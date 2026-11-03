<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

if (sm_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$locked = bv_login_locked_for('sm');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = (string)($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');

    if ($locked > 0) {
        $error = 'Too many attempts. Try again in ' . bv_wait_text($locked) . '.';
    } elseif (!bv_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'The page expired. Please try again.';
    } else {
        if (sm_accounts() === null) {
            $error = 'Black Vault accounts not found. Run the installer again (sudo bash blackvault/install.sh).';
        } else {
            // Only administrators can open the Monitor. Members and unknown names
            // get the same answer in the same time, whatever password they type.
            $u = strlen($user) <= 64 ? sm_find_account($user) : null;
            $admin = sm_usable_admin($u);
            $passOk = password_verify($pass, $admin ? $u['hash'] : SM_DUMMY_HASH);
            if ($admin && $passOk) {
                bv_login_ok('sm');
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['sm_uid'] = $u['id'];
                $_SESSION['sm_sv'] = (int)$u['sv'];
                $_SESSION['sm_user'] = $u['username'];
                $_SESSION['bv_born'] = time();
                $_SESSION['bv_last'] = time();
                sm_audit('monitor_login', $u['username']);
                header('Location: index.php');
                exit;
            }
            bv_login_failed('sm');
            sm_audit('monitor_login_failed', $user, $u && !$admin ? 'Not an administrator' : null);
            usleep(300000);
            $locked = bv_login_locked_for('sm');
            $error = $locked > 0
                ? 'Too many attempts. Try again in ' . bv_wait_text($locked) . '.'
                : 'Wrong username or password.';
        }
    }
}
// Fresh install: no administrator exists until the first-time setup is done in the Drive
$noAdmin = false;
if (($acc = sm_accounts()) !== null) {
    $noAdmin = true;
    foreach ($acc['users'] as $a) { if (($a['role'] ?? '') === 'admin') { $noAdmin = false; break; } }
}
$drivePort = defined('SM_DRIVE_PORT') ? (int)SM_DRIVE_PORT : (bv_is_https() ? 443 : 80);
$hostOnly = preg_replace('/:\d+$/', '', (string)($_SERVER['SERVER_ADDR'] ?? ''));
$driveUrl = (bv_is_https() ? 'https://' : 'http://') . (filter_var($hostOnly, FILTER_VALIDATE_IP) ? (strpos($hostOnly, ':') !== false ? '[' . $hostOnly . ']' : $hostOnly) : 'SERVER_IP') . (in_array($drivePort, [80, 443], true) ? '' : ':' . $drivePort) . '/';
$csrf = bv_csrf_token();
header('Cache-Control: no-store');
$host = gethostname() ?: 'server';
$v = fn(string $f) => @filemtime(__DIR__ . '/assets/' . $f) ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#0c0f13">
<title>Sign in · Black Vault Monitor</title>
<link rel="icon" href="assets/logo.svg?v=<?= $v('logo.svg') ?>" type="image/svg+xml">
<link rel="preload" href="assets/fonts/manrope-500.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/base.css?v=<?= $v('base.css') ?>">
<script src="assets/prefs.js?v=<?= $v('prefs.js') ?>"></script>
</head>
<body class="login-body">
<?php include __DIR__ . '/includes/icons.php'; ?>
<main class="login">
  <section class="login-art" aria-hidden="true">
    <div class="dial"></div>
    <?php include __DIR__ . '/includes/logo.php'; ?>
    <div>
      <h2>Your files and your server, behind one door.</h2>
      <p>Private storage and live system monitoring on your own hardware. Nothing leaves your network.</p>
    </div>
    <div class="login-facts">
      <span><svg class="ic"><use href="#i-lock"/></svg>Local network only</span>
      <span><svg class="ic"><use href="#i-key-round"/></svg>Rate-limited sign-in</span>
      <span><svg class="ic"><use href="#i-shield-check"/></svg><?= bv_is_https() ? 'Encrypted connection' : 'Strict security headers' ?></span>
    </div>
  </section>

  <section class="login-panel">
    <div class="login-main">
      <div class="login-mobile-brand"><?php include __DIR__ . '/includes/logo.php'; ?></div>
      <span class="secure-pill"><svg class="ic" aria-hidden="true"><use href="#i-server"/></svg><?= htmlspecialchars($host) ?> · Monitor</span>
      <h1>Sign in</h1>
      <p class="login-sub">Administrator accounts only.</p>

      <?php if ($noAdmin): ?>
        <div class="setup-note" role="status">
          <svg class="ic" aria-hidden="true"><use href="#i-key-round"/></svg>
          <div>This server is not set up yet. Open the Drive to create the administrator:<code><?= htmlspecialchars($driveUrl) ?></code></div>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="login-error" role="alert">
          <svg class="ic" aria-hidden="true"><use href="#i-circle-alert"/></svg>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" class="login-form" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <label class="field">
          <span>Username</span>
          <input class="text-input" type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required <?= $error ? '' : 'autofocus' ?> value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </label>
        <label class="field">
          <span>Password</span>
          <input class="text-input" type="password" name="password" autocomplete="current-password" required <?= $error ? 'autofocus' : '' ?>>
        </label>
        <button type="submit" class="btn btn-primary"<?= $locked > 0 ? ' disabled' : '' ?>>Sign in</button>
      </form>
      <p class="login-foot">
        <svg class="ic" aria-hidden="true"><use href="#i-shield-check"/></svg>
        Private session, signs out after 8 hours of inactivity
      </p>
    </div>
  </section>
</main>
</body>
</html>
