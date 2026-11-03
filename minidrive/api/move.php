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

$input = json_decode(file_get_contents('php://input'), true);
$srcRel = trim($input['path'] ?? '', '/');
$destRel = trim($input['dest'] ?? '', '/');

if ($srcRel === '') {
    md_json(['error' => 'Cannot move the root folder'], 400);
}

$srcAbs = md_resolve_path($srcRel);
if ($srcAbs === false || !file_exists($srcAbs)) {
    md_json(['error' => 'Source item not found'], 404);
}

$destDirAbs = md_resolve_path($destRel);
if ($destDirAbs === false || !is_dir($destDirAbs)) {
    md_json(['error' => 'Invalid destination folder'], 400);
}

$name = basename($srcAbs);
$newAbs = rtrim($destDirAbs, '/') . '/' . $name;

// A folder cannot be moved into itself or into one of its subfolders
if (is_dir($srcAbs)) {
    $srcReal = realpath($srcAbs) . '/';
    $destReal = realpath($destDirAbs) . '/';
    if (strpos($destReal, $srcReal) === 0) {
        md_json(['error' => 'Cannot move a folder into itself'], 400);
    }
}

// Already there: nothing to do
if (realpath(dirname($srcAbs)) === realpath($destDirAbs)) {
    md_json(['ok' => true, 'unchanged' => true]);
}

if (file_exists($newAbs)) {
    md_json(['error' => 'An item with that name already exists at the destination'], 409);
}

if (!rename($srcAbs, $newAbs)) {
    md_json(['error' => 'Could not move the item'], 500);
}

md_json(['ok' => true]);
