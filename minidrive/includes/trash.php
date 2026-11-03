<?php
/**
 * Black Vault Drive — the Trash.
 *
 * Deleting moves an item to  <storage>/trash/<user id>/<item id>/
 *     item        the file or folder itself (renamed, so any name is safe)
 *     meta.json   original name, original folder, size, when it was deleted
 * Each member has their own trash; nobody else can see it. Items stay
 * MD_TRASH_DAYS days and are then removed for good. While in the trash they
 * still count in the member's quota.
 */

const MD_TRASH_DAYS = 30;
const MD_TRASH_ID_RE = '/^[0-9]{14}-[a-f0-9]{8}$/';

/** Trash folder of an account (the signed-in member by default). */
function md_trash_dir(?string $id = null, bool $create = true): string {
    $id = $id ?? (md_me()['id'] ?? '');
    if (!preg_match(MD_ID_RE, $id)) throw new InvalidArgumentException('Bad id');
    $dir = md_storage_root() . '/trash/' . $id;
    if ($create && !is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

function md_trash_item_dir(string $tid): ?string {
    if (!preg_match(MD_TRASH_ID_RE, $tid)) return null;
    $dir = md_trash_dir() . '/' . $tid;
    return is_dir($dir) && !is_link($dir) ? $dir : null;
}

/** Moves a resolved path of the signed-in member's drive to their trash. */
function md_trash_put(string $abs, string $rel): string {
    $tid = date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $dir = md_trash_dir() . '/' . $tid;
    if (!@mkdir($dir, 0750)) throw new RuntimeException('Could not use the trash.');
    $isDir = is_dir($abs) && !is_link($abs);
    $meta = [
        'name' => basename($rel),
        'from' => (string)(dirname($rel) === '.' ? '' : dirname($rel)),
        'type' => $isDir ? 'folder' : 'file',
        'size' => md_tree_size($abs),
        'deleted' => time(),
    ];
    // Same disk as the drive, so this is instant even for huge folders
    if (!@rename($abs, $dir . '/item')) {
        @rmdir($dir);
        throw new RuntimeException('Could not move it to the trash.');
    }
    $bytes = file_put_contents($dir . '/meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    // The little note about the item lives in the trash too: count it like the rest
    if ($bytes) md_usage_add((string)md_me()['id'], (int)$bytes);
    return $tid;
}

function md_trash_meta(string $dir): ?array {
    $m = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
    if (!is_array($m) || !isset($m['name'], $m['deleted'])) return null;
    return $m;
}

/** Removes items older than MD_TRASH_DAYS. Returns bytes freed. */
function md_trash_purge(?string $id = null): int {
    try { $root = md_trash_dir($id, false); } catch (Throwable $e) { return 0; }
    if (!is_dir($root)) return 0;
    $limit = time() - MD_TRASH_DAYS * 86400;
    $freed = 0;
    foreach (scandir($root) ?: [] as $tid) {
        if (!preg_match(MD_TRASH_ID_RE, $tid)) continue;
        $dir = $root . '/' . $tid;
        $m = md_trash_meta($dir);
        if ($m && (int)$m['deleted'] >= $limit) continue;
        $freed += md_tree_size($dir);
        md_rrmdir($dir);
    }
    return $freed;
}

/** Items in the signed-in member's trash, newest first. */
function md_trash_list(): array {
    $root = md_trash_dir();
    $out = [];
    foreach (scandir($root) ?: [] as $tid) {
        if (!preg_match(MD_TRASH_ID_RE, $tid)) continue;
        $m = md_trash_meta($root . '/' . $tid);
        if (!$m) continue;
        $out[] = [
            'id' => $tid,
            'name' => (string)$m['name'],
            'from' => (string)($m['from'] ?? ''),
            'type' => $m['type'] === 'folder' ? 'folder' : 'file',
            'size' => (int)($m['size'] ?? 0),
            'deleted' => (int)$m['deleted'],
            'expires' => (int)$m['deleted'] + MD_TRASH_DAYS * 86400,
        ];
    }
    usort($out, fn($a, $b) => $b['deleted'] <=> $a['deleted']);
    return $out;
}

/**
 * Puts an item back where it was. If that folder is gone it is created again;
 * if the name is taken, "name (restored)" is used. Returns the new path.
 */
function md_trash_restore(string $tid): string {
    $dir = md_trash_item_dir($tid);
    $m = $dir ? md_trash_meta($dir) : null;
    if (!$dir || !$m || !file_exists($dir . '/item') && !is_link($dir . '/item')) throw new RuntimeException('That item is no longer in the trash.');

    // Rebuild the original folder inside the drive, one checked step at a time
    $folder = '';
    foreach (array_filter(explode('/', str_replace('\\', '/', (string)$m['from'])), 'strlen') as $seg) {
        if ($seg === '.' || $seg === '..' || !md_name_allowed($seg)) { $folder = ''; break; }
        $next = $folder === '' ? $seg : $folder . '/' . $seg;
        $abs = md_resolve_path($next);
        if ($abs === false) { $folder = ''; break; }
        if (!is_dir($abs)) {
            if (file_exists($abs) || !@mkdir($abs, 0750)) { break; }
        }
        $folder = $next;
    }

    $name = basename(str_replace('\\', '/', (string)$m['name']));
    if ($name === '' || $name === '.' || $name === '..' || !md_name_allowed($name)) $name = 'Restored item';
    $dot = ($m['type'] === 'file') ? strrpos($name, '.') : false;
    $stem = $dot > 0 ? substr($name, 0, $dot) : $name;
    $ext = $dot > 0 ? substr($name, $dot) : '';
    for ($i = 0; $i < 100; $i++) {
        $try = $i === 0 ? $name : $stem . ' (restored' . ($i > 1 ? ' ' . $i : '') . ')' . $ext;
        $rel = $folder === '' ? $try : $folder . '/' . $try;
        $abs = md_resolve_path($rel);
        if ($abs === false) throw new RuntimeException('Could not restore it.');
        if (!file_exists($abs) && !is_link($abs)) break;
    }
    if (file_exists($abs) || is_link($abs)) throw new RuntimeException('Could not find a free name to restore it.');
    if (!@rename($dir . '/item', $abs)) throw new RuntimeException('Could not restore it.');
    md_rrmdir($dir);
    return $rel;
}

/** Deletes an item for good. Returns bytes freed. */
function md_trash_delete(string $tid): int {
    $dir = md_trash_item_dir($tid);
    if (!$dir) return 0;
    $size = md_tree_size($dir);
    md_rrmdir($dir);
    return $size;
}
