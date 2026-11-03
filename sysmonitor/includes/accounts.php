<?php
/**
 * SysMonitor — sign-in with the Black Vault accounts (administrators only).
 *
 * The accounts live in the Drive's state/users.json. The Monitor only READS
 * them: members are always refused (without telling them whether their
 * password was right), so the Monitor cannot be used to guess passwords.
 * Every request re-reads the account, so disabling or deleting an
 * administrator, or resetting their password, closes the Monitor too.
 */

// A real bcrypt hash, used when the name is not an administrator, so every answer takes the same time.
const SM_DUMMY_HASH = '$2y$12$k3xIPhrEwNsg5KIMeExQgebwGjxlNZmhlHI1eAqyQ.tVtkFPjhMTO';

function sm_drive_dir(): string {
    return rtrim(defined('SM_DRIVE_DIR') ? (string)SM_DRIVE_DIR : dirname(__DIR__, 2) . '/minidrive', '/');
}

/** Accounts file contents, or null if missing / not upgraded yet. */
function sm_accounts(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $fp = @fopen(sm_drive_dir() . '/state/users.json', 'r');
    if (!$fp) return $cache = null;
    flock($fp, LOCK_SH);
    $data = json_decode((string)stream_get_contents($fp), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    // Version 2 = administrators are separate accounts. Older files are refused.
    if (!is_array($data) || !isset($data['users']) || (int)($data['version'] ?? 1) < 2) return $cache = null;
    return $cache = $data;
}

function sm_find_account(string $name): ?array {
    $store = sm_accounts();
    if (!$store) return null;
    $needle = strtolower(trim($name));
    foreach ($store['users'] as $u) {
        if (strtolower((string)$u['username']) === $needle) return $u;
    }
    return null;
}

function sm_usable_admin(?array $u): bool {
    return $u && ($u['role'] ?? '') === 'admin' && empty($u['disabled']) && empty($u['locked']);
}

/** Adds a line to the Drive's activity log, so the administrator sees Monitor sign-ins too. */
function sm_audit(string $event, ?string $user, ?string $detail = null): void {
    $file = sm_drive_dir() . '/state/audit.log';
    if (!is_file($file)) return;
    $fp = @fopen($file, 'a');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode([
        't' => time(), 'event' => $event, 'by' => null,
        'user' => $user === null ? null : substr($user, 0, 64),
        'detail' => $detail, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}
