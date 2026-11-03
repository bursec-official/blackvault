<?php
/**
 * The signed-in member's Trash.
 *   GET                              -> items (newest first), days kept, total size
 *   POST {action: restore, ids: []}  -> put items back where they were
 *   POST {action: delete,  ids: []}  -> delete items for good
 *   POST {action: empty}             -> delete everything in the trash for good
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
$me = md_require_member();

function md_trash_state(): array {
    $items = md_trash_list();
    return [
        'items' => $items,
        'days' => MD_TRASH_DAYS,
        'size' => array_sum(array_column($items, 'size')),
        'disk' => md_disk_usage(),
    ];
}

// Old items leave on their own (never while the drives are read-only)
if (($GLOBALS['MD_STORAGE_STATE']['state'] ?? 'ok') === 'ok') {
    $freed = md_trash_purge();
    if ($freed) md_usage_add($me['id'], -$freed);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    md_json(md_trash_state());
}

md_require_writable();
md_check_csrf();
$in = md_json_body();
$action = (string)($in['action'] ?? '');
$ids = array_values(array_filter((array)($in['ids'] ?? []), fn($i) => is_string($i) && preg_match(MD_TRASH_ID_RE, $i)));
if (count($ids) > 1000) md_json(['error' => 'Too many items at once.'], 400);

$done = 0;
$errors = [];
$restored = [];
switch ($action) {
case 'restore':
    foreach ($ids as $tid) {
        try { $restored[] = md_trash_restore($tid); $done++; }
        catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
    break;
case 'delete':
    $bytes = 0;
    foreach ($ids as $tid) { $bytes += md_trash_delete($tid); $done++; }
    md_usage_add($me['id'], -$bytes);
    break;
case 'empty':
    $bytes = 0;
    foreach (md_trash_list() as $it) { $bytes += md_trash_delete($it['id']); $done++; }
    md_usage_add($me['id'], -$bytes);
    break;
default:
    md_json(['error' => 'Unknown action.'], 400);
}

md_json(['ok' => true, 'done' => $done, 'restored' => $restored, 'errors' => array_slice(array_unique($errors), 0, 5)] + md_trash_state());
