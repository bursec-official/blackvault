<?php
/**
 * Administration API — administrators only.
 *   GET            -> accounts, quotas, storage pool and recent activity
 *   POST {action}  -> create | update | password | lock | unlock | signout | delete | recount
 *                     | settings (storage reserve) | security (wrong passwords before a lock)
 *                     | disks | disk_* (see includes/disks-admin.php)
 * The role (member / administrator) is fixed when an account is created:
 * members keep their drive, administrators never get one.
 * Every change is checked server-side and written to the audit log.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
$me = md_require_admin();

function md_admin_state(): array {
    // Refresh stale usage figures (cheap when nothing changed recently)
    foreach (md_store()['users'] as $id => $u) {
        if (time() - (int)($u['used_at'] ?? 0) > MD_USAGE_MAX_AGE) md_usage_recount($id, true);
    }
    $store = md_store();
    $limits = md_quota_limits($store);
    $users = [];
    foreach ($store['users'] as $id => $u) $users[] = md_public_user($u, $limits[$id] ?? null);
    usort($users, fn($a, $b) => [$b['role'] === 'admin', strtolower($a['username'])] <=> [$a['role'] === 'admin', strtolower($b['username'])]);
    return [
        'me' => md_me()['id'],
        'users' => $users,
        'pool' => md_pool_info($store),
        'activity' => md_audit_tail(60),
        'min_password' => MD_MIN_PASSWORD,
        'max_fails' => md_max_fails($store),
        'max_fails_range' => MD_MAX_FAILS_RANGE,
        'trash_days' => MD_TRASH_DAYS,
        'storage' => md_storage_state() + ['disk' => md_active_disk()],
    ];
}

function md_fail(string $msg, int $code = 400) { md_json(['error' => $msg], $code); }

/** Dangerous disk actions: the administrator types their password again. */
function md_confirm_password(array $me, string $pw): void {
    if (($wait = bv_login_locked_for('md')) > 0) md_fail('Too many wrong passwords. Try again in ' . bv_wait_text($wait) . '.', 429);
    if ($pw === '' || !password_verify($pw, $me['hash'])) {
        bv_login_failed('md');
        md_audit('disk_auth_failed', ['user' => $me['username'], 'detail' => 'Wrong password for a disk action']);
        md_fail('Your password is not correct.', 403);
    }
}

function md_disk_label(string $loc): string {
    if ($loc === 'internal') return 'the internal disk';
    $info = md_storage_conf()['disks'][$loc] ?? null;
    return ($info['model'] ?? 'Disk') . (isset($info['size']) ? ' (' . md_human_size((int)$info['size']) . ')' : '');
}

/** Disks as the Admin page shows them. Works (empty) when the service is not installed. */
function md_disks_state(): array {
    try {
        $l = md_disk_call(['action' => 'list'], 120);
    } catch (Throwable $e) {
        return ['available' => false, 'message' => $e->getMessage(), 'disks' => [], 'missing' => [], 'move' => md_move_status(), 'storage' => md_storage_state() + ['disk' => md_active_disk()]];
    }
    $used = 0;
    foreach (md_store()['users'] as $u) $used += (int)($u['used'] ?? 0);
    return [
        'available' => true,
        'disks' => $l['disks'] ?? [],
        'missing' => $l['missing'] ?? [],
        'move' => $l['move'] ?? null,
        'internal' => $l['storage']['internal'] ?? null,
        'smart_tool' => (bool)($l['smart_tool'] ?? false),
        'drives_size' => $used,
        'storage' => md_storage_state() + ['disk' => md_active_disk()],
    ];
}

/** Does this quota fit in the pool next to everyone else's? */
function md_quota_check(array $store, string $id, array $quota): ?string {
    $store['users'][$id]['quota'] = $quota;
    $pool = md_pool_info($store)['pool'];
    $sum = 0;
    foreach ($store['users'] as $u) {
        if (($u['role'] ?? '') === 'admin') continue;
        $q = md_clean_quota($u['quota'] ?? []);
        if ($q['mode'] === 'share') $sum += $pool * $q['value'] / 100;
        if ($q['mode'] === 'fixed') $sum += $q['value'];
    }
    if ($sum > $pool * 1.0001) {
        return 'These quotas add up to ' . md_human_size((int)$sum) . ', more than the ' . md_human_size((int)$pool) . ' available for drives.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    md_json(md_admin_state());
}

md_check_csrf();
$in = md_json_body();
$action = (string)($in['action'] ?? '');
$store = md_store();

switch ($action) {

case 'create': {
    $name = trim((string)($in['username'] ?? ''));
    $pass = (string)($in['password'] ?? '');
    $role = ($in['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $quota = md_clean_quota($in['quota'] ?? []);
    if (!md_valid_username($name)) md_fail('Usernames use 2–32 letters, numbers, dots, dashes or underscores.');
    if (md_find_user_by_name($name)) md_fail('That username is already taken.');
    if ($p = md_password_problem($pass)) md_fail($p);
    $id = bin2hex(random_bytes(8));
    if ($role === 'user' && ($err = md_quota_check($store, $id, $quota))) md_fail($err);
    $hash = md_hash_password($pass);
    md_store_update(function (array &$d) use ($id, $name, $hash, $role, $quota) {
        $d['users'][$id] = md_new_user_record($id, $name, $hash, $role, $quota);
        $d['users'][$id]['used_at'] = time();
    });
    if ($role === 'user') {
        $home = md_user_home($id);
        if (!is_dir($home)) @mkdir($home, 0750, true);
    }
    md_audit('user_created', ['user' => $name, 'detail' => ($role === 'admin' ? 'Administrator (no drive)' : 'Member with a private drive')]);
    md_json(['ok' => true] + md_admin_state());
}

case 'update': {
    $id = (string)($in['id'] ?? '');
    $u = $store['users'][$id] ?? null;
    if (!$u) md_fail('Account not found.', 404);
    $changes = [];
    $patch = [];

    if (array_key_exists('username', $in)) {
        $name = trim((string)$in['username']);
        if ($name !== $u['username']) {
            if (!md_valid_username($name)) md_fail('Usernames use 2–32 letters, numbers, dots, dashes or underscores.');
            $other = md_find_user_by_name($name);
            if ($other && $other['id'] !== $id) md_fail('That username is already taken.');
            $patch['username'] = $name;
            $changes[] = 'renamed to ' . $name;
        }
    }
    if (array_key_exists('role', $in) && ($in['role'] === 'admin' ? 'admin' : 'user') !== $u['role']) {
        // Kept apart on purpose: a member never gains admin rights over its own
        // files, and an administrator never ends up with a drive.
        md_fail('The role cannot be changed. Create a separate account instead.');
    }
    if (array_key_exists('quota', $in) && $u['role'] === 'user') {
        $quota = md_clean_quota($in['quota']);
        if ($quota != md_clean_quota($u['quota'] ?? [])) {
            if ($err = md_quota_check($store, $id, $quota)) md_fail($err);
            $patch['quota'] = $quota;
            $changes[] = 'quota changed';
        }
    }
    if (!$patch) md_json(['ok' => true] + md_admin_state());

    md_store_update(function (array &$d) use ($id, $patch) {
        if (!isset($d['users'][$id])) return;
        $d['users'][$id] = $patch + $d['users'][$id];
    });
    md_audit('user_updated', ['user' => $patch['username'] ?? $u['username'], 'detail' => ucfirst(implode(', ', $changes))]);
    md_json(['ok' => true] + md_admin_state());
}

case 'password': {
    $id = (string)($in['id'] ?? '');
    $u = $store['users'][$id] ?? null;
    if (!$u) md_fail('Account not found.', 404);
    $pass = (string)($in['password'] ?? '');
    if ($p = md_password_problem($pass)) md_fail($p);
    $hash = md_hash_password($pass);
    md_store_update(function (array &$d) use ($id, $hash) {
        $d['users'][$id]['hash'] = $hash;
        $d['users'][$id]['sv'] = (int)$d['users'][$id]['sv'] + 1;     // signs out the old sessions
        $d['users'][$id]['locked'] = false;                          // a new password also unlocks
        $d['users'][$id]['locked_at'] = null;
        $d['users'][$id]['locked_by'] = null;
        $d['users'][$id]['disabled'] = false;
        $d['users'][$id]['fails'] = 0;
    });
    if ($id === $me['id']) $_SESSION['md_sv'] = (int)$u['sv'] + 1;   // but keep the admin's own session
    md_audit('password_reset', ['user' => $u['username'], 'detail' => md_is_locked($u) ? 'Account unlocked too' : null]);
    md_json(['ok' => true] + md_admin_state());
}

case 'lock': {
    $id = (string)($in['id'] ?? '');
    $u = $store['users'][$id] ?? null;
    if (!$u) md_fail('Account not found.', 404);
    if ($id === $me['id']) md_fail('You cannot lock your own account.');
    if (md_is_locked($u)) md_fail('This account is already locked.');
    if ($u['role'] === 'admin' && md_admin_count($store, $id) === 0) md_fail('There must always be at least one active administrator.');
    md_lock($id);
    md_audit('account_locked', ['user' => $u['username'], 'detail' => 'Locked by an administrator']);
    md_json(['ok' => true] + md_admin_state());
}

case 'unlock': {
    $id = (string)($in['id'] ?? '');
    $u = $store['users'][$id] ?? null;
    if (!$u) md_fail('Account not found.', 404);
    if (!md_is_locked($u)) md_fail('This account is not locked.');
    md_unlock($id);
    md_audit('account_unlocked', ['user' => $u['username']]);
    md_json(['ok' => true] + md_admin_state());
}

case 'security': {
    $n = (int)($in['max_fails'] ?? 0);
    [$lo, $hi] = MD_MAX_FAILS_RANGE;
    if ($n < $lo || $n > $hi) md_fail('Choose between ' . $lo . ' and ' . $hi . ' attempts.');
    $old = md_max_fails($store);
    if ($n !== $old) {
        md_store_update(function (array &$d) use ($n) { $d['settings']['max_fails'] = $n; });
        md_audit('settings', ['detail' => 'Accounts lock after ' . $n . ' wrong passwords (was ' . $old . ')']);
    }
    md_json(['ok' => true] + md_admin_state());
}

case 'signout': {
    $id = (string)($in['id'] ?? '');
    $u = $store['users'][$id] ?? null;
    if (!$u) md_fail('Account not found.', 404);
    md_store_update(function (array &$d) use ($id) { $d['users'][$id]['sv'] = (int)$d['users'][$id]['sv'] + 1; });
    if ($id === $me['id']) $_SESSION['md_sv'] = (int)$u['sv'] + 1;
    md_audit('signed_out', ['user' => $u['username'], 'detail' => 'All sessions closed']);
    md_json(['ok' => true] + md_admin_state());
}

case 'delete': {
    $id = (string)($in['id'] ?? '');
    $u = $store['users'][$id] ?? null;
    if (!$u) md_fail('Account not found.', 404);
    if ($id === $me['id']) md_fail('You cannot delete your own account.');
    if ($u['role'] === 'admin' && md_admin_count($store, $id) === 0) md_fail('There must always be at least one active administrator.');
    if (strtolower(trim((string)($in['confirm'] ?? ''))) !== strtolower($u['username'])) md_fail('Type the username to confirm.');
    $freed = 0;
    if ($u['role'] === 'user') {
        // Its files must really go too: refuse while the disk with the drives is away or busy
        $st = md_storage_state();
        if ($st['state'] !== 'ok') md_fail($st['message']);
    }
    md_store_update(function (array &$d) use ($id) { unset($d['users'][$id]); });
    if ($u['role'] === 'user') {
        foreach ([md_user_home($id), md_trash_dir($id, false)] as $dir) {
            if (is_dir($dir) && !is_link($dir)) { $freed += md_tree_size($dir); md_rrmdir($dir); }
        }
    }
    md_audit('user_deleted', ['user' => $u['username'], 'detail' => md_human_size($freed) . ' of files deleted']);
    md_json(['ok' => true] + md_admin_state());
}

case 'recount': {
    if (md_storage_state()['state'] === 'offline') md_fail('The storage disk is not connected.');
    foreach (array_keys($store['users']) as $id) md_usage_recount($id, true);
    md_json(['ok' => true] + md_admin_state());
}

case 'settings': {
    $reserve = (float)($in['reserve_pct'] ?? 10);
    if ($reserve < 0 || $reserve > 50) md_fail('Reserve between 0 and 50%.');
    $reserve = round($reserve, 1);
    $test = $store;
    $test['settings']['reserve_pct'] = $reserve;
    $first = array_key_first($test['users']);
    if ($first && ($err = md_quota_check($test, $first, md_clean_quota($test['users'][$first]['quota'] ?? [])))) md_fail($err);
    md_store_update(function (array &$d) use ($reserve) { $d['settings']['reserve_pct'] = $reserve; });
    md_audit('settings', ['detail' => 'System reserve set to ' . $reserve . '%']);
    md_json(['ok' => true] + md_admin_state());
}

/* ---------------------------------------------------------------- disks
 * Everything below only ASKS the root disk service (tools/blackvault-disk),
 * which re-checks every request. Erasing, moving the drives and deleting an
 * old copy also need the administrator's own password again.
 */
case 'disks': {
    md_json(['ok' => true] + md_disks_state());
}

case 'disk_health': {
    try { md_disk_call(['action' => 'smart', 'refresh' => true], 240); } catch (Throwable $e) { md_fail($e->getMessage()); }
    md_json(['ok' => true] + md_disks_state());
}

case 'disk_progress': {
    md_json(['ok' => true, 'move' => md_move_status(), 'storage' => md_storage_state() + ['disk' => md_active_disk()]]);
}

case 'disk_connect':
case 'disk_eject': {
    $uuid = (string)($in['uuid'] ?? '');
    if (!preg_match(MD_UUID_RE, $uuid)) md_fail('Invalid disk.');
    $what = $action === 'disk_connect' ? 'connect' : 'eject';
    try { md_disk_call(['action' => $what, 'uuid' => $uuid], 180); } catch (Throwable $e) { md_fail($e->getMessage()); }
    md_audit($what === 'connect' ? 'disk_connected' : 'disk_ejected', ['detail' => md_disk_label($uuid)]);
    md_json(['ok' => true] + md_disks_state());
}

case 'disk_format': {
    md_confirm_password($me, (string)($in['password'] ?? ''));
    $path = (string)($in['path'] ?? '');
    $id = (string)($in['id'] ?? '');
    if (!preg_match('#^/dev/[a-z0-9]+$#', $path) || !preg_match('/^[a-f0-9]{16}$/', $id)) md_fail('Invalid disk.');
    try { $r = md_disk_call(['action' => 'format', 'path' => $path, 'id' => $id, 'confirm' => 'ERASE'], 1800); }
    catch (Throwable $e) { md_fail($e->getMessage()); }
    md_audit('disk_formatted', ['detail' => ($r['model'] ?? 'Disk') . ' · ' . md_human_size((int)($r['size'] ?? 0)) . ' erased and prepared']);
    md_json(['ok' => true, 'uuid' => $r['uuid'] ?? null] + md_disks_state());
}

case 'disk_move': {
    md_confirm_password($me, (string)($in['password'] ?? ''));
    $to = (string)($in['to'] ?? '');
    if ($to !== 'internal' && !preg_match(MD_UUID_RE, $to)) md_fail('Invalid destination.');
    try { $r = md_disk_call(['action' => 'move', 'to' => $to], 120); } catch (Throwable $e) { md_fail($e->getMessage()); }
    md_audit('drives_moving', ['detail' => md_human_size((int)($r['total'] ?? 0)) . ' to ' . md_disk_label($to)]);
    md_json(['ok' => true] + md_disks_state());
}

case 'disk_remove_old': {
    md_confirm_password($me, (string)($in['password'] ?? ''));
    $loc = (string)($in['location'] ?? '');
    if ($loc !== 'internal' && !preg_match(MD_UUID_RE, $loc)) md_fail('Invalid location.');
    try { $r = md_disk_call(['action' => 'remove_old', 'location' => $loc], 3600); } catch (Throwable $e) { md_fail($e->getMessage()); }
    md_audit('old_copy_removed', ['detail' => md_human_size((int)($r['freed'] ?? 0)) . ' freed on ' . md_disk_label($loc)]);
    md_json(['ok' => true] + md_disks_state());
}

default:
    md_fail('Unknown action.');
}
