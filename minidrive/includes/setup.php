<?php
/**
 * First-time setup.
 *
 * A fresh install has no administrator. Until one exists, the Drive shows the
 * setup page instead of the sign-in page. To stop anyone else on the network
 * from claiming the server first, the page asks for a one-time setup code
 * that only the server's terminal shows (the installer prints it, and
 * `sudo blackvault-admin setup-code` makes a new one).
 *
 * The code is stored hashed in state/setup.json (never served by Apache),
 * expires after 24 hours, allows a limited number of wrong tries and is
 * deleted as soon as the administrator is created.
 */
require_once __DIR__ . '/users.php';

const MD_SETUP_CODE_TTL = 24 * 3600;
const MD_SETUP_MAX_TRIES = 10;
const MD_SETUP_STEP_TTL = 30 * 60;                     // time to finish after entering the code
const MD_SETUP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I to mix up

function md_setup_file(): string { return md_state_dir() . '/setup.json'; }

/** True while there is no administrator account at all. */
function md_setup_needed(): bool {
    foreach (md_store()['users'] as $u) {
        if (($u['role'] ?? '') === 'admin') return false;
    }
    return true;
}

/** Makes a new code (replacing any older one) and returns it as XXXX-XXXX-XXXX. */
function md_setup_new_code(): string {
    $code = '';
    for ($i = 0; $i < 12; $i++) $code .= MD_SETUP_ALPHABET[random_int(0, strlen(MD_SETUP_ALPHABET) - 1)];
    $data = ['hash' => password_hash($code, PASSWORD_BCRYPT, ['cost' => 11]), 'created' => time(), 'tries' => 0];
    $file = md_setup_file();
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($data));
    @chmod($tmp, 0600);
    rename($tmp, $file);
    return substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4);
}

/** Uppercase, without spaces or dashes, so "abcd efgh-jklm" also works. */
function md_setup_clean(string $s): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(substr($s, 0, 64)));
}

/**
 * Checks a code typed on the setup page.
 * Returns 'ok', 'wrong', 'expired', 'used_up' (too many wrong tries) or 'missing'.
 */
function md_setup_check(string $typed): string {
    if (!is_file(md_setup_file())) return 'missing';
    $fp = @fopen(md_setup_file(), 'c+');
    if (!$fp) return 'missing';
    flock($fp, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fp), true);
    $result = 'wrong';
    if (!is_array($data) || empty($data['hash'])) {
        $result = 'missing';
    } elseif (time() - (int)($data['created'] ?? 0) > MD_SETUP_CODE_TTL) {
        $result = 'expired';
    } elseif ((int)($data['tries'] ?? 0) >= MD_SETUP_MAX_TRIES) {
        $result = 'used_up';
    } else {
        $code = md_setup_clean($typed);
        if (strlen($code) === 12 && password_verify($code, (string)$data['hash'])) {
            $result = 'ok';
        } else {
            $data['tries'] = (int)($data['tries'] ?? 0) + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
        }
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

/** The code is used: delete it. */
function md_setup_done(): void {
    @unlink(md_setup_file());
}
