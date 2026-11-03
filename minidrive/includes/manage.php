<?php
/**
 * Black Vault — account console. Runs ONLY from the server's terminal.
 *
 * Installed by install.sh as:   sudo blackvault-admin <command>
 *
 *   status                   list accounts (role, locked, disabled)
 *   setup-code               new one-time code for the first-time setup page
 *   create-admin             create an administrator account (asks name + password)
 *   unlock <user>            unlock a member locked after 3 wrong passwords
 *   reset-password <user>    set a new password (also unlocks)
 *   unblock-ips              clear the temporary per-IP sign-in brake
 *   migrate                  upgrade the accounts file (used by the installer)
 *
 * It lives in includes/, which Apache refuses to serve, and it refuses to run
 * from anything other than the command line.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/security.php';          // function definitions only (no web session here)
require dirname(__DIR__) . '/config.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/users.php';
require __DIR__ . '/setup.php';

$GLOBALS['MD_ME'] = ['id' => '', 'username' => 'console', 'role' => 'console'];

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s, int $code = 1): void { fwrite(STDERR, "✘ " . $s . "\n"); exit($code); }

function ask(string $q, bool $hidden = false): string {
    fwrite(STDOUT, $q);
    $tty = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($hidden && $tty) shell_exec('stty -echo 2>/dev/null');
    $line = fgets(STDIN);
    if ($hidden && $tty) { shell_exec('stty echo 2>/dev/null'); fwrite(STDOUT, "\n"); }
    if ($line === false) fail('No input.');
    return rtrim($line, "\r\n");
}

function ask_password(): string {
    for ($try = 0; $try < 3; $try++) {
        $p = ask('  Password (at least ' . MD_MIN_PASSWORD . ' characters, letters + numbers/symbols): ', true);
        if ($err = md_password_problem($p)) { out('  ' . $err); continue; }
        if (ask('  Repeat the password: ', true) !== $p) { out('  The passwords do not match.'); continue; }
        return $p;
    }
    fail('Password not set.');
}

function user_or_fail(?string $name): array {
    if ($name === null || $name === '') fail('Say which user, e.g.: blackvault-admin unlock alex');
    $u = md_find_user_by_name($name);
    if (!$u) fail('There is no account called "' . $name . '".');
    return $u;
}

function print_status(): void {
    $users = md_store()['users'];
    if (!$users) { out('  No accounts yet. Finish the first-time setup in the browser (code: sudo blackvault-admin setup-code).'); return; }
    foreach ($users as $u) {
        $st = md_is_locked($u) ? 'LOCKED' : (!empty($u['disabled']) ? 'disabled' : 'active');
        out(sprintf('  %-24s %-14s %s', $u['username'], $u['role'] === 'admin' ? 'administrator' : 'member', $st));
    }
}

$cmd = $argv[1] ?? 'help';
$arg = $argv[2] ?? null;

switch ($cmd) {

case 'migrate':
    md_store();                                            // creates / upgrades the accounts file
    foreach ($GLOBALS['MD_UPGRADED'] ?? [] as $n) out('  ' . $n . ' is now a member (keeps all files). Administrators are separate accounts now.');
    print_status();
    exit(md_admin_count(md_store()) > 0 ? 0 : 3);         // 3 = no administrator yet

case 'status':
    print_status();
    exit(0);

case 'setup-code':
    md_store();
    if (!md_setup_needed()) fail('This server is already set up (an administrator exists). Use create-admin or reset-password instead.', 4);
    $code = md_setup_new_code();
    if (($arg ?? '') === '--plain') { out($code); exit(0); }
    out('One-time setup code:  ' . $code);
    out('Open the Drive in a browser (https://THIS_SERVER/) and type it in. Valid for 24 hours.');
    exit(0);

case 'create-admin':
    out('Create an administrator account. It manages users and opens the Monitor; it has no drive.');
    for ($try = 0; ; $try++) {
        if ($try >= 3) fail('No valid username given.');
        $name = trim(ask('  Administrator username: '));
        if (!md_valid_username($name)) { out('  Use 2–32 letters, numbers, dots, dashes or underscores.'); continue; }
        if (md_find_user_by_name($name)) { out('  "' . $name . '" already exists (members and administrators are separate accounts). Choose another name.'); continue; }
        break;
    }
    $hash = md_hash_password(ask_password());
    $id = bin2hex(random_bytes(8));
    md_store_update(function (array &$d) use ($id, $name, $hash) {
        $d['users'][$id] = md_new_user_record($id, $name, $hash, 'admin', []);
        $d['users'][$id]['used_at'] = time();
    });
    md_audit('admin_created', ['by' => 'console', 'user' => $name, 'detail' => 'From the server console']);
    md_setup_done();
    out('✔ Administrator "' . $name . '" created.');
    exit(0);

case 'unlock':
    $u = user_or_fail($arg);
    if (!md_is_locked($u)) { out('  ' . $u['username'] . ' is not locked.'); exit(0); }
    md_unlock($u['id']);
    md_audit('account_unlocked', ['by' => 'console', 'user' => $u['username'], 'detail' => 'From the server console']);
    out('✔ ' . $u['username'] . ' is unlocked.');
    exit(0);

case 'reset-password':
    $u = user_or_fail($arg);
    out('New password for ' . $u['username'] . ':');
    $hash = md_hash_password(ask_password());
    $id = $u['id'];
    md_store_update(function (array &$d) use ($id, $hash) {
        $d['users'][$id]['hash'] = $hash;
        $d['users'][$id]['sv'] = (int)$d['users'][$id]['sv'] + 1;
        $d['users'][$id]['locked'] = false;
        $d['users'][$id]['locked_at'] = null;
        $d['users'][$id]['fails'] = 0;
    });
    md_audit('password_reset', ['by' => 'console', 'user' => $u['username'], 'detail' => 'From the server console']);
    out('✔ Password changed for ' . $u['username'] . ' (unlocked and signed out everywhere).');
    exit(0);

case 'unblock-ips':
    $files = [dirname(__DIR__) . '/state/login-md.json', dirname(__DIR__) . '/state/login-setup.json', dirname(__DIR__, 2) . '/sysmonitor/state/login-sm.json'];
    foreach ($files as $f) if (is_file($f)) file_put_contents($f, '{}');
    out('✔ Temporary sign-in brakes cleared for every IP.');
    exit(0);

default:
    out('Black Vault account console');
    out('  sudo blackvault-admin status                 list accounts');
    out('  sudo blackvault-admin setup-code             new code for the first-time setup page');
    out('  sudo blackvault-admin create-admin           create an administrator');
    out('  sudo blackvault-admin unlock <user>          unlock a locked member');
    out('  sudo blackvault-admin reset-password <user>  new password (also unlocks)');
    out('  sudo blackvault-admin unblock-ips            clear the temporary IP brake');
    exit($cmd === 'help' ? 0 : 1);
}
