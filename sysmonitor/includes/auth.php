<?php
/**
 * SysMonitor — authentication. Only Black Vault administrator accounts.
 */
require_once __DIR__ . '/accounts.php';

function sm_is_logged_in(): bool {
    $id = $_SESSION['sm_uid'] ?? null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{16}$/', $id)) return false;
    $u = sm_accounts()['users'][$id] ?? null;
    // Still an active administrator, and the session was not revoked
    if (!sm_usable_admin($u) || (int)($u['sv'] ?? 0) !== (int)($_SESSION['sm_sv'] ?? -1)) return false;
    $_SESSION['sm_user'] = $u['username'];
    return true;
}

function sm_require_login() {
    if (!sm_is_logged_in()) {
        if (!empty($_SESSION['sm_user']) || !empty($_SESSION['sm_uid'])) bv_destroy_session();
        if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
            sm_json(['error' => 'Not signed in'], 401);
        }
        header('Location: login.php');
        exit;
    }
}

function sm_json($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
