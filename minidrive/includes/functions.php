<?php
/**
 * Drive — helper functions
 */

/**
 * Resolves a relative path sent by the browser against the user's drive and
 * makes sure the result stays INSIDE it (no path traversal).
 * Returns the real absolute path, or false if it is not valid.
 */
function md_resolve_path(string $relative) {
    try {
        $root = md_root();            // the signed-in user's private drive
    } catch (Throwable $e) {
        return false;
    }

    // A NUL byte in a path is always an attack
    if (strpos($relative, "\0") !== false) {
        return false;
    }

    // Normalise separators
    $relative = str_replace('\\', '/', $relative);
    $relative = ltrim($relative, '/');

    // Every segment is a plain name: never "." or ".." (realpath below checks again)
    foreach (explode('/', $relative) as $seg) {
        if ($seg === '.' || $seg === '..') return false;
    }

    $target = $root . '/' . $relative;

    $existing = realpath($target);
    if ($existing !== false) {
        return md_is_inside_root($existing, $root) ? $existing : false;
    }

    // If the path does not exist yet (e.g. right before creating it), check
    // its parent folder instead, so mkdir/upload work.
    $parent = realpath(dirname($target));
    if ($parent === false || !md_is_inside_root($parent, $root)) {
        return false;
    }

    return $target;
}

/**
 * True if $path is the root or inside it. Compares with a trailing "/" so that,
 * for example, "/var/www/minidrive/storage2" does NOT count as inside "storage".
 */
function md_is_inside_root(string $path, string $root): bool {
    return $path === $root || strpos($path, rtrim($root, '/') . '/') === 0;
}

/**
 * Safe file/folder name: no paths, no control characters.
 */
/**
 * Any kind of file is accepted (.php, .sh, .htaccess, .exe…): the drives are
 * pure data and nothing in them can ever run or configure the server:
 *   - Apache refuses to serve the storage folder at all (Require all denied),
 *     ignores .htaccess files in it (AllowOverride None) and has PHP switched
 *     off there; external disks live outside the website and are mounted noexec.
 *   - Files only leave through download.php (as attachments) and preview.php
 *     (a whitelist of image/video/audio/PDF types; text always as text/plain).
 * The installer attacks its own install to check all of this every time.
 */
function md_is_protected_name(string $name): bool {
    return false;
}

function md_name_allowed(string $name): bool {
    $n = trim($name);
    if ($n === '' || $n === '.' || $n === '..') return false;
    if (strpbrk($n, "/\\\0") !== false) return false;
    return strlen($n) <= 255;
}

function md_sanitize_name(string $name): string {
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    $name = trim($name);
    return $name;
}

/**
 * Cleans a relative path with subfolders (used when uploading whole folders):
 * each part is cleaned on its own, and ".." or empty parts are dropped.
 */
function md_sanitize_relpath(string $relpath): string {
    $relpath = str_replace('\\', '/', $relpath);
    $parts = explode('/', $relpath);
    $clean = [];
    foreach ($parts as $part) {
        $part = md_sanitize_name($part);
        if ($part === '' || $part === '.' || $part === '..') continue;
        $clean[] = $part;
    }
    return implode('/', $clean);
}

function md_human_size($bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = (float)$bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, $size < 10 && $i > 0 ? 1 : 0) . ' ' . $units[$i];
}

/**
 * Lists the contents of a folder inside the drive.
 */
function md_list_dir(string $absPath, string $relPath): array {
    $items = [];
    $entries = scandir($absPath);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $absPath . '/' . $entry;
        if (is_link($full)) continue; // symbolic links are never followed
        $isDir = is_dir($full);
        $items[] = [
            'name' => $entry,
            'type' => $isDir ? 'folder' : 'file',
            'size' => $isDir ? null : filesize($full),
            'size_human' => $isDir ? null : md_human_size(filesize($full)),
            'modified' => filemtime($full),
            'path' => trim($relPath . '/' . $entry, '/'),
        ];
    }
    // Folders first, then alphabetical
    usort($items, function ($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'folder' ? -1 : 1;
        }
        return strnatcasecmp($a['name'], $b['name']);
    });
    return $items;
}

/** The Drive's own files inside storage/, never listed or downloaded. */
function md_is_internal_file(string $name): bool {
    return in_array($name, ['.htaccess', '.gitkeep'], true);
}

/** Space of the signed-in user's drive (quota-aware). */
function md_disk_usage(): array {
    return md_my_space();
}

function md_json($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

/**
 * Deletes a folder and everything in it. Symbolic links are removed as links
 * (never followed).
 */
function md_rrmdir(string $dir): bool {
    $ok = true;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            $ok = md_rrmdir($path) && $ok;
        } else {
            $ok = @unlink($path) && $ok;
        }
    }
    return @rmdir($dir) && $ok;
}

/* ===================== Downloads ===================== */

/**
 * Releases the session lock. PHP locks the session file for the whole request:
 * without this, during a long download (or while watching a video) the rest
 * of the site would hang, waiting.
 */
function md_release_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/**
 * Prepares the output for big files: no time limit, no PHP buffers and no
 * compression (which would load everything into memory).
 */
function md_prepare_streaming(): void {
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

/**
 * A correct Content-Disposition header for names with accents, spaces, etc.
 * (RFC 6266): an ASCII fallback name + the real name in UTF-8.
 */
function md_content_disposition(string $type, string $filename): string {
    $fallback = $filename;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        if ($t !== false && $t !== '') $fallback = $t;
    }
    $fallback = preg_replace('/[^\x20-\x7E]/', '_', $fallback);
    $fallback = str_replace(['"', '\\'], '_', $fallback);
    return $type . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}

/**
 * Sends a file to the browser in chunks (never all in memory), with "Range"
 * support: interrupted downloads can resume and videos can be skipped through.
 */
function md_stream_file(string $abs, string $mime, string $disposition): void {
    $size = filesize($abs);
    $start = 0;
    $end = $size - 1;
    $partial = false;

    if (isset($_SERVER['HTTP_RANGE']) && $size > 0
        && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)
        && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {                       // "bytes=-500": the last 500 bytes
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $partial = true;
    }

    $fp = fopen($abs, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit('Could not open the file');
    }

    md_prepare_streaming();

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . md_content_disposition($disposition, basename($abs)));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-transform');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($abs)) . ' GMT');
    header('Content-Length: ' . ($end - $start + 1));
    if ($partial) {
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    } else {
        http_response_code(200);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        fclose($fp);
        exit;
    }

    fseek($fp, $start);
    $remaining = $end - $start + 1;
    $chunk = 1024 * 1024; // 1 MB
    while ($remaining > 0 && !feof($fp)) {
        $buf = fread($fp, (int)min($chunk, $remaining));
        if ($buf === false || $buf === '') break;
        echo $buf;
        $remaining -= strlen($buf);
        flush();
        if (connection_aborted()) break;
    }
    fclose($fp);
    exit;
}

/** A php.ini size ("8M", "20G"…) in bytes. 0 = unlimited. */
function md_ini_bytes(string $key): int {
    $v = trim((string)ini_get($key));
    if ($v === '' || $v === '0' || $v === '-1') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

/** What one upload request may carry, as the server really allows it. */
function md_upload_limits(): array {
    $file = MD_MAX_UPLOAD_MB * 1048576;
    $u = md_ini_bytes('upload_max_filesize');
    $p = md_ini_bytes('post_max_size');
    if ($u > 0) $file = min($file, $u);
    if ($p > 0) $file = min($file, $p - 65536);   // room for the form fields
    return ['file' => max(0, $file), 'post' => $p, 'files' => max(1, (int)ini_get('max_file_uploads') ?: 20)];
}
