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
$rel = trim($input['path'] ?? '', '/');
$name = md_sanitize_name($input['name'] ?? '');

if ($name === '' || !md_name_allowed($name)) {
    md_json(['error' => 'Invalid or disallowed folder name'], 400);
}

$parentAbs = md_resolve_path($rel);
if ($parentAbs === false || !is_dir($parentAbs)) {
    md_json(['error' => 'Invalid destination folder'], 400);
}

$newPath = $parentAbs . '/' . $name;
if (file_exists($newPath)) {
    md_json(['error' => 'An item with that name already exists'], 409);
}

if (!mkdir($newPath, 0750)) {
    md_json(['error' => 'Could not create the folder'], 500);
}

md_json(['ok' => true, 'name' => $name]);
