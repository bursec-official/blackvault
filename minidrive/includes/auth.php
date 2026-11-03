<?php
/**
 * MiniDrive — authentication and authorisation.
 *
 * The session only stores the account id and a "session version". On every
 * request the account is re-read from state/users.json, so disabling a user,
 * deleting them, changing their role or resetting their password takes
 * effect immediately on all of their open sessions.
 */
require_once __DIR__ . '/users.php';

function md_is_api(): bool {
    return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
}

function md_deny_login(): void {
    if (md_is_api()) md_json(['error' => 'Not signed in'], 401);
    header('Location: login.php');
    exit;
}

/** Loads the signed-in account or stops the request. */
function md_require_login(): array {
    $id = $_SESSION['md_uid'] ?? null;
    if (!is_string($id) || !preg_match(MD_ID_RE, $id)) {
        if (!empty($_SESSION['md_user'])) {            // session from the single-user version
            $_SESSION = [];
        }
        md_deny_login();
    }
    $u = md_get_user($id);
    if (!$u || !empty($u['disabled']) || md_is_locked($u) || (int)($u['sv'] ?? 0) !== (int)($_SESSION['md_sv'] ?? -1)) {
        bv_destroy_session();
        md_deny_login();
    }
    $GLOBALS['MD_ME'] = $u;
    $_SESSION['md_user'] = $u['username'];      // keeps the name shown in the top bar current
    return $u;
}

/** Drive pages and file APIs: members only. Administrators have no drive. */
function md_require_member(): array {
    $u = md_require_login();
    if ($u['role'] !== 'user') {
        if (md_is_api()) md_json(['error' => 'Administrator accounts have no drive'], 403);
        header('Location: admin.php');
        exit;
    }
    // Is the disk with the drives there? (it may be an external disk)
    $st = md_storage_state();
    if ($st['state'] === 'offline' && md_try_reconnect()) $st = md_storage_state();
    $GLOBALS['MD_STORAGE_STATE'] = $st;
    if ($st['state'] === 'offline' && md_is_api()) md_json(['error' => $st['message'], 'storage' => $st], 503);
    return $u;
}

/** Anything that changes files: refused while the drives are offline or being moved. */
function md_require_writable(): void {
    $st = $GLOBALS['MD_STORAGE_STATE'] ?? md_storage_state();
    if ($st['state'] !== 'ok') md_json(['error' => $st['message'], 'storage' => $st], 503);
}

function md_require_admin(): array {
    $u = md_require_login();
    if ($u['role'] !== 'admin') {
        if (md_is_api()) md_json(['error' => 'Administrators only'], 403);
        header('Location: index.php');
        exit;
    }
    return $u;
}

function md_csrf_token(): string {
    if (empty($_SESSION['md_csrf'])) {
        $_SESSION['md_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['md_csrf'];
}

function md_check_csrf() {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['md_csrf']) || !is_string($sent) || !hash_equals($_SESSION['md_csrf'], $sent)) {
        md_json(['error' => 'Invalid CSRF token'], 403);
    }
}

/** Reads a JSON request body (POST only, size-limited). */
function md_json_body(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') md_json(['error' => 'Method not allowed'], 405);
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}
