<?php
/**
 * First-time setup page: shown only while the server has no administrator.
 *
 *   1. Setup code   — proves this is your server (printed by the installer)
 *   2. Administrator — creates the account that manages everything
 *   3. First user    — optional account with its own drive
 *   4. Done          — next steps
 *
 * Once an administrator exists this page only finishes the session that
 * created it; everyone else is sent to the normal sign-in page.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/setup.php';

$needed = md_setup_needed();
$mine = $_SESSION['setup_admin'] ?? null;            // administrator created by THIS browser session
if (!$needed && (!is_string($mine) || ($_SESSION['md_uid'] ?? null) !== $mine)) {
    unset($_SESSION['setup_ok'], $_SESSION['setup_admin'], $_SESSION['setup_step'], $_SESSION['setup_user']);
    header('Location: login.php');
    exit;
}

$codeOk = !empty($_SESSION['setup_ok']) && time() - (int)$_SESSION['setup_ok'] < MD_SETUP_STEP_TTL;
if ($needed) $step = $codeOk ? 'admin' : 'code';
else $step = ($_SESSION['setup_step'] ?? 'user') === 'done' ? 'done' : 'user';

$error = '';
$form = ['username' => $step === 'admin' ? 'admin' : ''];
$locked = bv_login_locked_for('setup');

function setup_go(): void { header('Location: setup.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!bv_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'The page expired. Please try again.';
    } elseif ($action === 'code' && $step === 'code') {
        if ($locked > 0) {
            $error = 'Too many attempts. Try again in ' . bv_wait_text($locked) . '.';
        } else {
            $r = md_setup_check((string)($_POST['code'] ?? ''));
            if ($r === 'ok') {
                bv_login_ok('setup');
                session_regenerate_id(true);
                $_SESSION['setup_ok'] = time();
                md_audit('setup_code_ok', ['by' => null, 'detail' => 'First-time setup started']);
                setup_go();
            }
            bv_login_failed('setup');
            usleep(300000);
            $error = [
                'wrong' => 'That code is not right. Check it and try again.',
                'expired' => 'This setup code has expired. Make a new one on the server (see below).',
                'used_up' => 'Too many wrong codes, so this one no longer works. Make a new one on the server (see below).',
                'missing' => 'There is no setup code yet. Make one on the server (see below).',
            ][$r] ?? 'That code is not right.';
            md_audit('setup_code_failed', ['by' => null, 'detail' => $r]);
            $locked = bv_login_locked_for('setup');
        }
    } elseif ($action === 'admin' && $step === 'admin') {
        $name = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $form['username'] = $name;
        if (!md_valid_username($name)) $error = 'Usernames use 2–32 letters, numbers, dots, dashes or underscores.';
        elseif ($p = md_password_problem($pass)) $error = $p;
        elseif (!hash_equals($pass, (string)($_POST['password2'] ?? ''))) $error = 'The two passwords do not match.';
        else {
            $id = bin2hex(random_bytes(8));
            $hash = md_hash_password($pass);
            // Checked again under the lock: only ONE administrator can ever be made here
            $res = md_store_update(function (array &$d) use ($id, $name, $hash) {
                foreach ($d['users'] as $u) {
                    if (($u['role'] ?? '') === 'admin') return 'exists';
                    if (strtolower((string)$u['username']) === strtolower($name)) return 'taken';
                }
                $d['users'][$id] = md_new_user_record($id, $name, $hash, 'admin', []);
                $d['users'][$id]['used_at'] = time();
                $d['users'][$id]['last_login'] = time();
                return 'ok';
            });
            if ($res === 'exists') { header('Location: login.php'); exit; }
            if ($res === 'taken') $error = 'That username is already taken.';
            else {
                md_setup_done();
                md_audit('admin_created', ['by' => 'setup', 'user' => $name, 'detail' => 'First-time setup']);
                // Signed in as the new administrator straight away
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['md_uid'] = $id;
                $_SESSION['md_sv'] = 1;
                $_SESSION['md_user'] = $name;
                $_SESSION['bv_born'] = time();
                $_SESSION['bv_last'] = time();
                $_SESSION['setup_admin'] = $id;
                $_SESSION['setup_step'] = 'user';
                setup_go();
            }
        }
    } elseif ($action === 'user' && $step === 'user') {
        $name = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $form['username'] = $name;
        if (!md_valid_username($name)) $error = 'Usernames use 2–32 letters, numbers, dots, dashes or underscores.';
        elseif (md_find_user_by_name($name)) $error = 'That username is already taken (the administrator is a separate account).';
        elseif ($p = md_password_problem($pass)) $error = $p;
        elseif (!hash_equals($pass, (string)($_POST['password2'] ?? ''))) $error = 'The two passwords do not match.';
        else {
            $id = bin2hex(random_bytes(8));
            $quota = md_clean_quota(['mode' => 'auto', 'value' => 0]);
            $hash = md_hash_password($pass);
            md_store_update(function (array &$d) use ($id, $name, $hash, $quota) {
                $d['users'][$id] = md_new_user_record($id, $name, $hash, 'user', $quota);
                $d['users'][$id]['used_at'] = time();
            });
            try { $home = md_user_home($id); if (!is_dir($home)) @mkdir($home, 0750, true); } catch (Throwable $e) { /* created on first use */ }
            md_audit('user_created', ['by' => $_SESSION['md_user'] ?? 'setup', 'user' => $name, 'detail' => 'Member with a private drive (first-time setup)']);
            $_SESSION['setup_user'] = $name;
            $_SESSION['setup_step'] = 'done';
            setup_go();
        }
    } elseif ($action === 'skip' && $step === 'user') {
        $_SESSION['setup_step'] = 'done';
        setup_go();
    } elseif ($action === 'finish' && $step === 'done') {
        unset($_SESSION['setup_ok'], $_SESSION['setup_admin'], $_SESSION['setup_step'], $_SESSION['setup_user']);
        header('Location: admin.php');
        exit;
    } else {
        setup_go();
    }
}

$csrf = bv_csrf_token();
header('Cache-Control: no-store');
$host = gethostname() ?: 'server';
$ip = (string)($_SERVER['SERVER_ADDR'] ?? '');
if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = 'SERVER_IP';
$ipUrl = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
$v = fn(string $f) => @filemtime(__DIR__ . '/assets/' . $f) ?: time();
$steps = ['code' => 'Setup code', 'admin' => 'Administrator', 'user' => 'First user', 'done' => 'Done'];
$order = array_keys($steps);
$cur = array_search($step, $order, true);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$adminName = $_SESSION['md_user'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#0c0f13">
<title>Set up · Black Vault</title>
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
      <h2>Your own private cloud, ready in a minute.</h2>
      <p>Files for everyone at home or in the office, an admin panel and a live server monitor. Everything stays on this server.</p>
    </div>
    <div class="login-facts">
      <span><svg class="ic"><use href="#i-lock"/></svg>Local network only</span>
      <span><svg class="ic"><use href="#i-key-round"/></svg>One-time setup code</span>
      <span><svg class="ic"><use href="#i-shield-check"/></svg><?= bv_is_https() ? 'Encrypted connection' : 'Strict security headers' ?></span>
    </div>
  </section>

  <section class="login-panel">
    <div class="login-main setup-main">
      <div class="login-mobile-brand"><?php include __DIR__ . '/includes/logo.php'; ?></div>
      <span class="secure-pill"><svg class="ic" aria-hidden="true"><use href="#i-server"/></svg><?= $h($host) ?> · First-time setup</span>

      <ol class="setup-steps" aria-label="Setup steps">
        <?php foreach ($order as $i => $k): ?>
          <li class="<?= $i < $cur ? 'done' : ($i === $cur ? 'current' : '') ?>"<?= $i === $cur ? ' aria-current="step"' : '' ?>>
            <span class="setup-dot"><?php if ($i < $cur): ?><svg class="ic" aria-hidden="true"><use href="#i-check"/></svg><?php else: ?><?= $i + 1 ?><?php endif; ?></span>
            <span class="setup-label"><?= $h($steps[$k]) ?></span>
          </li>
        <?php endforeach; ?>
      </ol>

      <?php if ($step === 'code'): ?>
        <h1>Welcome to Black Vault</h1>
        <p class="login-sub">Let's get your server ready. First, prove it's yours: type the setup code shown at the end of the installation.</p>
      <?php elseif ($step === 'admin'): ?>
        <h1>Create the administrator</h1>
        <p class="login-sub">The administrator manages users, disks and security, and opens the Monitor. It has no files of its own.</p>
      <?php elseif ($step === 'user'): ?>
        <h1>Add your first user</h1>
        <p class="login-sub">Users get their own private drive for files. Create one for yourself now, or later in Admin&nbsp;→&nbsp;Accounts.</p>
      <?php else: ?>
        <h1>You're all set</h1>
        <p class="login-sub">Black Vault is ready to use.</p>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="login-error" role="alert">
          <svg class="ic" aria-hidden="true"><use href="#i-circle-alert"/></svg>
          <span><?= $h($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($step === 'code'): ?>
        <form method="post" class="login-form" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="code">
          <label class="field">
            <span>Setup code</span>
            <input class="text-input setup-code" type="text" name="code" placeholder="XXXX-XXXX-XXXX" maxlength="20" autocapitalize="characters" spellcheck="false" required autofocus>
          </label>
          <button type="submit" class="btn btn-primary"<?= $locked > 0 ? ' disabled' : '' ?>>Continue</button>
        </form>
        <div class="setup-note">
          <svg class="ic" aria-hidden="true"><use href="#i-terminal"/></svg>
          <div>Lost the code, or it expired? On the server, run:<code>sudo blackvault-admin setup-code</code></div>
        </div>

      <?php elseif ($step === 'admin'): ?>
        <form method="post" class="login-form" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="admin">
          <label class="field">
            <span>Administrator username</span>
            <input class="text-input" type="text" name="username" value="<?= $h($form['username']) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            <small class="field-hint">You can keep “admin” or choose your own name.</small>
          </label>
          <label class="field">
            <span>Password</span>
            <input class="text-input" type="password" name="password" autocomplete="new-password" required <?= $error ? 'autofocus' : '' ?>>
            <small class="field-hint">At least <?= MD_MIN_PASSWORD ?> characters, mixing letters with numbers or symbols.</small>
          </label>
          <label class="field">
            <span>Repeat the password</span>
            <input class="text-input" type="password" name="password2" autocomplete="new-password" required>
          </label>
          <button type="submit" class="btn btn-primary">Create administrator</button>
        </form>

      <?php elseif ($step === 'user'): ?>
        <form method="post" class="login-form" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="user">
          <label class="field">
            <span>Username</span>
            <input class="text-input" type="text" name="username" value="<?= $h($form['username']) ?>" placeholder="e.g. alex" autocomplete="off" autocapitalize="none" spellcheck="false" required autofocus>
          </label>
          <label class="field">
            <span>Password</span>
            <input class="text-input" type="password" name="password" autocomplete="new-password" required>
            <small class="field-hint">At least <?= MD_MIN_PASSWORD ?> characters, mixing letters with numbers or symbols.</small>
          </label>
          <label class="field">
            <span>Repeat the password</span>
            <input class="text-input" type="password" name="password2" autocomplete="new-password" required>
          </label>
          <button type="submit" class="btn btn-primary">Create user</button>
        </form>
        <form method="post" class="setup-skip">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="skip">
          <button type="submit" class="btn btn-quiet">Skip for now</button>
        </form>

      <?php else: ?>
        <ul class="setup-summary">
          <li><svg class="ic" aria-hidden="true"><use href="#i-circle-check"/></svg><span>Administrator <b><?= $h($adminName) ?></b> created. You are signed in.</span></li>
          <?php if (!empty($_SESSION['setup_user'])): ?>
            <li><svg class="ic" aria-hidden="true"><use href="#i-circle-check"/></svg><span>User <b><?= $h($_SESSION['setup_user']) ?></b> created, with a private drive.</span></li>
          <?php endif; ?>
        </ul>
        <h2 class="setup-h2">Next steps</h2>
        <ul class="setup-next">
          <li><svg class="ic" aria-hidden="true"><use href="#i-shield-check"/></svg><span><b>Remove the certificate warning.</b> On each device, download <code>http://<?= $h($ipUrl) ?>/blackvault-ca.crt</code> and trust it (the README explains how on Windows, macOS, iPhone and Android).</span></li>
          <li><svg class="ic" aria-hidden="true"><use href="#i-hard-drive"/></svg><span><b>Upload files</b> by signing in to <code>https://<?= $h($ipUrl) ?>/</code> with a user account.</span></li>
          <li><svg class="ic" aria-hidden="true"><use href="#i-activity"/></svg><span><b>Watch the server</b> at <code>https://<?= $h($ipUrl) ?>:8443/</code> with the administrator.</span></li>
        </ul>
        <form method="post" class="login-form">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="finish">
          <button type="submit" class="btn btn-primary">Open the Admin panel</button>
        </form>
      <?php endif; ?>

      <p class="login-foot">
        <svg class="ic" aria-hidden="true"><use href="#i-shield-check"/></svg>
        This page closes for good once the administrator exists
      </p>
    </div>
  </section>
</main>
</body>
</html>
