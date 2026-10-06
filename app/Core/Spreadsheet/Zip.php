<?php

declare(strict_types=1);

namespace App\Core\Spreadsheet;

use RuntimeException;

/**
 * Minimal ZIP reader / writer for .xlsx files, built on zlib only
 * (this server has no ext-zip). Supports stored (0) and deflated (8) entries;
 * ZIP64, encryption and multi-disk archives are refused.
 *
 * Reading is bounded so a hostile file cannot exhaust memory (zip bombs):
 * every entry and the total inflated size are capped.
 */
final class Zip
{
    public const MAX_ENTRY_BYTES = 50 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 120 * 1024 * 1024;

    /** @var array<string, array{method: int, flags: int, csize: int, usize: int, offset: int}> */
    private array $entries = [];
    private int $inflated = 0;

    private function __construct(private readonly string $data) {}

    public static function open(string $bytes): self
    {
        $zip = new self($bytes);
        $zip->readCentralDirectory();
        return $zip;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function read(string $name): string
    {
        $e = $this->entries[$name] ?? throw new RuntimeException("Missing part {$name}");
        if ($e['flags'] & 0x1) {
            throw new RuntimeException('Encrypted files are not supported.');
        }
        if ($e['usize'] > self::MAX_ENTRY_BYTES || $this->inflated + $e['usize'] > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('The spreadsheet is too large to read.');
        }
        $local = substr($this->data, $e['offset'], 30);
        if (strlen($local) < 30 || substr($local, 0, 4) !== "PK\x03\x04") {
            throw new RuntimeException('Corrupt file (bad local header).');
        }
        $h = unpack('vnameLen/vextraLen', substr($local, 26, 4));
        $start = $e['offset'] + 30 + $h['nameLen'] + $h['extraLen'];
        $raw = substr($this->data, $start, $e['csize']);
        if (strlen($raw) !== $e['csize']) {
            throw new RuntimeException('Corrupt file (truncated entry).');
        }
        $out = match ($e['method']) {
            0 => $raw,
            8 => @gzinflate($raw, max(1, $e['usize'])),
            default => throw new RuntimeException('Unsupported compression in spreadsheet.'),
        };
        if ($out === false || strlen($out) !== $e['usize']) {
            throw new RuntimeException('Corrupt file (could not decompress).');
        }
        $this->inflated += strlen($out);
        return $out;
    }

    /**
     * Build a ZIP archive (deflated) from name => contents.
     *
     * @param array<string, string> $files
     */
    public static function build(array $files): string
    {
        $out = '';
        $central = '';
        [$time, $date] = self::dosTime(time());
        foreach ($files as $name => $content) {
            $deflated = gzdeflate($content, 6);
            $crc = crc32($content);
            $offset = strlen($out);
            $head = pack('vvvvvVVVvv', 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($content), strlen($name), 0);
            $out .= "PK\x03\x04" . $head . $name . $deflated;
            $central .= "PK\x01\x02" . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
        }
        $end = "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
        return $out . $central . $end;
    }

    // -------------------------------------------------------------------------

    private function readCentralDirectory(): void
    {
        $len = strlen($this->data);
        if ($len < 22 || substr($this->data, 0, 2) !== 'PK') {
            throw new RuntimeException('This is not a valid .xlsx file.');
        }
        $tail = substr($this->data, max(0, $len - 65557));
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false) {
            throw new RuntimeException('This is not a valid .xlsx file (no directory).');
        }
        $eocd = unpack('vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset', substr($tail, $pos + 4, 16));
        if ($eocd['disk'] !== 0 || $eocd['entries'] === 0xFFFF || $eocd['cdOffset'] === 0xFFFFFFFF) {
            throw new RuntimeException('Multi-part or ZIP64 spreadsheets are not supported.');
        }
        if ($eocd['entries'] > 5000) {
            throw new RuntimeException('The spreadsheet has too many parts.');
        }

        $p = $eocd['cdOffset'];
        for ($i = 0; $i < $eocd['entries']; $i++) {
            if (substr($this->data, $p, 4) !== "PK\x01\x02") {
                throw new RuntimeException('Corrupt file (bad directory entry).');
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset',
                substr($this->data, $p + 4, 42));
            $name = substr($this->data, $p + 46, $h['nameLen']);
            $this->entries[$name] = ['method' => $h['method'], 'flags' => $h['flags'], 'csize' => $h['csize'], 'usize' => $h['usize'], 'offset' => $h['offset']];
            $p += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        }
    }

    /** @return array{0: int, 1: int} */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
