<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/users.php';
require __DIR__ . '/includes/setup.php';

// Fresh install: nobody can sign in until the first-time setup has created the administrator
if (md_setup_needed()) {
    header('Location: setup.php');
    exit;
}

if (!empty($_SESSION['md_uid'])) {
    header('Location: index.php');
    exit;
}

// A real bcrypt hash used when the username does not exist, so the answer
// takes the same time and an attacker cannot tell which usernames are valid.
const MD_DUMMY_HASH = '$2y$12$k3xIPhrEwNsg5KIMeExQgebwGjxlNZmhlHI1eAqyQ.tVtkFPjhMTO';

$error = '';
$locked = bv_login_locked_for('md');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if ($locked > 0) {
        $error = 'Too many attempts. Try again in ' . bv_wait_text($locked) . '.';
    } elseif (!bv_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'The page expired. Please try again.';
    } else {
        $u = strlen($name) <= 64 ? md_find_user_by_name($name) : null;
        $real = $u && empty($u['disabled']);          // disabled accounts look exactly like unknown names
        // A locked account is refused WITHOUT checking the password, so the
        // lock cannot be used to keep guessing.
        if (($real && md_is_locked($u)) || (!$real && md_ghost_locked($name))) {
            md_audit('login_blocked', ['by' => null, 'user' => substr($name, 0, 64), 'detail' => 'Account locked']);
            usleep(300000);
            $error = MD_LOCKED_MSG;
            goto render;
        }
        $passOk = password_verify($pass, $real ? $u['hash'] : MD_DUMMY_HASH);
        if ($real && $passOk) {
            bv_login_ok('md');
            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['md_uid'] = $u['id'];
            $_SESSION['md_sv'] = (int)$u['sv'];
            $_SESSION['md_user'] = $u['username'];
            $_SESSION['bv_born'] = time();
            $_SESSION['bv_last'] = time();
            $id = $u['id'];
            $rehash = password_needs_rehash($u['hash'], PASSWORD_BCRYPT, ['cost' => 12]) ? md_hash_password($pass) : null;
            md_store_update(function (array &$d) use ($id, $rehash) {
                if (!isset($d['users'][$id])) return;
                $d['users'][$id]['last_login'] = time();
                $d['users'][$id]['fails'] = 0;
                if ($rehash) $d['users'][$id]['hash'] = $rehash;
            });
            md_audit('login', ['by' => $u['username'], 'user' => $u['username']]);
            if ($u['role'] === 'admin') { header('Location: admin.php'); exit; }
            md_usage_recount($id);   // refresh the drive size if it is more than an hour old
            header('Location: index.php');
            exit;
        }
        bv_login_failed('md');
        $fail = $real ? md_register_fail($u['id']) : md_ghost_fail($name);
        md_audit('login_failed', ['by' => null, 'user' => substr($name, 0, 64), 'detail' => $u && !empty($u['disabled']) ? 'Account disabled' : null]);
        usleep(300000);
        $locked = bv_login_locked_for('md');
        if ($fail['locked']) $error = MD_LOCKED_MSG;
        elseif ($locked > 0) $error = 'Too many attempts. Try again in ' . bv_wait_text($locked) . '.';
        elseif ($fail['left'] === 1) $error = 'Wrong username or password. One more mistake and the account will be locked.';
        else $error = 'Wrong username or password.';
    }
}
render:
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
<title>Sign in · Black Vault Drive</title>
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
      <span class="secure-pill"><svg class="ic" aria-hidden="true"><use href="#i-server"/></svg><?= htmlspecialchars($host) ?> · Drive</span>
      <h1>Sign in</h1>
      <p class="login-sub">Sign in with your Black Vault account.</p>

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
