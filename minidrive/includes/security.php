<?php
/**
 * Black Vault — security core (identical in the Drive and the Monitor)
 *
 * Loaded BEFORE config.php (so it works even with an old config.php).
 * It takes care of:
 *   - Hardened sessions, separate for each app.
 *   - Security headers (CSP, anti-clickjacking, etc.).
 *   - Limiting access to your local network / VPN.
 *   - Slowing down password guessing on the sign-in page.
 *   - CSRF tokens.
 */

const BV_SESSION_IDLE = 8 * 3600;      // sign out after 8 h without activity
const BV_SESSION_MAX = 24 * 3600;      // and after 24 h in a row at most
const BV_LOGIN_MAX_FAILS = 5;          // failed attempts allowed...
const BV_LOGIN_WINDOW = 900;           // ...in 15 minutes
const BV_LOGIN_LOCK = 900;             // 15-minute block

/** Networks allowed by default: localhost, home networks and VPNs (Tailscale/WireGuard). */
const BV_DEFAULT_NETS = [
    '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
    '100.64.0.0/10',                       // Tailscale
    '169.254.0.0/16',
    '::1/128', 'fc00::/7', 'fe80::/10',
];

function bv_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/**
 * Before config.php: session and headers.
 * $app: 'md' or 'sm'. $scriptCsp: extra allowed origins (none by default).
 */
function bv_boot(string $app): void {
    // ---- Errors: never shown to the visitor ----
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    @ini_set('log_errors', '1');
    header_remove('X-Powered-By');

    // ---- Session: its own cookie name for each app ----
    // Cookies are NOT separated by port: without this, signing in to the Drive
    // would also open the Monitor (and signing out of one would close the other).
    if (session_status() === PHP_SESSION_NONE) {
        session_name($app === 'md' ? 'BV_DRIVE' : 'BV_MONITOR');
        session_set_cookie_params([
            'lifetime' => 0,                 // deleted when the browser closes
            'path' => '/',
            'secure' => bv_is_https(),
            'httponly' => true,              // JavaScript cannot read it
            'samesite' => 'Strict',          // never sent from other websites
        ]);
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.gc_maxlifetime', (string)BV_SESSION_MAX);
        @ini_set('session.sid_length', '48');
        @ini_set('session.sid_bits_per_character', '6');
        @ini_set('session.upload_progress.enabled', '0');
    }

    bv_security_headers($app);
}

function bv_security_headers(string $app, bool $embeddable = false): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header('X-Frame-Options: ' . ($embeddable ? 'SAMEORIGIN' : 'DENY'));
    header('X-Robots-Tag: noindex, nofollow');
    if (bv_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    // CSP: only code from this same site. No inline scripts, no plugins,
    // no <base>, forms only post back here, and nobody can embed the site.
    $csp = [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self' 'unsafe-inline'",   // 'unsafe-inline' only for styles (progress bars)
        "img-src 'self' data: blob:",
        "media-src 'self' blob:",
        "font-src 'self'",
        "connect-src 'self'",
        "frame-src 'self'",                  // the PDF preview
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'self'",
        "frame-ancestors " . ($embeddable ? "'self'" : "'none'"),
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

/** After config.php: allowed network, session and expiry. */
function bv_after_config(string $prefix): void {
    // ---- Local network ----
    $allowAll = defined('BV_ALLOW_ANY_NETWORK') && BV_ALLOW_ANY_NETWORK === true;
    if (!$allowAll) {
        $nets = defined('BV_ALLOWED_NETS') && is_array(BV_ALLOWED_NETS) ? BV_ALLOWED_NETS : BV_DEFAULT_NETS;
        // Only REMOTE_ADDR: anyone can invent X-Forwarded-For headers.
        if (!bv_ip_in_nets($_SERVER['REMOTE_ADDR'] ?? '', $nets)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Access denied.');
        }
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ---- Session expiry ----
    $now = time();
    if (!empty($_SESSION[$prefix . '_user'])) {
        $last = $_SESSION['bv_last'] ?? $now;
        $born = $_SESSION['bv_born'] ?? $now;
        if ($now - $last > BV_SESSION_IDLE || $now - $born > BV_SESSION_MAX) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
    }
    $_SESSION['bv_last'] = $now;
    $_SESSION['bv_born'] = $_SESSION['bv_born'] ?? $now;
}

/* ---------------- IP inside a list of networks (IPv4 and IPv6) ---------------- */
function bv_ip_in_nets(string $ip, array $nets): bool {
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    // IPv4 mapped into IPv6 (::ffff:192.168.1.5) -> IPv4
    if (strlen($bin) === 16 && strncmp($bin, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12) === 0) {
        $bin = substr($bin, 12);
    }
    foreach ($nets as $net) {
        [$base, $bits] = array_pad(explode('/', $net, 2), 2, null);
        $nb = @inet_pton($base);
        if ($nb === false || strlen($nb) !== strlen($bin)) continue;
        $bits = $bits === null ? strlen($nb) * 8 : (int)$bits;
        $full = intdiv($bits, 8);
        if ($full && strncmp($bin, $nb, $full) !== 0) continue;
        $rest = $bits % 8;
        if ($rest) {
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$full]) & $mask) !== (ord($nb[$full]) & $mask)) continue;
        }
        return true;
    }
    return false;
}

/* ---------------- CSRF ---------------- */
function bv_csrf_token(): string {
    if (empty($_SESSION['bv_csrf'])) {
        $_SESSION['bv_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['bv_csrf'];
}
function bv_csrf_ok(?string $sent): bool {
    return !empty($_SESSION['bv_csrf']) && is_string($sent) && hash_equals($_SESSION['bv_csrf'], $sent);
}

/* ---------------- Brake against password guessing ----------------
 * Counts failures per IP in a file inside state/ (which Apache never
 * serves). After 5 failures in 15 min, that IP is blocked for 15 min.
 */
function bv_throttle_file(string $app): string {
    $dir = dirname(__DIR__) . '/state';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/login-' . $app . '.json';
}

/** Runs $fn with the file locked and passes it the data; saves what it changes. */
function bv_throttle_edit(string $app, callable $fn) {
    $file = bv_throttle_file($app);
    $fp = @fopen($file, 'c+');
    if (!$fp) { $empty = []; return $fn($empty); } // if it cannot be written, nobody is blocked by mistake
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) $data = [];
    $now = time();
    foreach ($data as $k => $v) {                     // drop old entries
        if (($v['lock'] ?? 0) < $now && ($v['first'] ?? 0) < $now - BV_LOGIN_WINDOW) unset($data[$k]);
    }
    $result = $fn($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function bv_client_key(): string {
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x');
}

/** Seconds left before trying again (0 = may try now). */
function bv_login_locked_for(string $app): int {
    $key = bv_client_key();
    return (int)bv_throttle_edit($app, function (array &$d) use ($key) {
        $lock = $d[$key]['lock'] ?? 0;
        return $lock > time() ? $lock - time() : 0;
    });
}

function bv_login_failed(string $app): void {
    $key = bv_client_key();
    bv_throttle_edit($app, function (array &$d) use ($key) {
        $now = time();
        $e = $d[$key] ?? ['n' => 0, 'first' => $now, 'lock' => 0];
        if ($now - $e['first'] > BV_LOGIN_WINDOW) $e = ['n' => 0, 'first' => $now, 'lock' => 0];
        $e['n']++;
        if ($e['n'] >= BV_LOGIN_MAX_FAILS) { $e['lock'] = $now + BV_LOGIN_LOCK; $e['n'] = 0; $e['first'] = $now; }
        $d[$key] = $e;
        return null;
    });
}

function bv_login_ok(string $app): void {
    $key = bv_client_key();
    bv_throttle_edit($app, function (array &$d) use ($key) { unset($d[$key]); return null; });
}

/** Friendly text for the waiting time. */
function bv_wait_text(int $secs): string {
    return $secs >= 90 ? ceil($secs / 60) . ' minutes' : $secs . ' seconds';
}

/** Ends the session completely (data + cookie). */
function bv_destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}
