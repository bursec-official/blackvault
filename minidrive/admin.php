<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';
$me = md_require_admin();
header('Cache-Control: no-store');

$csrf = md_csrf_token();
$v = fn(string $f) => @filemtime(__DIR__ . '/assets/' . $f) ?: time();
$monitorPort = defined('MD_MONITOR_PORT') ? MD_MONITOR_PORT : (bv_is_https() ? 8443 : 8080);

function ic(string $name, string $cls = ''): string {
    return '<svg class="ic ' . $cls . '" aria-hidden="true"><use href="#i-' . $name . '"/></svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#0c0f13">
<title>Admin · Black Vault</title>
<link rel="icon" href="assets/logo.svg?v=<?= $v('logo.svg') ?>" type="image/svg+xml">
<link rel="preload" href="assets/fonts/manrope-500.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/base.css?v=<?= $v('base.css') ?>">
<link rel="stylesheet" href="assets/style.css?v=<?= $v('style.css') ?>">
<link rel="stylesheet" href="assets/admin.css?v=<?= $v('admin.css') ?>">
<script src="assets/prefs.js?v=<?= $v('prefs.js') ?>"></script>
</head>
<body>
<?php include __DIR__ . '/includes/icons.php'; ?>
<div id="admin" data-csrf="<?= htmlspecialchars($csrf) ?>" data-me="<?= htmlspecialchars($me['id']) ?>">

<?php $active = 'admin'; $showSearch = false; include __DIR__ . '/includes/topbar.php'; ?>

<main class="admin">
  <header class="admin-head">
    <div>
      <h1>Administration</h1>
      <p>Who can use Black Vault, where the files live and how sign-in is protected.</p>
    </div>
    <div class="admin-head-actions">
      <button class="btn btn-primary" id="btn-add"><?= ic('user') ?><span>Add user</span></button>
    </div>
  </header>

  <div id="alerts" class="alerts" aria-live="polite"></div>

  <nav class="tabs" role="tablist" aria-label="Administration sections">
    <button role="tab" id="tab-accounts" data-tab="accounts" aria-controls="panel-accounts"><?= ic('user') ?><span>Accounts</span><i class="tab-dot" hidden></i></button>
    <button role="tab" id="tab-storage" data-tab="storage" aria-controls="panel-storage"><?= ic('hard-drive') ?><span>Storage &amp; disks</span><i class="tab-dot" hidden></i></button>
    <button role="tab" id="tab-security" data-tab="security" aria-controls="panel-security"><?= ic('shield-check') ?><span>Security</span></button>
    <button role="tab" id="tab-activity" data-tab="activity" aria-controls="panel-activity"><?= ic('activity') ?><span>Activity</span></button>
  </nav>

  <!-- ============ Accounts ============ -->
  <section class="tab-panel" id="panel-accounts" role="tabpanel" aria-labelledby="tab-accounts">
    <section class="card users-card">
      <div class="card-head">
        <h2>Accounts</h2>
        <span class="card-meta" id="users-meta"></span>
      </div>
      <div class="utable" role="table" aria-label="Accounts">
        <div class="urow uhead" role="row">
          <span role="columnheader">User</span>
          <span role="columnheader">Storage</span>
          <span role="columnheader">Usage</span>
          <span role="columnheader">Last sign-in</span>
          <span role="columnheader">Status</span>
          <span role="columnheader"></span>
        </div>
        <div id="users"><div class="urow-empty">Loading accounts…</div></div>
      </div>
    </section>
  </section>

  <!-- ============ Storage & disks ============ -->
  <section class="tab-panel" id="panel-storage" role="tabpanel" aria-labelledby="tab-storage" hidden>
    <section class="stats stats-3" aria-label="Storage summary">
      <div class="stat">
        <span class="stat-label">Drives live on</span>
        <b id="st-disk">—</b>
        <small id="st-disk-sub">—</small>
      </div>
      <div class="stat">
        <span class="stat-label">Available for drives</span>
        <b id="st-pool">—</b>
        <small id="st-pool-sub">—</small>
      </div>
      <div class="stat">
        <span class="stat-label">Assigned</span>
        <b id="st-assigned">—</b>
        <div class="meter"><div class="meter-fill" id="st-assigned-bar"></div></div>
        <small id="st-assigned-sub">—</small>
      </div>
    </section>

    <section class="card disks-card">
      <div class="card-head">
        <div>
          <h2>Disks</h2>
          <p class="card-text small" id="disks-sub">Plug a disk into the server and it shows up here.</p>
        </div>
        <div class="card-head-actions">
          <button class="btn btn-sm btn-quiet" id="btn-health"><?= ic('activity') ?><span>Check health now</span></button>
          <button class="btn btn-sm" id="btn-disks-refresh"><?= ic('refresh-cw') ?><span>Look for disks</span></button>
        </div>
      </div>
      <div id="disks" class="disks"><div class="urow-empty">Looking for disks…</div></div>
    </section>

    <div class="admin-grid">
      <section class="card">
        <div class="card-head"><h2>Space kept for the system</h2></div>
        <p class="card-text">Drives on <b>Equal share</b> split whatever the other quotas leave free, and are recalculated automatically when you add or remove people. Keep some space free so the disk never fills up completely.</p>
        <form id="settings-form" class="settings-form">
          <div class="prefs-label">Keep free <span class="prefs-val" id="reserve-val"></span></div>
          <input type="range" id="reserve" min="0" max="50" step="1" aria-label="Space kept free for the system">
          <p class="card-text small" id="reserve-hint"></p>
          <div class="settings-actions"><button type="submit" class="btn" id="settings-save" disabled>Save</button></div>
        </form>
      </section>
      <section class="card">
        <div class="card-head"><h2>How moving to a new disk works</h2></div>
        <ol class="steps">
          <li><b>Plug the disk in</b> and press “Look for disks”.</li>
          <li><b>Prepare it.</b> Black Vault erases it and sets it up. Your password is needed.</li>
          <li><b>Move the drives.</b> Everything is copied and checked, then Black Vault switches over. Files are read-only while it copies.</li>
          <li><b>Remove the old copy</b> when you are happy, to free the space.</li>
        </ol>
        <p class="card-text small">If the disk with the drives is ever unplugged, the drives simply go offline until it is back. Nothing is ever saved somewhere else by mistake.</p>
      </section>
    </div>
  </section>

  <!-- ============ Security ============ -->
  <section class="tab-panel" id="panel-security" role="tabpanel" aria-labelledby="tab-security" hidden>
    <div class="admin-grid">
      <section class="card">
        <div class="card-head"><h2>Lock after wrong passwords</h2></div>
        <p class="card-text">When someone types a wrong password this many times in a row, their account is locked and signed out everywhere. Only an administrator can unlock it.</p>
        <form id="security-form" class="security-form">
          <div class="stepper" role="group" aria-label="Wrong passwords before locking">
            <button type="button" class="icon-btn" data-step="-1" aria-label="Fewer attempts"><?= ic('minus') ?></button>
            <output id="fails-val" aria-live="polite">3</output>
            <button type="button" class="icon-btn" data-step="1" aria-label="More attempts"><?= ic('plus') ?></button>
            <span class="stepper-label">wrong passwords</span>
          </div>
          <p class="card-text small" id="fails-hint"></p>
          <div class="settings-actions"><button type="submit" class="btn" id="security-save" disabled>Save</button></div>
        </form>
      </section>
      <section class="card">
        <div class="card-head"><h2>How sign-in is protected</h2></div>
        <ul class="facts">
          <li><?= ic('lock') ?><span><b>Administrators are never locked</b> by wrong passwords, so you can always get in. Lock them by hand if needed.</span></li>
          <li><?= ic('clock') ?><span><b>Any device</b> that gets the password wrong 5 times waits 15 minutes before trying again.</span></li>
          <li><?= ic('shield-check') ?><span><b>Made-up usernames</b> behave exactly like real ones, so nobody can find out who has an account.</span></li>
          <li><?= ic('key-round') ?><span><b>Everything travels encrypted</b> (HTTPS) and only devices on your network can reach the server.</span></li>
          <li><?= ic('terminal') ?><span><b>Locked out?</b> On the server: <code>sudo blackvault-admin unlock NAME</code> or <code>sudo blackvault-admin unblock-ips</code>.</span></li>
        </ul>
      </section>
    </div>
  </section>

  <!-- ============ Activity ============ -->
  <section class="tab-panel" id="panel-activity" role="tabpanel" aria-labelledby="tab-activity" hidden>
    <section class="card">
      <div class="card-head"><h2>Recent activity</h2><span class="card-meta">Sign-ins, changes and disk operations</span></div>
      <div class="activity activity-full" id="activity"></div>
    </section>
  </section>
</main>

<div id="toast-container" class="toast-container" aria-live="polite"></div>
</div>

<script src="assets/account.js?v=<?= $v('account.js') ?>"></script>
<script src="assets/admin.js?v=<?= $v('admin.js') ?>"></script>
</body>
</html>
