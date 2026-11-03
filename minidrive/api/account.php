<?php
/**
 * The signed-in user's own account: change password, sign out other devices.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
$me = md_require_login();
md_check_csrf();
$in = md_json_body();
$action = (string)($in['action'] ?? '');

if ($action === 'password') {
    $current = (string)($in['current'] ?? '');
    $new = (string)($in['password'] ?? '');
    if (bv_login_locked_for('md') > 0) md_json(['error' => 'Too many attempts. Try again later.'], 429);
    if (!password_verify($current, $me['hash'])) {
        bv_login_failed('md');
        md_audit('password_change_failed', ['user' => $me['username']]);
        // Same rule as the sign-in page: a member who gets it wrong 3 times in a row is locked
        $fail = md_register_fail($me['id'], 'Change password');
        if ($fail['locked']) {
            bv_destroy_session();
            md_json(['error' => MD_LOCKED_MSG, 'locked' => true], 401);
        }
        md_json(['error' => 'Your current password is not correct.' . ($fail['left'] === 1 ? ' One more mistake and your account will be locked.' : '')], 400);
    }
    md_clear_fails($me['id']);
    if ($p = md_password_problem($new)) md_json(['error' => $p], 400);
    if (hash_equals($current, $new)) md_json(['error' => 'Choose a password different from the current one.'], 400);
    $hash = md_hash_password($new);
    $id = $me['id'];
    $sv = md_store_update(function (array &$d) use ($id, $hash) {
        $d['users'][$id]['hash'] = $hash;
        $d['users'][$id]['sv'] = (int)$d['users'][$id]['sv'] + 1;   // other devices are signed out
        return $d['users'][$id]['sv'];
    });
    $_SESSION['md_sv'] = $sv;                                         // this one stays signed in
    session_regenerate_id(true);
    md_audit('password_changed', ['user' => $me['username']]);
    md_json(['ok' => true]);
}

if ($action === 'signout_others') {
    $id = $me['id'];
    $sv = md_store_update(function (array &$d) use ($id) {
        $d['users'][$id]['sv'] = (int)$d['users'][$id]['sv'] + 1;
        return $d['users'][$id]['sv'];
    });
    $_SESSION['md_sv'] = $sv;
    md_audit('signed_out', ['user' => $me['username'], 'detail' => 'Other devices signed out']);
    md_json(['ok' => true]);
}

md_json(['error' => 'Unknown action.'], 400);
