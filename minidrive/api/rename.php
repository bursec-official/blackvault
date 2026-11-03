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
$newName = md_sanitize_name($input['new_name'] ?? '');

if ($rel === '' || $newName === '' || !md_name_allowed($newName)) {
    md_json(['error' => 'Invalid or disallowed name'], 400);
}

$abs = md_resolve_path($rel);
if ($abs === false || !file_exists($abs)) {
    md_json(['error' => 'Not found'], 404);
}

$newAbs = dirname($abs) . '/' . $newName;
if (file_exists($newAbs)) {
    md_json(['error' => 'An item with that name already exists'], 409);
}

if (!rename($abs, $newAbs)) {
    md_json(['error' => 'Could not rename'], 500);
}

md_json(['ok' => true]);
