<?php
/**
 * Black Vault Drive — accounts, private drives and quotas.
 *
 * Everything lives in state/ (Apache refuses to serve it):
 *   state/users.json   accounts, password hashes, quotas, settings
 *   state/audit.log    one JSON line per security-relevant event
 *
 * Two kinds of account, kept completely apart:
 *   user  (Member)        owns storage/users/<id>/ and nothing else. Every file
 *                         path goes through md_resolve_path(), anchored to
 *                         md_root() — the signed-in member's folder.
 *   admin (Administrator) manages accounts and opens the Monitor. Has NO
 *                         drive: md_root() refuses to give it one.
 *
 * Members are locked after md_max_fails() wrong passwords in a row (3 by
 * default, adjustable 3–10 in Admin → Security) and only an administrator can
 * unlock them. Administrators are never locked by wrong passwords (nobody
 * could unlock them); they are protected by the per-IP brake instead.
 * An administrator can also lock any other account by hand.
 *
 * Where the files live: md_storage_root(). Normally storage/ inside the app;
 * after "Move drives here" in Admin → Storage, an external disk mounted by
 * the root-only disk service at /srv/blackvault/disks/<uuid>. If that disk
 * is unplugged, the drives go OFFLINE: nothing is ever written to the
 * internal disk by mistake.
 */

const MD_ID_RE = '/^[a-f0-9]{16}$/';
const MD_USERNAME_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{1,31}$/';
const MD_MIN_PASSWORD = 10;
const MD_MAX_FAILS = 3;                 // default wrong passwords in a row before a member is locked
const MD_MAX_FAILS_RANGE = [3, 10];     // what the administrator may choose
const MD_STORE_VERSION = 3;             // 2 = administrators have no drive, 3 = "disabled" became "locked"
const MD_USAGE_MAX_AGE = 3600;          // recount a drive's size at most every hour
const MD_AUDIT_MAX_BYTES = 1048576;     // audit log is trimmed above 1 MB
const MD_GHOST_TTL = 30 * 86400;        // memory of failed names that are not accounts
const MD_LOCKED_MSG = 'This account is locked. Ask your administrator to unlock it.';
const MD_DISKS_DIR = '/srv/blackvault/disks';
const MD_UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

/** Thrown when the drives live on an external disk that is not connected. */
class MdStorageOffline extends RuntimeException {}

/** The signed-in account (set by md_require_login). */
$GLOBALS['MD_ME'] = null;

function md_state_dir(): string {
    $d = dirname(__DIR__) . '/state';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}
function md_users_file(): string { return md_state_dir() . '/users.json'; }
function md_users_dir(): string { return md_storage_root() . '/users'; }

/* ---------------------------------------------------------------------
 * Where the drives live
 * ------------------------------------------------------------------- */

/** Written ONLY by the root disk service (www-data cannot change it). */
function md_storage_conf(): array {
    static $conf = null;
    if ($conf !== null) return $conf;
    $file = defined('MD_STORAGE_CONF') ? MD_STORAGE_CONF : '/etc/blackvault/storage.json';
    $d = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    return $conf = is_array($d) ? $d : [];
}

function md_disks_dir(): string { return defined('MD_DISKS_DIR_OVERRIDE') ? MD_DISKS_DIR_OVERRIDE : MD_DISKS_DIR; }

/** UUID of the external disk holding the drives, or null for the internal disk. */
function md_active_disk(): ?string {
    $a = (string)(md_storage_conf()['active'] ?? 'internal');
    return preg_match(MD_UUID_RE, $a) ? $a : null;
}

/** Root folder of all drives. Throws MdStorageOffline if the disk is missing. */
function md_storage_root(): string {
    $uuid = md_active_disk();
    if ($uuid === null) return rtrim(MD_STORAGE_ROOT, '/');
    $base = md_disks_dir() . '/' . $uuid;
    // The marker file only exists ON the disk: an empty mount point never passes
    if (!is_file($base . '/.blackvault-disk') || !is_dir($base . '/storage')) {
        throw new MdStorageOffline('The storage disk is not connected.');
    }
    return $base . '/storage';
}

/**
 * ok | moving | offline, with details for the interface.
 * "moving" = drives are being copied to another disk: read-only meanwhile.
 */
function md_storage_state(): array {
    $move = md_move_status();
    // A move whose worker stopped beating (power cut…) no longer blocks anything
    if ($move && in_array($move['state'] ?? '', ['running', 'verifying'], true) && time() - (int)($move['beat'] ?? 0) <= 60) {
        return ['state' => 'moving', 'progress' => (float)($move['progress'] ?? 0), 'message' => 'Drives are being moved to another disk. Files are read-only until it finishes.'];
    }
    try { md_storage_root(); }
    catch (MdStorageOffline $e) {
        return ['state' => 'offline', 'message' => 'The storage disk is not connected. Plug it back in; your files are safe on it.'];
    }
    return ['state' => 'ok'];
}

function md_move_status(): ?array {
    $file = defined('MD_MOVE_STATUS') ? MD_MOVE_STATUS : '/var/lib/blackvault/move.json';
    if (!is_file($file)) return null;
    $d = json_decode((string)@file_get_contents($file), true);
    return is_array($d) ? $d : null;
}

/* ---------------------------------------------------------------------
 * Talking to the root disk service (unix socket, group www-data only)
 * ------------------------------------------------------------------- */

function md_disk_socket(): string { return defined('MD_DISK_SOCKET') ? MD_DISK_SOCKET : '/run/blackvault-disk.sock'; }

/** Sends one request to the disk service. Throws RuntimeException on failure. */
function md_disk_call(array $req, int $timeout = 30): array {
    $sock = md_disk_socket();
    if (!file_exists($sock)) throw new RuntimeException('The disk service is not installed. Run the installer again.');
    $fp = @stream_socket_client('unix://' . $sock, $errno, $err, 5);
    if (!$fp) throw new RuntimeException('The disk service did not answer (' . $err . ').');
    stream_set_timeout($fp, $timeout);
    fwrite($fp, json_encode($req) . "\n");
    @stream_socket_shutdown($fp, STREAM_SHUT_WR);
    $raw = stream_get_contents($fp, 4 * 1048576);
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    if (!empty($meta['timed_out'])) throw new RuntimeException('The disk service took too long to answer.');
    $res = json_decode((string)$raw, true);
    if (!is_array($res)) throw new RuntimeException('The disk service gave an invalid answer.');
    if (empty($res['ok'])) throw new RuntimeException((string)($res['error'] ?? 'The disk service refused the request.'));
    return $res;
}

/**
 * The storage disk was unplugged and plugged back in: ask the disk service to
 * mount it again (at most every 20 s, so a missing disk costs nothing).
 */
function md_try_reconnect(): bool {
    $uuid = md_active_disk();
    if ($uuid === null) return false;
    $stamp = md_state_dir() . '/reconnect.at';
    if (time() - (int)@filemtime($stamp) < 20) return false;
    @touch($stamp);
    try { md_disk_call(['action' => 'connect', 'uuid' => $uuid], 20); } catch (Throwable $e) { return false; }
    clearstatcache();
    try { md_storage_root(); return true; } catch (MdStorageOffline $e) { return false; }
}

/* ---------------------------------------------------------------------
 * Security settings
 * ------------------------------------------------------------------- */

function md_max_fails(?array $store = null): int {
    $store = $store ?? md_store();
    $n = (int)($store['settings']['max_fails'] ?? MD_MAX_FAILS);
    return max(MD_MAX_FAILS_RANGE[0], min(MD_MAX_FAILS_RANGE[1], $n));
}

/* ---------------------------------------------------------------------
 * Storage of the accounts file (always under an flock)
 * ------------------------------------------------------------------- */

function md_store_empty(): array {
    return ['version' => MD_STORE_VERSION, 'users' => [], 'settings' => ['reserve_pct' => 10]];
}

/** Read-only snapshot. */
function md_store(): array {
    static $cache = null;
    if ($cache !== null && empty($GLOBALS['MD_STORE_DIRTY'])) return $cache;
    md_users_bootstrap();
    $fp = @fopen(md_users_file(), 'r');
    if (!$fp) return md_store_empty();
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string)$raw, true);
    $GLOBALS['MD_STORE_DIRTY'] = false;
    return $cache = (is_array($data) && isset($data['users'])) ? $data + md_store_empty() : md_store_empty();
}

/**
 * Read-modify-write under an exclusive lock. $fn receives the store by
 * reference and returns a value that is passed back to the caller.
 */
function md_store_update(callable $fn) {
    md_users_bootstrap();
    $fp = fopen(md_users_file(), 'c+');
    if (!$fp) throw new RuntimeException('Cannot open the accounts file. Check permissions of state/.');
    flock($fp, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($data) || !isset($data['users'])) $data = md_store_empty();
    $data += md_store_empty();
    $result = $fn($data);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod(md_users_file(), 0640);
    $GLOBALS['MD_STORE_DIRTY'] = true;
    return $result;
}

/**
 * First run: creates the accounts file.
 *  - Fresh install: an empty accounts file. The administrator is then created
 *    on the first-time setup page, which needs the one-time setup code that
 *    only the server's terminal shows (see includes/setup.php).
 *  - Very old single-user installs (a username + password hash in config.php):
 *    that user becomes a Member and the existing files move into its drive.
 * Runs once, under a lock.
 */
function md_users_bootstrap(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $file = md_users_file();
    if (is_file($file)) { md_users_upgrade(); return; }

    $lock = fopen(md_state_dir() . '/migrate.lock', 'c');
    flock($lock, LOCK_EX);
    $legacy = defined('MD_USERNAME') && defined('MD_PASSWORD_HASH') && (string)MD_PASSWORD_HASH !== '';
    if (!is_file($file) && !$legacy) {
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode(md_store_empty(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($tmp, 0640);
        rename($tmp, $file);
        md_audit('setup', ['by' => 'system', 'detail' => 'Accounts file created; waiting for the first-time setup']);
    } elseif (!is_file($file)) {
        $root = rtrim(MD_STORAGE_ROOT, '/');
        if (!is_dir($root)) @mkdir($root, 0750, true);
        $usersDir = $root . '/users';
        // A folder of the user's own that happens to be called "users" is moved
        // aside first, so it ends up inside the admin's drive like everything else.
        $aside = null;
        if (file_exists($usersDir)) {
            $aside = $root . '/.users-own-' . bin2hex(random_bytes(4));
            @rename($usersDir, $aside);
        }
        @mkdir($usersDir, 0750, true);

        $id = bin2hex(random_bytes(8));
        $home = $usersDir . '/' . $id;
        @mkdir($home, 0750, true);

        // Move everything that was in storage/ into the admin's drive
        foreach ((@scandir($root) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'users' || md_is_internal_file($entry)) continue;
            $dest = ($aside !== null && $root . '/' . $entry === $aside) ? 'users' : $entry;
            @rename($root . '/' . $entry, $home . '/' . $dest);
        }

        $name = defined('MD_USERNAME') ? (string)MD_USERNAME : 'admin';
        $hash = defined('MD_PASSWORD_HASH') ? (string)MD_PASSWORD_HASH : '';
        $data = md_store_empty();
        $data['users'][$id] = md_new_user_record($id, $name, $hash, 'user', ['mode' => 'auto', 'value' => 0]);
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($tmp, 0640);
        rename($tmp, $file);
        md_audit('setup', ['by' => 'system', 'user' => $name, 'detail' => 'Accounts created; existing files moved to ' . $name . '\'s drive']);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    md_users_upgrade();
}

/**
 * Version 1 → 2: administrators no longer have a drive. Every account that
 * was an administrator in version 1 (and so has files) becomes a Member and
 * keeps all of its files; its sessions are closed. A separate administrator
 * account is then created from the console.
 */
function md_users_upgrade(): void {
    $file = md_users_file();
    $fp = @fopen($file, 'r');
    if (!$fp) return;
    flock($fp, LOCK_SH);
    $data = json_decode((string)stream_get_contents($fp), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (!is_array($data) || (int)($data['version'] ?? 1) >= MD_STORE_VERSION) return;

    $fp = fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fp), true);
    $demoted = [];
    if (is_array($data) && isset($data['users']) && (int)($data['version'] ?? 1) < MD_STORE_VERSION) {
        $from = (int)($data['version'] ?? 1);
        foreach ($data['users'] as $id => &$u) {
            if ($from < 2 && ($u['role'] ?? '') === 'admin') {
                $u['role'] = 'user';
                $u['sv'] = (int)($u['sv'] ?? 1) + 1;
                $demoted[] = $u['username'];
            }
            $u += ['fails' => 0, 'locked' => false, 'locked_at' => null, 'locked_by' => null];
            if (!empty($u['disabled'])) {               // v3: one concept only, "locked"
                $u['locked'] = true;
                $u['locked_at'] = $u['locked_at'] ?? time();
                $u['locked_by'] = 'admin';
            }
            $u['disabled'] = false;
        }
        unset($u);
        $data['version'] = MD_STORE_VERSION;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    $GLOBALS['MD_STORE_DIRTY'] = true;
    $GLOBALS['MD_UPGRADED'] = $demoted;
    if ($demoted) {
        md_audit('upgrade', ['by' => 'system', 'user' => implode(', ', $demoted), 'detail' => 'Now a member with their own drive; administrators are separate accounts']);
    }
}

function md_new_user_record(string $id, string $name, string $hash, string $role, array $quota): array {
    $admin = $role === 'admin';
    return [
        'id' => $id,
        'username' => $name,
        'hash' => $hash,
        'role' => $admin ? 'admin' : 'user',
        'quota' => $admin ? ['mode' => 'none', 'value' => 0] : md_clean_quota($quota),
        'disabled' => false,
        'fails' => 0,              // wrong passwords in a row
        'locked' => false,         // after too many wrong passwords (members), or by an administrator
        'locked_at' => null,
        'locked_by' => null,       // 'fails' | 'admin'
        'sv' => 1,                 // session version: bumping it signs the user out everywhere
        'created' => time(),
        'last_login' => null,
        'used' => 0,
        'used_at' => 0,
    ];
}

/* ---------------------------------------------------------------------
 * Lockout
 * ------------------------------------------------------------------- */

function md_is_locked(array $u): bool {
    return !empty($u['locked']) || !empty($u['disabled']);
}

/**
 * A wrong password for an existing account. Members are locked on the
 * md_max_fails()-th in a row (and signed out everywhere). Administrators are
 * never locked this way. Returns ['locked' => bool, 'left' => attempts left|null].
 */
function md_register_fail(string $id, string $where = 'Sign-in'): array {
    $res = md_store_update(function (array &$d) use ($id) {
        if (!isset($d['users'][$id]) || $d['users'][$id]['role'] === 'admin') return ['locked' => false, 'left' => null, 'now' => false, 'max' => 0];
        $max = md_max_fails($d);
        $u = &$d['users'][$id];
        if (!empty($u['locked'])) return ['locked' => true, 'left' => 0, 'now' => false, 'max' => $max];
        $u['fails'] = (int)($u['fails'] ?? 0) + 1;
        if ($u['fails'] >= $max) {
            $u['locked'] = true;
            $u['locked_at'] = time();
            $u['locked_by'] = 'fails';
            $u['fails'] = 0;
            $u['sv'] = (int)$u['sv'] + 1;          // closes every open session
            return ['locked' => true, 'left' => 0, 'now' => true, 'max' => $max];
        }
        return ['locked' => false, 'left' => $max - $u['fails'], 'now' => false, 'max' => $max];
    });
    if ($res['now']) {
        $name = md_store()['users'][$id]['username'] ?? '?';
        md_audit('account_locked', ['by' => null, 'user' => $name, 'detail' => $where . ': ' . $res['max'] . ' wrong passwords in a row']);
    }
    return $res;
}

/** An administrator locks an account by hand (signs it out everywhere). */
function md_lock(string $id): void {
    md_store_update(function (array &$d) use ($id) {
        if (!isset($d['users'][$id])) return;
        $d['users'][$id]['locked'] = true;
        $d['users'][$id]['locked_at'] = time();
        $d['users'][$id]['locked_by'] = 'admin';
        $d['users'][$id]['fails'] = 0;
        $d['users'][$id]['sv'] = (int)$d['users'][$id]['sv'] + 1;
    });
}

function md_clear_fails(string $id): void {
    md_store_update(function (array &$d) use ($id) {
        if (isset($d['users'][$id]) && !empty($d['users'][$id]['fails'])) $d['users'][$id]['fails'] = 0;
    });
}

function md_unlock(string $id): void {
    md_store_update(function (array &$d) use ($id) {
        if (!isset($d['users'][$id])) return;
        $d['users'][$id]['locked'] = false;
        $d['users'][$id]['locked_at'] = null;
        $d['users'][$id]['locked_by'] = null;
        $d['users'][$id]['disabled'] = false;
        $d['users'][$id]['fails'] = 0;
    });
}

/*
 * Names that are not (usable) accounts get the same treatment: after
 * MD_MAX_FAILS tries they also answer "locked". Otherwise an attacker could
 * tell real members from made-up names by who gets locked.
 */
function md_ghost_edit(callable $fn) {
    $fp = @fopen(md_state_dir() . '/login-names.json', 'c+');
    if (!$fp) { $d = []; return $fn($d); }
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d)) $d = [];
    $now = time();
    foreach ($d as $k => $v) if (($v['t'] ?? 0) < $now - MD_GHOST_TTL) unset($d[$k]);
    if (count($d) > 2000) { uasort($d, fn($a, $b) => $b['t'] <=> $a['t']); $d = array_slice($d, 0, 2000, true); }
    $res = $fn($d);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($d));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod(md_state_dir() . '/login-names.json', 0640);
    return $res;
}
function md_ghost_key(string $name): string { return hash('sha256', 'bv-ghost|' . strtolower(trim($name))); }
function md_ghost_locked(string $name): bool {
    $k = md_ghost_key($name);
    $max = md_max_fails();
    return (bool)md_ghost_edit(fn(array &$d) => ($d[$k]['n'] ?? 0) >= $max);
}
/** Returns the same shape as md_register_fail(). */
function md_ghost_fail(string $name): array {
    $k = md_ghost_key($name);
    $max = md_max_fails();
    return md_ghost_edit(function (array &$d) use ($k, $max) {
        $n = (int)($d[$k]['n'] ?? 0) + 1;
        $d[$k] = ['n' => $n, 't' => time()];
        return ['locked' => $n >= $max, 'left' => max(0, $max - $n), 'now' => $n === $max, 'max' => $max];
    });
}

/* ---------------------------------------------------------------------
 * Lookups
 * ------------------------------------------------------------------- */

function md_find_user_by_name(string $name): ?array {
    $needle = strtolower(trim($name));
    foreach (md_store()['users'] as $u) {
        if (strtolower($u['username']) === $needle) return $u;
    }
    return null;
}
function md_get_user(string $id): ?array {
    return md_store()['users'][$id] ?? null;
}
function md_me(): ?array { return $GLOBALS['MD_ME']; }
function md_is_admin(): bool { $me = md_me(); return $me && $me['role'] === 'admin'; }

/** The signed-in member's private drive. Created on demand. Administrators have none. */
function md_root(): string {
    $me = md_me();
    if (!$me || !preg_match(MD_ID_RE, $me['id'])) {
        throw new RuntimeException('No signed-in user');
    }
    if ($me['role'] !== 'user') {
        throw new RuntimeException('Administrators have no drive');
    }
    $home = md_users_dir() . '/' . $me['id'];
    if (!is_dir($home)) @mkdir($home, 0750, true);
    $real = realpath($home);
    if ($real === false) throw new RuntimeException('Drive folder missing');
    return $real;
}

function md_user_home(string $id): string {
    if (!preg_match(MD_ID_RE, $id)) throw new InvalidArgumentException('Bad id');
    return md_users_dir() . '/' . $id;
}

/* ---------------------------------------------------------------------
 * Quotas
 *   auto  = equal share of whatever the other quotas leave free
 *   share = a percentage of the storage pool (½ = 50, ⅓ = 33.3…)
 *   fixed = an exact size in bytes
 *   none  = no limit (only the disk itself)
 * The pool is the disk size minus the space reserved for the system.
 * ------------------------------------------------------------------- */

function md_clean_quota($q): array {
    $mode = is_array($q) ? (string)($q['mode'] ?? 'auto') : 'auto';
    $value = is_array($q) ? (float)($q['value'] ?? 0) : 0;
    if (!in_array($mode, ['auto', 'share', 'fixed', 'none'], true)) $mode = 'auto';
    if ($mode === 'share') $value = max(1, min(100, round($value, 2)));
    elseif ($mode === 'fixed') $value = max(1, floor($value));
    else $value = 0;
    return ['mode' => $mode, 'value' => $value];
}

function md_disk_totals(): array {
    try { $root = md_storage_root(); } catch (MdStorageOffline $e) { return ['total' => 0.0, 'free' => 0.0]; }
    $total = (float)(@disk_total_space($root) ?: 0);
    $free = (float)(@disk_free_space($root) ?: 0);
    return ['total' => $total, 'free' => $free];
}

/** Limit in bytes for every account: [id => int|null]. null = unlimited. */
function md_quota_limits(?array $store = null): array {
    $store = $store ?? md_store();
    $disk = md_disk_totals();
    $reserve = max(0, min(90, (float)($store['settings']['reserve_pct'] ?? 10)));
    $pool = $disk['total'] * (1 - $reserve / 100);
    $limits = [];
    $fixedSum = 0.0;
    $autoIds = [];
    foreach ($store['users'] as $id => $u) {
        if (($u['role'] ?? '') === 'admin') continue;     // administrators have no drive
        $q = md_clean_quota($u['quota'] ?? []);
        if ($q['mode'] === 'share') { $limits[$id] = (int)floor($pool * $q['value'] / 100); $fixedSum += $limits[$id]; }
        elseif ($q['mode'] === 'fixed') { $limits[$id] = (int)$q['value']; $fixedSum += $limits[$id]; }
        elseif ($q['mode'] === 'none') { $limits[$id] = null; }
        else {
            $autoIds[] = $id;
        }
    }
    if ($autoIds) {
        $each = (int)floor(max(0, $pool - $fixedSum) / count($autoIds));
        foreach ($autoIds as $id) $limits[$id] = $each;
    }
    return $limits;
}

function md_pool_info(?array $store = null): array {
    $store = $store ?? md_store();
    $disk = md_disk_totals();
    $reserve = max(0, min(90, (float)($store['settings']['reserve_pct'] ?? 10)));
    return [
        'disk_total' => $disk['total'],
        'disk_free' => $disk['free'],
        'reserve_pct' => $reserve,
        'pool' => $disk['total'] * (1 - $reserve / 100),
    ];
}

/* ---------------------------------------------------------------------
 * Space used by each drive
 * ------------------------------------------------------------------- */

/** Size of a file or folder tree, without following symlinks. */
function md_tree_size(string $path): int {
    if (is_link($path)) return 0;
    if (is_file($path)) return (int)@filesize($path);
    if (!is_dir($path)) return 0;
    $sum = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if ($f->isLink()) continue;
            if ($f->isFile()) $sum += $f->getSize();
        }
    } catch (Throwable $e) { /* unreadable folder: count what we could */ }
    return $sum;
}

/** Recounts a drive from disk (slow on huge drives: cached for an hour). */
function md_usage_recount(string $id, bool $force = false): int {
    $u = md_get_user($id);
    if (!$u) return 0;
    if (!$force && (time() - (int)($u['used_at'] ?? 0)) < MD_USAGE_MAX_AGE) return (int)$u['used'];
    if ($u['role'] === 'admin') return 0;
    try {
        // The trash counts too: deleting only frees space once the trash is emptied
        $size = md_tree_size(md_user_home($id)) + md_tree_size(md_trash_dir($id, false));
    } catch (MdStorageOffline $e) {
        return (int)$u['used'];
    }
    md_store_update(function (array &$d) use ($id, $size) {
        if (isset($d['users'][$id])) { $d['users'][$id]['used'] = $size; $d['users'][$id]['used_at'] = time(); }
    });
    return $size;
}

/** Adds (or subtracts) bytes after an upload or a delete. */
function md_usage_add(string $id, int $delta): void {
    if ($delta === 0) return;
    md_store_update(function (array &$d) use ($id, $delta) {
        if (isset($d['users'][$id])) $d['users'][$id]['used'] = max(0, (int)$d['users'][$id]['used'] + $delta);
    });
}

/** Space summary for the signed-in user (drives the storage card). */
function md_my_space(): array {
    $me = md_me();
    $store = md_store();
    $limit = md_quota_limits($store)[$me['id']] ?? null;
    $used = (int)($store['users'][$me['id']]['used'] ?? 0);
    $disk = md_disk_totals();
    if ($limit === null) {
        $total = $used + $disk['free'];
        $free = $disk['free'];
    } else {
        $total = $limit;
        $free = max(0, min($limit - $used, $disk['free']));
    }
    return [
        'total' => $total,
        'used' => $used,
        'free' => $free,
        'limited' => $limit !== null,
        'percent' => $total > 0 ? round(min(100, $used / $total * 100), 1) : 0,
        'total_human' => md_human_size((int)$total),
        'used_human' => md_human_size($used),
        'free_human' => md_human_size((int)$free),
    ];
}

/* ---------------------------------------------------------------------
 * Validation helpers
 * ------------------------------------------------------------------- */

function md_valid_username(string $n): bool { return (bool)preg_match(MD_USERNAME_RE, $n); }

function md_password_problem(string $p): ?string {
    if (strlen($p) < MD_MIN_PASSWORD) return 'Use at least ' . MD_MIN_PASSWORD . ' characters.';
    if (strlen($p) > 200) return 'That password is too long.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9\W_]/', $p)) return 'Mix letters with numbers or symbols.';
    return null;
}

function md_hash_password(string $p): string { return password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]); }

function md_admin_count(array $store, ?string $excludeId = null): int {
    $n = 0;
    foreach ($store['users'] as $id => $u) {
        if ($id !== $excludeId && $u['role'] === 'admin' && !md_is_locked($u)) $n++;
    }
    return $n;
}

/* ---------------------------------------------------------------------
 * Audit log
 * ------------------------------------------------------------------- */

function md_audit(string $event, array $info = []): void {
    $file = md_state_dir() . '/audit.log';
    $me = $GLOBALS['MD_ME'] ?? null;
    $line = json_encode([
        't' => time(),
        'event' => $event,
        'by' => $info['by'] ?? ($me['username'] ?? null),
        'user' => $info['user'] ?? null,
        'detail' => $info['detail'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $fp = @fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    fseek($fp, 0, SEEK_END);
    if (ftell($fp) > MD_AUDIT_MAX_BYTES) {          // keep the newest half
        rewind($fp);
        $all = stream_get_contents($fp);
        $keep = substr($all, (int)(strlen($all) / 2));
        $keep = substr($keep, strpos($keep, "\n") + 1);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $keep);
    }
    fwrite($fp, $line);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($file, 0640);
}

function md_audit_tail(int $n = 60): array {
    $file = md_state_dir() . '/audit.log';
    if (!is_file($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach (array_reverse(array_slice($lines, -$n)) as $l) {
        $e = json_decode($l, true);
        if (is_array($e)) $out[] = $e;
    }
    return $out;
}

/** Public view of an account (never includes the password hash). */
function md_public_user(array $u, $limit): array {
    return [
        'id' => $u['id'],
        'username' => $u['username'],
        'role' => $u['role'],
        'disabled' => !empty($u['disabled']),
        'locked' => md_is_locked($u),
        'locked_at' => md_is_locked($u) ? ($u['locked_at'] ?? null) : null,
        'locked_by' => md_is_locked($u) ? ($u['locked_by'] ?? 'admin') : null,
        'fails' => (int)($u['fails'] ?? 0),
        'quota' => md_clean_quota($u['quota'] ?? []),
        'limit' => $limit,
        'used' => (int)($u['used'] ?? 0),
        'created' => $u['created'] ?? null,
        'last_login' => $u['last_login'] ?? null,
    ];
}

require_once __DIR__ . '/trash.php';
