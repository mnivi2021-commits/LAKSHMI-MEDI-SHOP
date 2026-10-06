<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Core\Audit;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Logger;
use App\Core\Spreadsheet\SheetReader;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Upload -> map columns -> validate (preview) -> import, for every importer.
 *
 *  - The uploaded file is stored under uploads/imports with a random name (the
 *    user's file name is only displayed) and its SHA-256 is kept to warn about
 *    re-uploads.
 *  - Validation never writes business data. Rows end up valid / invalid /
 *    duplicate (already in the CRM, or repeated in the file) with reasons.
 *  - Import re-validates everything inside ONE transaction and writes only valid
 *    rows; if anything fails, nothing is written.
 */
final class ImportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;

    /** key => class, in menu order */
    public const IMPORTERS = [
        'pending_orders' => Importers\PendingOrderImporter::class,
        'samples'        => Importers\SampleImporter::class,
        'dc'             => Importers\DcImporter::class,
        'collections'    => Importers\CollectionImporter::class,
        'sales'          => Importers\SalesImporter::class,
        'outstanding'    => Importers\OutstandingImporter::class,
        'customers'      => Importers\CustomerImporter::class,
        'leads'          => Importers\LeadImporter::class,
    ];

    public static function importer(string $key, array $user, ?DateTimeImmutable $today = null): ?Importer
    {
        $class = self::IMPORTERS[$key] ?? null;
        return $class === null ? null : new $class($user, DataScope::for($user), ($today ?? new DateTimeImmutable('today'))->setTime(0, 0));
    }

    /** Importers this user may use. @return array<string, Importer> */
    public static function available(array $user): array
    {
        $out = [];
        foreach (array_keys(self::IMPORTERS) as $key) {
            $imp = self::importer($key, $user);
            if (Gate::allows($imp->permission(), $user)) {
                $out[$key] = $imp;
            }
        }
        return $out;
    }

    public static function storageDir(): string
    {
        return dirname(__DIR__, 3) . '/uploads/imports';
    }

    // =========================================================================
    // 1. Upload
    // =========================================================================

    /**
     * @return array{id: int, warning: ?string}
     * @throws RuntimeException with a user-facing message
     */
    public static function createBatch(Importer $imp, string $tmpPath, string $originalName, bool $isUpload, int $userId): array
    {
        $size = @filesize($tmpPath);
        if ($size === false || $size === 0) {
            throw new RuntimeException('The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('The file is larger than 5 MB. Split it into smaller files.');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
            throw new RuntimeException('Upload an Excel (.xlsx) or CSV file.');
        }

        $rows = SheetReader::read($tmpPath, self::MAX_ROWS);
        $header = $rows[0] ?? [];
        if (array_filter($header, static fn ($h) => $h !== '') === []) {
            throw new RuntimeException('The first row must contain the column headings.');
        }
        $headers = self::cleanHeaders($header);
        $data = [];
        foreach (array_slice($rows, 1, null, true) as $i => $r) {
            if (array_filter($r, static fn ($v) => $v !== '') === []) {
                continue;                                           // blank line
            }
            $values = [];
            foreach (array_keys($headers) as $c) {
                $values[] = $r[$c] ?? '';
            }
            $data[$i + 1] = $values;                                // spreadsheet row number (header = 1)
        }
        if ($data === []) {
            throw new RuntimeException('The file has headings but no data rows.');
        }

        $sha = hash_file('sha256', $tmpPath);
        $stored = bin2hex(random_bytes(16)) . '.' . ($ext === 'xlsx' ? 'xlsx' : 'csv');
        $dest = self::storageDir() . '/' . $stored;
        $ok = $isUpload ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
        if (!$ok) {
            throw new RuntimeException('The file could not be saved on the server.');
        }

        $previous = Database::fetch(
            "SELECT id, created_at FROM import_batches WHERE module = ? AND file_sha256 = ? AND status = 'completed' ORDER BY id DESC LIMIT 1",
            [$imp->key(), $sha]
        );
        $mapping = self::autoMap($imp, $headers);

        $id = Database::transaction(static function () use ($imp, $originalName, $stored, $sha, $size, $mapping, $data, $userId, $headers): int {
            Database::query(
                "INSERT INTO import_batches (module, original_filename, stored_filename, file_sha256, file_size, column_mapping, status, total_rows, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'uploaded', ?, ?)",
                [$imp->key(), mb_substr(basename($originalName), 0, 255), $stored, $sha, $size, json_encode($mapping), count($data), $userId]
            );
            $id = (int) Database::connection()->lastInsertId();
            // Row 1 keeps the headings in file order (JSON objects do not keep key order in MySQL).
            Database::query("INSERT INTO import_rows (batch_id, row_no, raw_data, status) VALUES (?, 1, ?, 'skipped')",
                [$id, json_encode(array_values($headers), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
            foreach (array_chunk($data, 500, true) as $chunk) {
                $sql = 'INSERT INTO import_rows (batch_id, row_no, raw_data) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?)'));
                $params = [];
                foreach ($chunk as $rowNo => $values) {
                    array_push($params, $id, $rowNo, json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                }
                Database::query($sql, $params);
            }
            return $id;
        });

        Audit::log('import.uploaded', $imp->key(), $id, null, ['file' => basename($originalName), 'rows' => count($data), 'sha256' => $sha]);
        $warning = $previous !== null
            ? 'This exact file was already imported on ' . date('d-m-Y H:i', strtotime($previous['created_at'])) . ' (batch #' . $previous['id'] . '). Rows already in the CRM will be skipped as duplicates.'
            : null;
        return ['id' => $id, 'warning' => $warning];
    }

    /** @param list<string> $header @return list<string> unique, non-empty headings */
    private static function cleanHeaders(array $header): array
    {
        $out = [];
        foreach ($header as $i => $h) {
            $h = trim(preg_replace('/\s+/', ' ', $h));
            $h = $h === '' ? 'Column ' . self::colName($i) : mb_substr($h, 0, 80);
            $base = $h;
            for ($n = 2; in_array($h, $out, true); $n++) {
                $h = "{$base} ({$n})";
            }
            $out[$i] = $h;
        }
        return $out;
    }

    /** @param list<string> $headers @return array<string, string> field => heading */
    public static function autoMap(Importer $imp, array $headers): array
    {
        $norm = static fn (string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower($s));
        $byNorm = [];
        foreach ($headers as $h) {
            $byNorm[$norm($h)] ??= $h;
        }
        $map = [];
        $used = [];
        foreach ($imp->fields() as $field => $f) {
            foreach (array_merge([$f['label'], $field], $f['aliases'] ?? []) as $candidate) {
                $h = $byNorm[$norm($candidate)] ?? null;
                if ($h !== null && !isset($used[$h])) {
                    $map[$field] = $h;
                    $used[$h] = true;
                    break;
                }
            }
        }
        return $map;
    }

    // =========================================================================
    // 2. Mapping
    // =========================================================================

    /** @param array<string, string> $input field => heading ('' = not mapped) @return list<string> errors */
    public static function saveMapping(array $batch, Importer $imp, array $input): array
    {
        $headers = self::headers($batch);
        $map = [];
        $errors = [];
        foreach ($imp->fields() as $field => $f) {
            $h = (string) ($input[$field] ?? '');
            if ($h === '') {
                if ($f['required'] ?? false) {
                    $errors[] = "Choose the column for \"{$f['label']}\" (required).";
                }
                continue;
            }
            if (!in_array($h, $headers, true)) {
                $errors[] = "Column \"{$h}\" is not in the file.";
                continue;
            }
            if (in_array($h, $map, true)) {
                $errors[] = "Column \"{$h}\" is used for two fields.";
                continue;
            }
            $map[$field] = $h;
        }
        if ($errors) {
            return $errors;
        }
        Database::query("UPDATE import_batches SET column_mapping = ?, status = 'mapped' WHERE id = ?", [json_encode($map), $batch['id']]);
        return [];
    }

    /** @return list<string> headings in file order */
    public static function headers(array $batch): array
    {
        $raw = Database::value('SELECT raw_data FROM import_rows WHERE batch_id = ? AND row_no = 1', [$batch['id']]);
        return $raw ? json_decode($raw, true) : [];
    }

    /** One stored data row as heading => value. @param list<string> $headers */
    public static function assoc(array $headers, string $rawJson): array
    {
        $values = json_decode($rawJson, true) ?: [];
        $out = [];
        foreach ($headers as $i => $h) {
            $out[$h] = (string) ($values[$i] ?? '');
        }
        return $out;
    }

    // =========================================================================
    // 3. Validate (preview)
    // =========================================================================

    /** @return array{valid: int, invalid: int, duplicate: int, records: int} */
    public static function validate(array $batch, Importer $imp): array
    {
        $map = json_decode((string) $batch['column_mapping'], true) ?: [];
        $headers = self::headers($batch);
        $rows = Database::fetchAll('SELECT id, row_no, raw_data FROM import_rows WHERE batch_id = ? AND row_no > 1 ORDER BY row_no', [$batch['id']]);

        $results = [];           // row id => [status, errors, data|null, mapped]
        foreach ($rows as $r) {
            $raw = self::assoc($headers, $r['raw_data']);
            $in = [];
            foreach ($imp->fields() as $field => $_) {
                $in[$field] = isset($map[$field]) ? trim((string) ($raw[$map[$field]] ?? '')) : '';
            }
            [$data, $errors] = $imp->validateRow($in);
            $results[$r['id']] = ['row_no' => (int) $r['row_no'], 'status' => $data === null ? 'invalid' : 'valid', 'errors' => $errors, 'data' => $data, 'in' => $in];
        }

        $group = $imp->groupField();
        $records = 0;
        if ($group !== null) {
            $records = self::checkDocuments($imp, $group, $results);
        } else {
            $seen = [];
            foreach ($results as &$res) {
                if ($res['status'] !== 'valid') {
                    continue;
                }
                $key = $imp->recordKey($res['data']);
                if ($key !== null && isset($seen[$key])) {
                    $res['status'] = 'duplicate';
                    $res['errors'] = ["Same record as row {$seen[$key]} in this file."];
                    continue;
                }
                if ($key !== null) {
                    $seen[$key] = $res['row_no'];
                }
                if (($msg = $imp->existing($res['data'])) !== null) {
                    $res['status'] = 'duplicate';
                    $res['errors'] = [$msg];
                    continue;
                }
                $records++;
            }
            unset($res);
        }

        foreach ($results as &$res) {
            if ($group !== null && $res['status'] === 'valid') {
                $res['in']['_doc'] = $imp->documentKey($res['data']);    // lets the preview count documents exactly
            }
        }
        unset($res);

        $counts = ['valid' => 0, 'invalid' => 0, 'duplicate' => 0];
        Database::transaction(static function () use ($results, $batch, &$counts): void {
            $upd = Database::connection()->prepare('UPDATE import_rows SET mapped_data = ?, status = ?, errors = ? WHERE id = ?');
            foreach ($results as $id => $res) {
                $counts[$res['status']]++;
                $upd->execute([json_encode($res['in'], JSON_UNESCAPED_UNICODE), $res['status'], $res['errors'] ? json_encode($res['errors'], JSON_UNESCAPED_UNICODE) : null, $id]);
            }
            Database::query(
                "UPDATE import_batches SET status = 'validated', valid_rows = ?, invalid_rows = ?, duplicate_rows = ?, error_message = NULL WHERE id = ?",
                [$counts['valid'], $counts['invalid'], $counts['duplicate'], $batch['id']]
            );
        });
        return $counts + ['records' => $records];
    }

    /**
     * Group document lines, spread line errors to the whole document, check header
     * consistency and existing documents. Returns the number of documents to create.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private static function checkDocuments(Importer $imp, string $group, array &$results): int
    {
        // A) If any line of a document number is invalid, the whole document is held back.
        $byNumber = [];
        foreach ($results as $id => $res) {
            $byNumber[strtoupper($res['in'][$group])][] = $id;
        }
        foreach ($byNumber as $number => $ids) {
            $bad = array_values(array_filter($ids, static fn ($id) => $results[$id]['status'] === 'invalid'));
            if ($bad === [] || $number === '') {
                continue;
            }
            $badRows = implode(', ', array_map(static fn ($id) => $results[$id]['row_no'], $bad));
            foreach ($ids as $id) {
                if ($results[$id]['status'] === 'valid') {
                    $results[$id]['status'] = 'invalid';
                    $results[$id]['errors'] = ["Not imported because another line of {$number} has errors (row {$badRows})."];
                }
            }
        }

        // B) Valid lines -> documents.
        $docs = [];
        foreach ($results as $id => $res) {
            if ($res['status'] === 'valid') {
                $docs[$imp->documentKey($res['data'])][] = $id;
            }
        }
        $labels = self::fieldLabels($imp);
        $count = 0;
        foreach ($docs as $ids) {
            $lines = array_map(static fn ($id) => $results[$id]['data'], $ids);
            $errors = [];
            foreach ($imp->headerFields() as $f) {
                if (count(array_unique(array_map(static fn ($l) => (string) ($l[$f] ?? ''), $lines))) > 1) {
                    $errors[] = 'All lines of this document must have the same ' . ($labels[$f] ?? $f) . '.';
                }
            }
            $errors = array_merge($errors, $imp->validateDocument($lines));
            $status = 'invalid';
            if ($errors === []) {
                $msg = $imp->existing($lines[0]);
                if ($msg !== null) {
                    $status = 'duplicate';
                    $errors = [$msg];
                }
            }
            if ($errors === []) {
                $count++;
                continue;
            }
            foreach ($ids as $id) {
                $results[$id]['status'] = $status;
                $results[$id]['errors'] = $errors;
            }
        }
        return $count;
    }

    /** @return array<string, string> data key => human label */
    private static function fieldLabels(Importer $imp): array
    {
        $out = [];
        foreach ($imp->fields() as $field => $f) {
            $out[$field] = $f['label'];
        }
        return $out + ['customer_id' => 'Customer Code', 'employee_id' => 'Sales Employee', 'sample_id' => 'Sample No',
            'reference_invoice_id' => 'Against Invoice', 'expected_date' => 'Expected Delivery'];
    }

    // =========================================================================
    // 4. Import
    // =========================================================================

    /**
     * @return array{imported_rows: int, records: int}
     * @throws RuntimeException
     */
    public static function run(int $batchId, Importer $imp): array
    {
        try {
            $result = Database::transaction(static function () use ($batchId, $imp): array {
                $batch = Database::fetch('SELECT * FROM import_batches WHERE id = ? FOR UPDATE', [$batchId]);
                if ($batch === null || $batch['status'] !== 'validated') {
                    throw new RuntimeException('This import is not ready (it may already have been imported or cancelled).');
                }
                Database::query("UPDATE import_batches SET status = 'importing' WHERE id = ?", [$batchId]);

                // Re-check against the CRM as it is NOW (something may have changed since the preview).
                self::validate($batch, $imp);
                $rows = Database::fetchAll("SELECT id, row_no, mapped_data FROM import_rows WHERE batch_id = ? AND status = 'valid' ORDER BY row_no", [$batchId]);
                if ($rows === []) {
                    throw new RuntimeException('There are no valid rows to import.');
                }

                $groups = [];
                foreach ($rows as $r) {
                    [$data] = $imp->validateRow(json_decode($r['mapped_data'], true));
                    if ($data === null) {
                        throw new RuntimeException('Data changed during the import; please validate again.');
                    }
                    $key = $imp->groupField() !== null ? $imp->documentKey($data) : 'row' . $r['id'];
                    $groups[$key]['lines'][] = $data;
                    $groups[$key]['ids'][] = (int) $r['id'];
                }

                $mark = Database::connection()->prepare("UPDATE import_rows SET status = 'imported', record_table = ?, record_id = ? WHERE id = ?");
                foreach ($groups as $g) {
                    [$table, $recordId] = $imp->store($g['lines'], $batchId);
                    foreach ($g['ids'] as $rowId) {
                        $mark->execute([$table, $recordId, $rowId]);
                    }
                }
                Database::query("UPDATE import_batches SET status = 'completed', imported_rows = ?, completed_at = NOW() WHERE id = ?", [count($rows), $batchId]);
                return ['imported_rows' => count($rows), 'records' => count($groups)];
            });
        } catch (RuntimeException $e) {
            self::markFailed($batchId, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            Logger::error('Import failed', ['batch' => $batchId, 'error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
            self::markFailed($batchId, 'The import failed and nothing was saved. Please try again or contact the administrator.');
            throw new RuntimeException('The import failed and nothing was saved. Please try again or contact the administrator.');
        }

        Audit::log('import.completed', $imp->key(), $batchId, null, $result);
        return $result;
    }

    private static function markFailed(int $batchId, string $message): void
    {
        // Only a batch that was ready can fail; leave completed/cancelled ones untouched. Keep it re-runnable.
        Database::query("UPDATE import_batches SET status = 'validated', error_message = ? WHERE id = ? AND status IN ('validated', 'importing')",
            [mb_substr($message, 0, 500), $batchId]);
    }

    public static function cancel(array $batch): void
    {
        Database::query("UPDATE import_batches SET status = 'cancelled' WHERE id = ? AND status IN ('uploaded', 'mapped', 'validated')", [$batch['id']]);
        Audit::log('import.cancelled', $batch['module'], (int) $batch['id']);
    }

    // =========================================================================
    // Error report
    // =========================================================================

    /** @return array{0: list<string>, 1: list<list<string>>} header + rows of every row that was not imported */
    public static function problemRows(array $batch): array
    {
        $headers = self::headers($batch);
        $rows = Database::fetchAll("SELECT row_no, status, errors, raw_data FROM import_rows WHERE batch_id = ? AND row_no > 1 AND status IN ('invalid', 'duplicate', 'skipped') ORDER BY row_no", [$batch['id']]);
        $out = [];
        foreach ($rows as $r) {
            $raw = self::assoc($headers, $r['raw_data']);
            $out[] = array_merge([(string) $r['row_no'], ['invalid' => 'Error', 'duplicate' => 'Duplicate'][$r['status']] ?? ucfirst($r['status']), implode(' | ', json_decode((string) $r['errors'], true) ?: [])],
                array_map(static fn ($h) => (string) ($raw[$h] ?? ''), $headers));
        }
        return [array_merge(['Row', 'Status', 'Problem'], $headers), $out];
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }
}
