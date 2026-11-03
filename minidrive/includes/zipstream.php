<?php
/**
 * Drive — streaming ZIP builder.
 *
 * Builds the .zip ON THE FLY while it downloads: no extra disk space, nothing
 * loaded into memory, and the download starts at once. Files are stored
 * without recompressing ("store" method), which is the fastest and normal
 * for photos, videos and documents, which are already compressed.
 *
 * - ZIP64 (files over 4 GB and huge ZIPs) only when needed, so the ZIP still
 *   opens with any program.
 * - The final size is known before starting, so the browser shows a real
 *   progress bar with the time left.
 * - UTF-8 names (accents etc.) and modification dates are kept.
 * - Never follows symbolic links (nothing can "escape" the drive).
 */
class MdZipStream
{
    private const MAX32 = 0xFFFFFFFF;
    private const MAX16 = 0xFFFF;
    private const FLAG_UTF8 = 0x0800;
    private const MADE_BY = (3 << 8) | 45; // Unix, specification 4.5

    /** @var array<int,array> */
    private array $entries = [];
    private int $fileCount = 0;
    private int $dataBytes = 0;

    /**
     * Adds a folder or file (absolute path). $zipName is its name inside the
     * ZIP; '' = put its contents straight at the root.
     */
    public function add(string $abs, string $zipName): void
    {
        if (is_link($abs)) return;
        if (is_dir($abs)) {
            if ($zipName !== '') {
                $this->entries[] = $this->makeEntry($abs, $zipName . '/', true, 0);
            }
            $children = @scandir($abs);
            if ($children === false) return;
            natcasesort($children);
            foreach ($children as $child) {
                if ($child === '.' || $child === '..') continue;
                $this->add($abs . '/' . $child, ($zipName === '' ? '' : $zipName . '/') . $child);
            }
        } elseif (is_file($abs) && is_readable($abs)) {
            $size = (int)filesize($abs);
            $this->entries[] = $this->makeEntry($abs, $zipName, false, $size);
            $this->fileCount++;
            $this->dataBytes += $size;
        }
    }

    public function fileCount(): int { return $this->fileCount; }
    public function dataBytes(): int { return $this->dataBytes; }
    public function entryCount(): int { return count($this->entries); }

    private function makeEntry(string $abs, string $name, bool $isDir, int $size): array
    {
        $mtime = @filemtime($abs) ?: time();
        return [
            'abs' => $abs,
            'name' => $name,
            'dir' => $isDir,
            'size' => $size,
            'mtime' => $mtime,
            'zip64' => $size >= self::MAX32,
            'offset' => 0,
            'crc' => 0,
        ];
    }

    /** Works out each entry's offset and the exact size of the final ZIP. */
    public function plan(): int
    {
        $offset = 0;
        foreach ($this->entries as &$e) {
            $e['offset'] = $offset;
            $offset += 30 + strlen($e['name']) + strlen($this->localExtra($e)) + $e['size'];
        }
        unset($e);
        $cdOffset = $offset;
        $cdSize = 0;
        foreach ($this->entries as $e) {
            $cdSize += 46 + strlen($e['name']) + strlen($this->centralExtra($e));
        }
        $total = $cdOffset + $cdSize;
        if ($this->needsZip64End($cdOffset, $cdSize)) {
            $total += 56 + 20;
        }
        return $total + 22;
    }

    /** Writes the whole ZIP to the output (php://output). */
    public function stream(): void
    {
        $this->plan();
        $offset = 0;

        foreach ($this->entries as &$e) {
            if (!$e['dir']) {
                $e['crc'] = $this->crc32File($e['abs'], $e['size']);
            }
            $extra = $this->localExtra($e);
            [$time, $date] = $this->dosTime($e['mtime']);
            $header = pack('VvvvvvVVVvv',
                0x04034b50,
                $e['zip64'] ? 45 : 20,
                self::FLAG_UTF8,
                0,                      // method: store
                $time, $date,
                $e['crc'],
                $e['zip64'] ? self::MAX32 : $e['size'],
                $e['zip64'] ? self::MAX32 : $e['size'],
                strlen($e['name']),
                strlen($extra)
            ) . $e['name'] . $extra;
            $this->out($header);
            $offset += strlen($header);

            if (!$e['dir'] && $e['size'] > 0) {
                $this->copyFile($e['abs'], $e['size']);
                $offset += $e['size'];
            }
            if (connection_aborted()) return;
        }
        unset($e);

        $cdOffset = $offset;
        $cdSize = 0;
        foreach ($this->entries as $e) {
            $extra = $this->centralExtra($e);
            [$time, $date] = $this->dosTime($e['mtime']);
            $big = $e['zip64'];
            $bigOffset = $e['offset'] >= self::MAX32;
            $attr = $e['dir'] ? ((0040755 << 16) | 0x10) : (0100644 << 16);
            $rec = pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,
                self::MADE_BY,
                ($big || $bigOffset) ? 45 : 20,
                self::FLAG_UTF8,
                0,
                $time, $date,
                $e['crc'],
                $big ? self::MAX32 : $e['size'],
                $big ? self::MAX32 : $e['size'],
                strlen($e['name']),
                strlen($extra),
                0, 0, 0,
                $attr,
                $bigOffset ? self::MAX32 : $e['offset']
            ) . $e['name'] . $extra;
            $this->out($rec);
            $cdSize += strlen($rec);
        }

        $count = count($this->entries);
        if ($this->needsZip64End($cdOffset, $cdSize)) {
            $zip64EndOffset = $cdOffset + $cdSize;
            $this->out(pack('VPvvVVPPPP',
                0x06064b50, 44, self::MADE_BY, 45, 0, 0,
                $count, $count, $cdSize, $cdOffset));
            $this->out(pack('VVPV', 0x07064b50, 0, $zip64EndOffset, 1));
        }
        $this->out(pack('VvvvvVVv',
            0x06054b50, 0, 0,
            min($count, self::MAX16), min($count, self::MAX16),
            min($cdSize, self::MAX32), min($cdOffset, self::MAX32),
            0));
        flush();
    }

    private function needsZip64End(int $cdOffset, int $cdSize): bool
    {
        return count($this->entries) >= self::MAX16 || $cdOffset >= self::MAX32 || $cdSize >= self::MAX32;
    }

    /** "UT" extra field (exact date in Unix format). */
    private function timeExtra(array $e): string
    {
        return pack('vvCV', 0x5455, 5, 1, $e['mtime'] & 0xFFFFFFFF);
    }

    private function localExtra(array $e): string
    {
        $x = $this->timeExtra($e);
        if ($e['zip64']) {
            $x .= pack('vvPP', 0x0001, 16, $e['size'], $e['size']);
        }
        return $x;
    }

    private function centralExtra(array $e): string
    {
        $x = $this->timeExtra($e);
        $z = '';
        if ($e['zip64']) $z .= pack('PP', $e['size'], $e['size']);
        if ($e['offset'] >= self::MAX32) $z .= pack('P', $e['offset']);
        if ($z !== '') $x .= pack('vv', 0x0001, strlen($z)) . $z;
        return $x;
    }

    private function dosTime(int $ts): array
    {
        $d = getdate(max($ts, 315532800)); // the DOS format has no dates before 1980
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        return [$time, $date];
    }

    /** CRC32 of the file, reading exactly $size bytes (what will be sent). */
    private function crc32File(string $abs, int $size): int
    {
        $ctx = hash_init('crc32b');
        $fp = @fopen($abs, 'rb');
        $left = $size;
        if ($fp !== false) {
            while ($left > 0 && !feof($fp)) {
                $buf = fread($fp, (int)min(4 * 1024 * 1024, $left));
                if ($buf === false || $buf === '') break;
                hash_update($ctx, $buf);
                $left -= strlen($buf);
            }
            fclose($fp);
        }
        // If the file shrank in the meantime, it is padded with zeros so the
        // ZIP still matches the size announced.
        while ($left > 0) {
            $n = (int)min(1024 * 1024, $left);
            hash_update($ctx, str_repeat("\0", $n));
            $left -= $n;
        }
        return (int)hexdec(hash_final($ctx));
    }

    private function copyFile(string $abs, int $size): void
    {
        $fp = @fopen($abs, 'rb');
        $left = $size;
        if ($fp !== false) {
            while ($left > 0 && !feof($fp)) {
                $buf = fread($fp, (int)min(1024 * 1024, $left));
                if ($buf === false || $buf === '') break;
                $this->out($buf);
                $left -= strlen($buf);
                if (connection_aborted()) { fclose($fp); return; }
            }
            fclose($fp);
        }
        while ($left > 0) {
            $n = (int)min(1024 * 1024, $left);
            $this->out(str_repeat("\0", $n));
            $left -= $n;
        }
    }

    private function out(string $bytes): void
    {
        echo $bytes;
        flush();
    }
}
