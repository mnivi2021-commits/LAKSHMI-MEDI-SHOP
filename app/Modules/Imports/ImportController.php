<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Core\Auth;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Response;
use App\Core\Session;
use App\Core\Spreadsheet\XlsxWriter;
use RuntimeException;

/** Excel / CSV upload screens: start, upload, map columns, preview, import, error report. */
final class ImportController
{
    public const ANY_PERMISSION = 'can_any:pending_orders.import,samples.import,dc.import,collections.import,sales.import,outstanding.import,customers.import,leads.import';
    private const PER_PAGE = 100;

    public static function index(): void
    {
        $user = Auth::user();
        $available = ImportService::available($user);
        $params = array_keys($available);
        $in = implode(',', array_fill(0, count($params), '?'));
        $own = Gate::isSuper() ? '' : ' AND ib.created_by = ?';
        if (!Gate::isSuper()) {
            $params[] = $user['id'];
        }
        $batches = $available === [] ? [] : Database::fetchAll(
            "SELECT ib.*, u.name AS user_name FROM import_batches ib LEFT JOIN users u ON u.id = ib.created_by
             WHERE ib.module IN ({$in}){$own} ORDER BY ib.id DESC LIMIT 50",
            $params
        );
        Response::view('imports/index', [
            'title'     => 'Excel Upload',
            'flash'     => Session::takeFlash(),
            'available' => $available,
            'batches'   => $batches,
        ]);
    }

    public static function create(array $p): void
    {
        $imp = self::importerFor($p['type'] ?? '');
        if ($imp !== null) {
            Response::view('imports/upload', ['title' => 'Upload ' . $imp->label(), 'flash' => Session::takeFlash(), 'imp' => $imp]);
        }
    }

    public static function template(array $p): void
    {
        $imp = self::importerFor($p['type'] ?? '');
        if ($imp === null) {
            return;
        }
        $fields = $imp->fields();
        $header = array_map(static fn ($f) => $f['label'], array_values($fields));
        $examples = [];
        $n = max(array_map(static fn ($f) => count($f['example'] ?? []), $fields) ?: [0]);
        for ($i = 0; $i < $n; $i++) {
            $examples[] = array_map(static fn ($f) => $f['example'][$i] ?? '', array_values($fields));
        }
        if (($_GET['format'] ?? '') === 'csv') {
            Csv::download($imp->key() . '_template.csv', $header, $examples);
            return;
        }
        $help = [['Column', 'Required', 'Format / notes']];
        foreach ($fields as $f) {
            $help[] = [$f['label'], ($f['required'] ?? false) ? 'Yes' : '', $f['format'] ?? ''];
        }
        $help[] = ['', '', ''];
        foreach ($imp->notes() as $note) {
            $help[] = ['Note', '', $note];
        }
        $help[] = ['Note', '', 'Delete the example rows before uploading. Keep the first row (headings). Dates as DD-MM-YYYY.'];
        $bytes = (new XlsxWriter())->addSheet($imp->label(), array_merge([$header], $examples))->addSheet('Instructions', $help)->toString();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $imp->key() . '_template.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    public static function upload(array $p): void
    {
        $imp = self::importerFor($p['type'] ?? '');
        if ($imp === null) {
            return;
        }
        $file = $_FILES['file'] ?? null;
        $back = '/imports/new/' . $imp->key();
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Session::flash('error', 'Choose a file to upload.');
            Response::redirect($back);
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The file is too large (max 5 MB).' : 'The upload did not complete. Please try again.');
            Response::redirect($back);
            return;
        }
        try {
            $res = ImportService::createBatch($imp, $file['tmp_name'], (string) $file['name'], true, (int) Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect($back);
            return;
        }
        if ($res['warning']) {
            Session::flash('warning', $res['warning']);
        }
        Response::redirect("/imports/{$res['id']}/map");
    }

    public static function map(array $p): void
    {
        [$batch, $imp] = self::batch($p) ?? [null, null];
        if ($batch === null) {
            return;
        }
        if (!in_array($batch['status'], ['uploaded', 'mapped', 'validated'], true)) {
            Response::redirect("/imports/{$batch['id']}");
            return;
        }
        $headers = ImportService::headers($batch);
        $first = Database::fetchAll('SELECT raw_data FROM import_rows WHERE batch_id = ? AND row_no > 1 ORDER BY row_no LIMIT 3', [$batch['id']]);
        Response::view('imports/map', [
            'title'   => 'Match columns · ' . $imp->label(),
            'flash'   => Session::takeFlash(),
            'batch'   => $batch,
            'imp'     => $imp,
            'headers' => $headers,
            'mapping' => Session::pull('_mapping') ?? (json_decode((string) $batch['column_mapping'], true) ?: []),
            'samples' => array_map(static fn ($r) => ImportService::assoc($headers, $r['raw_data']), $first),
        ]);
    }

    public static function saveMap(array $p): void
    {
        [$batch, $imp] = self::batch($p) ?? [null, null];
        if ($batch === null) {
            return;
        }
        if (!in_array($batch['status'], ['uploaded', 'mapped', 'validated'], true)) {
            Response::redirect("/imports/{$batch['id']}");
            return;
        }
        $input = is_array($_POST['map'] ?? null) ? array_map('strval', $_POST['map']) : [];
        $errors = ImportService::saveMapping($batch, $imp, $input);
        if ($errors) {
            $_SESSION['_mapping'] = $input;
            Session::flash('error', implode(' ', $errors));
            Response::redirect("/imports/{$batch['id']}/map");
            return;
        }
        $batch = Database::fetch('SELECT * FROM import_batches WHERE id = ?', [$batch['id']]);
        $c = ImportService::validate($batch, $imp);
        Session::flash($c['valid'] > 0 ? 'success' : 'error', "Checked {$batch['total_rows']} rows: {$c['valid']} ready, {$c['invalid']} with errors, {$c['duplicate']} duplicates.");
        Response::redirect("/imports/{$batch['id']}");
    }

    public static function show(array $p): void
    {
        [$batch, $imp] = self::batch($p) ?? [null, null];
        if ($batch === null) {
            return;
        }
        $filter = in_array($_GET['status'] ?? '', ['valid', 'invalid', 'duplicate', 'imported'], true) ? $_GET['status'] : '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $where = 'batch_id = ? AND row_no > 1' . ($filter !== '' ? ' AND status = ?' : '');
        $params = $filter !== '' ? [$batch['id'], $filter] : [$batch['id']];
        $total = (int) Database::value("SELECT COUNT(*) FROM import_rows WHERE {$where}", $params);
        $rows = Database::fetchAll("SELECT row_no, status, mapped_data, raw_data, errors, record_table, record_id FROM import_rows WHERE {$where}
                                    ORDER BY FIELD(status, 'invalid', 'duplicate', 'valid', 'imported', 'pending', 'skipped'), row_no
                                    LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        $records = $imp->groupField() !== null && $batch['status'] === 'validated'
            ? (int) Database::value("SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(mapped_data, '$._doc'))) FROM import_rows WHERE batch_id = ? AND status = 'valid'",
                [$batch['id']])
            : (int) $batch['valid_rows'];
        Response::view('imports/show', [
            'title'   => 'Import #' . $batch['id'] . ' · ' . $imp->label(),
            'flash'   => Session::takeFlash(),
            'batch'   => $batch,
            'imp'     => $imp,
            'rows'    => $rows,
            'filter'  => $filter,
            'page'    => $page,
            'pages'   => max(1, (int) ceil($total / self::PER_PAGE)),
            'records' => $records,
            'mapping' => json_decode((string) $batch['column_mapping'], true) ?: [],
        ]);
    }

    public static function run(array $p): void
    {
        [$batch, $imp] = self::batch($p) ?? [null, null];
        if ($batch === null) {
            return;
        }
        try {
            $r = ImportService::run((int) $batch['id'], $imp);
            Session::flash('success', "Imported {$r['records']} " . ($imp->groupField() !== null ? 'document(s)' : 'record(s)') . " from {$r['imported_rows']} row(s).");
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect("/imports/{$batch['id']}");
    }

    public static function cancel(array $p): void
    {
        [$batch] = self::batch($p) ?? [null];
        if ($batch === null) {
            return;
        }
        ImportService::cancel($batch);
        Session::flash('success', 'Import cancelled. Nothing was saved.');
        Response::redirect('/imports');
    }

    public static function errors(array $p): void
    {
        [$batch, $imp] = self::batch($p) ?? [null, null];
        if ($batch === null) {
            return;
        }
        [$header, $rows] = ImportService::problemRows($batch);
        Csv::download($imp->key() . '_import_' . $batch['id'] . '_problems.csv', $header, $rows);
    }

    // -------------------------------------------------------------------------

    private static function importerFor(string $type): ?Importer
    {
        $imp = ImportService::importer($type, Auth::user());
        if ($imp === null) {
            Response::error(404, 'Unknown import type.');
            return null;
        }
        return Gate::authorize($imp->permission()) ? $imp : null;
    }

    /** @return array{0: array<string, mixed>, 1: Importer}|null */
    private static function batch(array $p): ?array
    {
        $id = ctype_digit((string) ($p['id'] ?? '')) ? (int) $p['id'] : 0;
        $batch = $id > 0 ? Database::fetch('SELECT * FROM import_batches WHERE id = ?', [$id]) : null;
        if ($batch === null || (!Gate::isSuper() && (int) $batch['created_by'] !== Auth::id())) {
            Response::error(404, 'Import not found.');
            return null;
        }
        $imp = ImportService::importer($batch['module'], Auth::user());
        if ($imp === null || !Gate::authorize($imp->permission())) {
            return null;
        }
        return [$batch, $imp];
    }
}
