<?php
/**
 * Which of these files are already in the drive (same place, same name, same size)?
 * Used before an upload, so uploading a folder again only sends what is missing
 * instead of making "(1)" copies of everything. Read-only, own drive only.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
md_require_member();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    md_json(['error' => 'Method not allowed'], 405);
}
md_check_csrf();

const MD_EXISTS_MAX_ITEMS = 20000;
const MD_EXISTS_MAX_BODY = 8 * 1024 * 1024;

$raw = file_get_contents('php://input', false, null, 0, MD_EXISTS_MAX_BODY + 1);
if (strlen((string)$raw) > MD_EXISTS_MAX_BODY) {
    md_json(['error' => 'Too many files to check at once'], 413);
}
$input = json_decode((string)$raw, true);
if (!is_array($input) || !is_array($input['items'] ?? null)) {
    md_json(['error' => 'Invalid request'], 400);
}
if (count($input['items']) > MD_EXISTS_MAX_ITEMS) {
    md_json(['error' => 'Too many files to check at once'], 413);
}

$rel = trim((string)($input['path'] ?? ''), '/');
$destDir = md_resolve_path($rel);
if ($destDir === false || !is_dir($destDir)) {
    md_json(['error' => 'Invalid destination folder'], 400);
}

$same = [];
foreach (array_values($input['items']) as $i => $it) {
    if (!is_array($it) || !is_string($it['p'] ?? null) || !is_numeric($it['s'] ?? null)) continue;
    // Same cleaning as the upload itself, so the names match what upload.php stores
    $clean = md_sanitize_relpath($it['p']);
    if ($clean === '' || strlen($clean) > 4096) continue;
    $full = md_resolve_path(($rel !== '' ? $rel . '/' : '') . $clean);
    if ($full === false || is_link($full) || !is_file($full)) continue;
    if (filesize($full) === (int)$it['s']) $same[] = $i;
}

md_json(['same' => $same]);
