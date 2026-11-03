<?php
/**
 * Delete = move to the member's Trash (restorable for MD_TRASH_DAYS days).
 * The space is only freed when the trash is emptied, so the quota is unchanged.
 */
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
$rel = trim((string)($input['path'] ?? ''), '/');

if ($rel === '') {
    md_json(['error' => 'Cannot delete the root folder'], 400);
}

$abs = md_resolve_path($rel);
if ($abs === false || (!file_exists($abs) && !is_link($abs))) {
    md_json(['error' => 'Not found'], 404);
}

try {
    $tid = md_trash_put($abs, $rel);
} catch (Throwable $e) {
    md_json(['error' => $e->getMessage()], 500);
}

md_json(['ok' => true, 'trash_id' => $tid, 'disk' => md_disk_usage()]);
