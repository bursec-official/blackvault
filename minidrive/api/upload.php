<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
md_require_member();
md_require_writable();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    md_json(['error' => 'Method not allowed'], 405);
}
md_check_csrf();

$rel = trim($_POST['path'] ?? '', '/');
$destDir = md_resolve_path($rel);

if ($destDir === false || !is_dir($destDir)) {
    md_json(['error' => 'Invalid destination folder'], 400);
}

// The client numbers every file (files[i] + relpaths[i] + count), so a file the
// browser silently left out of the request is reported back by its number.
$expected = isset($_POST['count']) ? max(0, min(1000, (int)$_POST['count'])) : null;

if (empty($_FILES['files']) && $expected === null) {
    // PHP drops the whole request when it is bigger than post_max_size
    $sent = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = md_ini_bytes('post_max_size');
    if ($max > 0 && $sent > $max) {
        error_log('MiniDrive: upload of ' . $sent . ' bytes is above post_max_size (' . ini_get('post_max_size') . ')');
        md_json(['error' => 'Too big for the server in one go (' . md_human_size($sent) . ', limit ' . md_human_size($max) . '). Run the installer again to raise it.', 'too_big' => true], 413);
    }
    // The browser sent the form fields but no file data (e.g. it could no longer read them)
    error_log('MiniDrive: upload with no file data (' . ($_SERVER['HTTP_USER_AGENT'] ?? '?') . ', ' . (int)($_SERVER['CONTENT_LENGTH'] ?? 0) . ' bytes)');
    md_json(['error' => 'Your browser sent the upload without the files. Reload the page (Cmd+Shift+R) and try again; if the files are in iCloud, download them to this Mac first.', 'empty' => true], 400);
}

$maxBytes = MD_MAX_UPLOAD_MB * 1024 * 1024;
$results = [];

// Quota of this user's drive (null = no limit, only the physical disk)
$meId = md_me()['id'];
$space = md_my_space();
$remaining = $space['limited'] ? (int)$space['free'] : (int)(@disk_free_space(md_root()) ?: 0);
$added = 0;

$F = $_FILES['files'] ?? ['name' => [], 'error' => [], 'tmp_name' => [], 'size' => []];
if (!is_array($F['name'])) $F = ['name' => [$F['name']], 'error' => [$F['error']], 'tmp_name' => [$F['tmp_name']], 'size' => [$F['size']]];
$indexes = $expected !== null ? ($expected > 0 ? range(0, $expected - 1) : []) : array_keys($F['name']);
// Path of each file inside the uploaded folder (if any), sent by the page
// with the same number as its file.
$relpaths = is_array($_POST['relpaths'] ?? null) ? $_POST['relpaths'] : [];
// Size of each file as the browser sees it: anything else arriving = not really sent
$sizes = is_array($_POST['sizes'] ?? null) ? $_POST['sizes'] : [];
$missing = 0;

foreach ($indexes as $i) {
    $relInput = isset($relpaths[$i]) ? (string)$relpaths[$i] : '';
    if (!isset($F['name'][$i])) {
        $missing++;
        $results[] = ['i' => $i, 'name' => $relInput, 'ok' => false, 'missing' => true, 'error' => 'The browser did not send this file'];
        continue;
    }
    $errCode = (int)$F['error'][$i];
    $origName = md_sanitize_name((string)$F['name'][$i]);
    $tmpPath = $F['tmp_name'][$i];
    $size = (int)$F['size'][$i];

    // Sent empty or cut short: the client retries it another way
    $declared = isset($sizes[$i]) && is_numeric($sizes[$i]) ? (int)$sizes[$i] : null;
    if ($errCode === UPLOAD_ERR_NO_FILE || $errCode === UPLOAD_ERR_PARTIAL
        || ($errCode === UPLOAD_ERR_OK && $declared !== null && $size !== $declared)) {
        $missing++;
        $results[] = ['i' => $i, 'name' => $relInput !== '' ? $relInput : $origName, 'ok' => false, 'missing' => true, 'error' => 'The browser sent this file incomplete'];
        continue;
    }
    if ($errCode !== UPLOAD_ERR_OK) {
        $why = [
            UPLOAD_ERR_INI_SIZE => 'Bigger than the server allows (' . ini_get('upload_max_filesize') . '). Run the installer again to raise it.',
            UPLOAD_ERR_FORM_SIZE => 'Too big',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'The file could not be read on your computer',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the file (disk full?)',
            UPLOAD_ERR_EXTENSION => 'Blocked by a PHP extension on the server',
        ][$errCode] ?? 'Upload error (code ' . $errCode . ')';
        $results[] = ['i' => $i, 'name' => $relInput !== '' ? $relInput : $origName, 'ok' => false, 'error' => $why];
        continue;
    }
    if ($origName === '') {
        $results[] = ['i' => $i, 'name' => $origName, 'ok' => false, 'error' => 'Invalid file name'];
        continue;
    }
    if (!md_name_allowed($origName)) {
        $results[] = ['i' => $i, 'name' => $origName, 'ok' => false, 'error' => 'Invalid file name'];
        continue;
    }
    if ($size > $maxBytes) {
        $results[] = ['i' => $i, 'name' => $origName, 'ok' => false, 'error' => 'Exceeds the limit of ' . md_human_size($maxBytes)];
        continue;
    }

    if ($size > $remaining - $added) {
        $results[] = ['i' => $i, 'name' => $origName, 'ok' => false, 'error' => 'Not enough space in your drive (' . md_human_size(max(0, $remaining - $added)) . ' left)'];
        continue;
    }

    // A file from an uploaded folder gets its subfolders created again.
    $targetDir = $destDir;
    $leafName = $origName;

    if ($relInput !== '') {
        $relClean = md_sanitize_relpath($relInput);
        foreach (explode('/', $relClean) as $seg) {
            if ($seg !== '' && !md_name_allowed($seg)) {
                $results[] = ['i' => $i, 'name' => $relInput, 'ok' => false, 'error' => 'Invalid folder name (' . $seg . ')'];
                continue 2;
            }
        }
        if ($relClean !== '') {
            $subDir = dirname($relClean);
            $leafName = basename($relClean);
            if ($subDir !== '.' && $subDir !== '') {
                $targetDir = rtrim($destDir, '/') . '/' . $subDir;
                // Extra defence: the final folder must stay inside the drive (no symbolic links on the way)
                $probe = $destDir;
                foreach (explode('/', $subDir) as $seg) {
                    $probe .= '/' . $seg;
                    if (is_link($probe)) { $targetDir = ''; break; }
                }
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0750, true);
                }
            }
        }
    }

    if ($targetDir === '' || !is_dir($targetDir)) {
        $results[] = ['i' => $i, 'name' => $leafName, 'ok' => false, 'error' => 'Could not create the destination folder'];
        continue;
    }

    // Never overwrite: add (1), (2)... if the name is taken
    $finalName = $leafName;
    $finalPath = $targetDir . '/' . $finalName;
    $ext = '';
    $base = $finalName;
    if (($dot = strrpos($finalName, '.')) !== false && $dot > 0) {
        $ext = substr($finalName, $dot);
        $base = substr($finalName, 0, $dot);
    }
    $n = 1;
    while (file_exists($finalPath)) {
        $finalName = $base . ' (' . $n . ')' . $ext;
        $finalPath = $targetDir . '/' . $finalName;
        $n++;
    }

    if (move_uploaded_file($tmpPath, $finalPath)) {
        chmod($finalPath, 0640);
        $added += (int)filesize($finalPath);
        $results[] = ['i' => $i, 'name' => ($relInput !== '' ? $relInput : $finalName), 'ok' => true, 'size' => filesize($finalPath)];
    } else {
        $results[] = ['i' => $i, 'name' => $leafName, 'ok' => false, 'error' => 'Could not save on the server'];
    }
}

md_usage_add($meId, $added);
if ($missing) {
    // Left in Apache's error log so a browser problem can be traced later
    error_log('MiniDrive: browser left out ' . $missing . ' of ' . count($indexes) . ' file(s) in an upload (' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '?'), 0, 160) . '); the page retries them');
}
md_json(['results' => $results, 'disk' => md_disk_usage()]);
