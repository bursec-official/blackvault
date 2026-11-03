<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';
sm_require_login();
header('Cache-Control: no-store');

$v = fn(string $f) => @filemtime(__DIR__ . '/assets/' . $f) ?: time();
$host = gethostname() ?: 'server';
$drivePort = defined('SM_DRIVE_PORT') ? SM_DRIVE_PORT : (bv_is_https() ? 443 : 80);   // where the Admin panel lives

function ic(string $name, string $cls = ''): string {
    return '<svg class="ic ' . $cls . '" aria-hidden="true"><use href="#i-' . $name . '"/></svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#0c0f13">
<title>Monitor · Black Vault</title>
<link rel="icon" href="assets/logo.svg?v=<?= $v('logo.svg') ?>" type="image/svg+xml">
<link rel="preload" href="assets/fonts/manrope-500.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/base.css?v=<?= $v('base.css') ?>">
<link rel="stylesheet" href="assets/style.css?v=<?= $v('style.css') ?>">
<script src="assets/prefs.js?v=<?= $v('prefs.js') ?>"></script>
</head>
<body>
<?php include __DIR__ . '/includes/icons.php'; ?>

<header class="topbar">
  <a class="brand" href="index.php" aria-label="Black Vault Monitor">
    <?php include __DIR__ . '/includes/logo.php'; ?>
  </a>

  <nav class="suite-nav" aria-label="Server apps">
    <a href="#" id="nav-drive" data-port="<?= (int)$drivePort ?>"><?= ic('shield-check') ?><span>Admin</span></a>
    <a href="index.php" aria-current="page"><?= ic('activity') ?><span>Monitor</span></a>
  </nav>

  <div class="live" id="live" title="Connection status">
    <span class="led" id="live-led"></span>
    <span id="live-text">Connecting…</span>
  </div>

  <div class="topbar-actions">
    <button id="btn-pause" class="icon-btn" title="Pause updates" aria-label="Pause updates" aria-pressed="false">
      <svg class="ic" aria-hidden="true" viewBox="0 0 24 24"><path id="pause-path" d="M9 5v14M15 5v14"/></svg>
    </button>
    <span class="secure-pill" title="<?= bv_is_https() ? 'Encrypted connection (HTTPS). ' : '' ?>Only devices on your local network or VPN can reach this server"><?= ic('shield-check') ?><?= bv_is_https() ? 'Encrypted' : 'Secure session' ?> · LAN only</span>
    <button class="icon-btn" data-open-prefs title="Appearance" aria-label="Appearance"><?= ic('palette') ?></button>
    <span class="user-chip" title="Signed in"><span class="avatar"><?= ic('user') ?></span><span><?= htmlspecialchars($_SESSION['sm_user']) ?></span></span>
    <form method="post" action="logout.php" class="inline-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(bv_csrf_token()) ?>">
      <button type="submit" class="icon-btn" title="Sign out" aria-label="Sign out"><?= ic('log-out') ?></button>
    </form>
  </div>
</header>

<div class="sysline" id="sysline">
  <span><?= ic('server') ?><b id="s-host"><?= htmlspecialchars($host) ?></b></span>
  <span id="s-os">—</span>
  <span id="s-kernel">—</span>
  <span id="s-ip">—</span>
  <span><?= ic('clock') ?>Uptime <b id="s-uptime">—</b></span>
  <span><b id="s-procs">—</b> processes</span>
</div>

<main class="dash">

  <!-- CPU -->
  <section class="panel p-cpu">
    <header class="panel-head">
      <h2><?= ic('cpu') ?>Processor</h2>
      <span class="panel-meta" id="cpu-model">—</span>
    </header>
    <div class="cpu-body">
      <div class="big">
        <div class="big-num" id="cpu-pct" data-level="ok">0<small>%</small></div>
        <dl class="kv">
          <div><dt>Load 1 m</dt><dd id="load-1">—</dd></div>
          <div><dt>5 m</dt><dd id="load-5">—</dd></div>
          <div><dt>15 m</dt><dd id="load-15">—</dd></div>
          <div><dt>I/O wait</dt><dd id="iowait">—</dd></div>
        </dl>
      </div>
      <div class="chart-wrap">
        <canvas class="chart" id="chart-cpu" aria-label="CPU usage history (last 2 minutes)"></canvas>
        <div class="chart-legend"><span>-2 min</span><span>now</span></div>
      </div>
    </div>
    <div class="cores" id="cores" aria-label="Usage per core"></div>
  </section>

  <!-- Memoria -->
  <section class="panel p-mem">
    <header class="panel-head">
      <h2><?= ic('memory-stick') ?>Memory</h2>
      <span class="panel-meta" id="mem-total">—</span>
    </header>
    <div class="big-num" id="mem-pct" data-level="ok">0<small>%</small></div>
    <div class="stack" aria-hidden="true">
      <span class="stack-used" id="mem-bar-used"></span><span class="stack-cache" id="mem-bar-cache"></span>
    </div>
    <ul class="legend">
      <li><i class="sw sw-used"></i>In use<b id="mem-used">—</b></li>
      <li><i class="sw sw-cache"></i>Cache<b id="mem-cache">—</b></li>
      <li><i class="sw sw-free"></i>Available<b id="mem-free">—</b></li>
    </ul>
    <div class="subbar">
      <div class="subbar-head"><span>Swap</span><b id="swap-text">—</b></div>
      <div class="meter"><div class="meter-fill" id="swap-bar"></div></div>
    </div>
  </section>

  <!-- Red -->
  <section class="panel p-net">
    <header class="panel-head">
      <h2><?= ic('network') ?>Network</h2>
      <span class="panel-meta" id="net-iface">—</span>
    </header>
    <div class="rates">
      <div><span class="rate-lbl"><?= ic('arrow-down') ?>Download</span><span class="rate-val glow" id="net-rx">—</span></div>
      <div><span class="rate-lbl"><?= ic('arrow-up') ?>Upload</span><span class="rate-val info" id="net-tx">—</span></div>
    </div>
    <canvas class="chart chart-sm" id="chart-net" aria-label="Network history"></canvas>
    <ul class="ifaces" id="ifaces"></ul>
  </section>

  <!-- Disco -->
  <section class="panel p-disk">
    <header class="panel-head">
      <h2><?= ic('hard-drive') ?>Disk</h2>
      <span class="panel-meta" id="disk-devs">—</span>
    </header>
    <div class="big-num" id="disk-pct" data-level="ok">0<small>%</small></div>
    <div class="meter meter-lg"><div class="meter-fill" id="disk-bar"></div></div>
    <p class="muted-line"><b id="disk-used">—</b> used of <span id="disk-total">—</span>, <b id="disk-free">—</b> free</p>
    <div class="rates rates-sm">
      <div><span class="rate-lbl"><?= ic('hard-drive-download') ?>Read</span><span class="rate-val glow" id="io-read">—</span></div>
      <div><span class="rate-lbl"><?= ic('pencil') ?>Write</span><span class="rate-val info" id="io-write">—</span></div>
    </div>
    <canvas class="chart chart-xs" id="chart-io" aria-label="Disk read and write history"></canvas>
  </section>

  <!-- Temperatura -->
  <section class="panel p-temp">
    <header class="panel-head">
      <h2><?= ic('thermometer') ?>Temperature</h2>
      <span class="panel-meta" id="temp-source">—</span>
    </header>
    <div id="temp-has">
      <div class="big-num" id="temp-main" data-level="ok">—<small>°C</small></div>
      <div class="thermo" aria-hidden="true"><div class="thermo-fill" id="temp-bar"></div><span class="thermo-mark" style="left:75%"></span><span class="thermo-mark crit" style="left:90%"></span></div>
      <div class="thermo-scale"><span>30°</span><span>75°</span><span>90°</span></div>
      <ul class="sensors" id="sensors"></ul>
    </div>
    <div id="temp-none" class="none" hidden>
      <p>No temperature sensors available.</p>
      <p class="dim">On some machines they only appear after loading the sensor module (<code>k10temp</code> instead of <code>coretemp</code> on AMD):</p>
      <pre>sudo modprobe coretemp</pre>
    </div>
  </section>

  <!-- Disk health -->
  <section class="panel p-health">
    <header class="panel-head">
      <h2><?= ic('hard-drive') ?>Disk health</h2>
      <span class="panel-meta"><span id="health-meta">—</span>
        <button type="button" class="btn btn-sm btn-quiet" id="btn-health-now" title="Ask every disk again (sleeping disks are not woken up)"><?= ic('refresh-cw') ?><span>Check now</span></button></span>
    </header>
    <div class="hdisks" id="hdisks"><p class="dim">Looking at the disks…</p></div>
  </section>

  <!-- Procesos -->
  <section class="panel p-procs">
    <header class="panel-head">
      <h2><?= ic('list') ?>Top processes</h2>
      <span class="panel-meta" id="procs-meta">—</span>
    </header>
    <div class="ptable" role="table" aria-label="Processes using the most CPU">
      <div class="prow phead" role="row"><span role="columnheader">PID</span><span role="columnheader">User</span><span role="columnheader">Process</span><span role="columnheader" class="r">CPU</span><span role="columnheader" class="r">Memory</span></div>
      <div id="procs"></div>
    </div>
  </section>

  <!-- Registro -->
  <section class="panel p-log">
    <header class="panel-head">
      <h2><?= ic('activity') ?>Events</h2>
      <span class="panel-meta">This session</span>
    </header>
    <div class="log" id="log" aria-live="polite"></div>
  </section>

</main>

<script src="assets/app.js?v=<?= $v('app.js') ?>"></script>
</body>
</html>
