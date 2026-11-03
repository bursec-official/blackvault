<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
md_require_member();

$rel = trim($_GET['path'] ?? '', '/');
$abs = md_resolve_path($rel);

if ($abs === false || !is_dir($abs)) {
    md_json(['error' => 'Folder not found'], 404);
}

$items = md_list_dir($abs, $rel);
$disk = md_disk_usage();

$trash = 0;
try { $tdir = md_trash_dir(null, false); if (is_dir($tdir)) $trash = count(array_filter(scandir($tdir) ?: [], fn($n) => (bool)preg_match(MD_TRASH_ID_RE, $n))); } catch (Throwable $e) {}

md_json([
    'path' => $rel,
    'items' => $items,
    'disk' => $disk,
    'trash' => $trash,
    'storage' => $GLOBALS['MD_STORAGE_STATE'] ?? ['state' => 'ok'],
]);
