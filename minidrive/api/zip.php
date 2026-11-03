<?php
/**
 * Drive — download folders (or several items at once) as one .zip
 *
 * Two steps:
 *   1) POST (JSON + CSRF token) with the list of paths -> everything is checked
 *      and a temporary download link comes back + a summary (files, size).
 *   2) GET ?t=<token> -> the ZIP is built on the fly while it downloads.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/zipstream.php';
md_require_member();

const MD_ZIP_TOKEN_TTL = 600; // 10 minutes to start the download

/** Builds the ZIP from relative paths. Returns [MdZipStream, name.zip]. */
function md_zip_build(array $paths, string $base): array {
    $zip = new MdZipStream();
    foreach ($paths as $rel) {
        $abs = md_resolve_path($rel);
        if ($abs === false || !file_exists($abs)) continue;
        $root = md_root();
        // The whole root goes in without a wrapping folder; anything else keeps its name.
        $zip->add($abs, $abs === $root ? '' : basename($abs));
    }

    if (count($paths) === 1) {
        $only = trim($paths[0], '/');
        $name = $only === '' ? MD_SITE_NAME : basename($only);
    } else {
        $name = ($base === '' ? MD_SITE_NAME : basename($base)) . ' - selection';
    }
    return [$zip, $name . '.zip'];
}

// ---------- Paso 1: preparar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    md_check_csrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $paths = $input['paths'] ?? null;
    $base = trim((string)($input['base'] ?? ''), '/');

    if (!is_array($paths) || count($paths) === 0) {
        md_json(['error' => 'Nothing selected to download'], 400);
    }

    $clean = [];
    foreach ($paths as $p) {
        $rel = trim((string)$p, '/');
        $abs = md_resolve_path($rel);
        if ($abs === false || !file_exists($abs)) {
            md_json(['error' => 'Not found: “' . basename($rel) . '”. It may have been moved or deleted.'], 404);
        }
        $clean[] = $rel;
    }
    $clean = array_values(array_unique($clean));

    [$zip, $zipName] = md_zip_build($clean, $base);
    if ($zip->entryCount() === 0) {
        md_json(['error' => 'The folder is empty: nothing to download'], 400);
    }
    $total = $zip->plan();

    // Keep the request in the session under a random token
    $now = time();
    $_SESSION['md_zip'] = array_filter($_SESSION['md_zip'] ?? [], fn($t) => $t['exp'] > $now);
    $token = bin2hex(random_bytes(16));
    $_SESSION['md_zip'][$token] = ['paths' => $clean, 'base' => $base, 'exp' => $now + MD_ZIP_TOKEN_TTL];

    md_json([
        'ok' => true,
        'url' => 'api/zip.php?t=' . $token,
        'name' => $zipName,
        'files' => $zip->fileCount(),
        'size' => $total,
        'size_human' => md_human_size($total),
    ]);
}

// ---------- Paso 2: descargar ----------
$token = (string)($_GET['t'] ?? '');
$job = $_SESSION['md_zip'][$token] ?? null;
if (!$job || $job['exp'] < time()) {
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The download link has expired. Go back to MiniDrive and click Download again.');
}

// Important: release the session so the site keeps working during the download
md_release_session();

[$zip, $zipName] = md_zip_build($job['paths'], $job['base']);
$total = $zip->plan();

md_prepare_streaming();
header('Content-Type: application/zip');
header('Content-Disposition: ' . md_content_disposition('attachment', $zipName));
header('Content-Length: ' . $total);
header('Cache-Control: private, no-transform');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

$zip->stream();
exit;
