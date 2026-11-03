<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';
$me = md_require_member();


header('Cache-Control: no-store');
$disk = md_disk_usage();
$csrf = md_csrf_token();
$v = fn(string $f) => @filemtime(__DIR__ . '/assets/' . $f) ?: time();
$host = gethostname() ?: 'server';
$user = (string)$_SESSION['md_user'];
// Port where the Monitor runs (for the "Monitor" link)
$monitorPort = defined('MD_MONITOR_PORT') ? MD_MONITOR_PORT : (bv_is_https() ? 8443 : 8080);

/** Shortcut to draw an icon from the sprite */
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
<title>Drive · Black Vault</title>
<link rel="icon" href="assets/logo.svg?v=<?= $v('logo.svg') ?>" type="image/svg+xml">
<link rel="preload" href="assets/fonts/manrope-500.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/base.css?v=<?= $v('base.css') ?>">
<link rel="stylesheet" href="assets/style.css?v=<?= $v('style.css') ?>">
<script src="assets/prefs.js?v=<?= $v('prefs.js') ?>"></script>
</head>
<body>
<?php include __DIR__ . '/includes/icons.php'; ?>
<div id="app" data-csrf="<?= htmlspecialchars($csrf) ?>" data-max-mb="<?= (int)MD_MAX_UPLOAD_MB ?>" data-upload-limits="<?= htmlspecialchars(json_encode(md_upload_limits())) ?>" data-site="<?= htmlspecialchars(MD_SITE_NAME) ?>" data-trash-days="<?= (int)MD_TRASH_DAYS ?>" data-storage="<?= htmlspecialchars(json_encode($GLOBALS['MD_STORAGE_STATE'] ?? ['state' => 'ok'])) ?>">

<?php $active = 'drive'; $showSearch = true; include __DIR__ . '/includes/topbar.php'; ?>

  <main class="layout">
    <aside class="sidebar">
      <button id="btn-upload" class="btn btn-primary btn-block"><?= ic('upload') ?><span>Upload files</span></button>
      <input type="file" id="file-input" multiple hidden>
      <button id="btn-upload-folder" class="btn btn-block"><?= ic('folder-up') ?><span>Upload folder</span></button>
      <input type="file" id="folder-input" webkitdirectory directory multiple hidden>
      <button id="btn-newfolder" class="btn btn-block"><?= ic('folder-plus') ?><span>New folder</span></button>

      <nav class="side-nav" aria-label="Places">
        <a href="#/" id="nav-files" class="side-link" aria-current="page"><?= ic('house') ?><span>My files</span></a>
        <a href="#trash" id="nav-trash" class="side-link"><?= ic('trash-2') ?><span>Trash</span><span class="side-count" id="trash-count" hidden></span></a>
      </nav>
      <p class="side-label">Tip: drop files or folders anywhere in the window to upload them.</p>

      <section class="storage" aria-label="Disk space">
        <div class="storage-head">
          <?= ic('hard-drive') ?>
          <span>Your drive</span>
          <span class="storage-pct" id="disk-pct"><?= round($disk['percent']) ?>%</span>
        </div>
        <div class="meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $disk['percent'] ?>">
          <div class="meter-fill" id="disk-fill" style="width: <?= $disk['percent'] ?>%"></div>
        </div>
        <p class="storage-text" id="disk-label"><strong><?= $disk['used_human'] ?></strong> used of <?= $disk['total_human'] ?></p>
        <p class="storage-sub"><span id="disk-free"><?= $disk['free_human'] ?></span> <span id="disk-free-label"><?= $disk['limited'] ? 'left in your drive' : 'free on the disk' ?></span></p>
      </section>
    </aside>

    <section class="content" id="content">
      <div id="storage-banner" class="storage-banner" role="status" hidden></div>

      <!-- ===== Trash ===== -->
      <div id="trash-view" hidden>
        <div class="pathbar trash-bar">
          <h1 class="trash-title"><?= ic('trash-2') ?><span>Trash</span></h1>
          <button id="btn-empty-trash" class="btn btn-quiet btn-danger" disabled><?= ic('trash-2') ?><span>Empty trash</span></button>
        </div>
        <p class="trash-sub" id="trash-sub">Deleted items wait here for <?= (int)MD_TRASH_DAYS ?> days, then they are removed for good. They still count in your drive until then.</p>
        <div id="trash-list" class="trash-list" role="list"></div>
        <div id="trash-empty" class="empty-state" hidden>
          <div class="empty-icon"><?= ic('trash-2') ?></div>
          <h2>The trash is empty</h2>
          <p>When you delete something, it waits here for <?= (int)MD_TRASH_DAYS ?> days in case you change your mind.</p>
        </div>
      </div>

      <!-- ===== Files ===== -->
      <div id="files-view">
      <div class="pathbar">
        <button id="btn-back" class="icon-btn" title="Up one level" aria-label="Up one level" disabled><?= ic('chevron-left') ?></button>
        <nav class="breadcrumb" id="breadcrumb" aria-label="Current path"></nav>
        <button id="btn-download-folder" class="btn btn-quiet" title="Download this whole folder as .zip">
          <?= ic('download') ?><span id="btn-download-folder-label">Download folder</span>
        </button>
      </div>

      <div class="toolbar">
        <div class="toolbar-info" id="item-count" aria-live="polite">Loading…</div>
        <div class="segmented" role="group" aria-label="View">
          <button class="seg-btn active" data-view="list" title="List view" aria-label="List view"><?= ic('list') ?></button>
          <button class="seg-btn" data-view="grid" title="Grid view" aria-label="Grid view"><?= ic('layout-grid') ?></button>
        </div>
      </div>

      <div class="table" id="table">
        <div class="thead" id="thead">
          <label class="check" title="Select all">
            <input type="checkbox" id="check-all" aria-label="Select all">
            <span class="check-box"><?= ic('check', 'check-on') ?><?= ic('minus', 'check-mixed') ?></span>
          </label>
          <button class="th sort" data-sort="name">Name <span class="sort-ic"></span></button>
          <button class="th sort col-size" data-sort="size">Size <span class="sort-ic"></span></button>
          <button class="th sort col-date" data-sort="modified">Modified <span class="sort-ic"></span></button>
          <span class="th col-actions"></span>
        </div>
        <div id="file-list" class="file-list list-view" role="list"></div>
      </div>

      <div id="empty-state" class="empty-state" hidden>
        <div class="empty-icon"><?= ic('cloud-upload') ?></div>
        <h2>This folder is empty</h2>
        <p>Drop files or folders here, or use “Upload files”.</p>
      </div>
      <div id="no-results" class="empty-state" hidden>
        <div class="empty-icon"><?= ic('search') ?></div>
        <h2>Nothing matches “<span id="no-results-q"></span>”</h2>
        <p>Search only looks inside this folder.</p>
      </div>
      </div><!-- /files-view -->

      <!-- Multi-selection bar -->
      <div id="selection-bar" class="selection-bar" hidden>
        <button id="sel-clear" class="icon-btn" title="Clear selection" aria-label="Clear selection"><?= ic('x') ?></button>
        <span id="sel-count" class="sel-count"></span>
        <div class="sel-actions">
          <button id="sel-download" class="btn btn-primary"><?= ic('download') ?><span>Download</span></button>
          <button id="sel-move" class="btn"><?= ic('folder-input') ?><span>Move</span></button>
          <button id="sel-delete" class="btn btn-danger"><?= ic('trash-2') ?><span>Delete</span></button>
        </div>
      </div>
    </section>
  </main>

  <div id="dropzone" class="dropzone" aria-hidden="true">
    <div class="dropzone-card">
      <?= ic('cloud-upload') ?>
      <p>Drop to upload to <strong id="dropzone-target">Home</strong></p>
    </div>
  </div>

  <div id="toast-container" class="toast-container" aria-live="polite"></div>

  <div id="upload-panel" class="upload-panel" hidden>
    <div class="upload-head">
      <span class="spinner"><?= ic('loader-circle') ?></span>
      <span id="upload-title">Uploading…</span>
      <span id="upload-pct" class="upload-pct">0 %</span>
    </div>
    <div class="meter"><div class="meter-fill" id="upload-fill"></div></div>
    <p class="upload-sub" id="upload-sub"></p>
  </div>

  <!-- Menu of each item -->
  <div id="ctx-menu" class="menu" role="menu" hidden></div>

  <!-- Generic dialog (rename, new folder, confirmations) -->
  <div id="dialog" class="modal-overlay" hidden>
    <form class="modal-card dialog-card" id="dialog-form" method="dialog">
      <h3 id="dialog-title"></h3>
      <p id="dialog-text" class="dialog-text"></p>
      <input id="dialog-input" class="text-input" type="text" autocomplete="off" spellcheck="false">
      <p id="dialog-error" class="dialog-error" hidden></p>
      <div class="modal-actions">
        <button type="button" id="dialog-alt" class="btn btn-quiet dialog-alt" hidden></button>
        <button type="button" id="dialog-cancel" class="btn btn-quiet">Cancel</button>
        <button type="submit" id="dialog-ok" class="btn btn-primary">OK</button>
      </div>
    </form>
  </div>

  <!-- Mover a... -->
  <div id="move-modal" class="modal-overlay" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h3 id="move-title">Move</h3>
        <button id="move-close" class="icon-btn" title="Close" aria-label="Close"><?= ic('x') ?></button>
      </div>
      <p class="modal-hint">Choose the destination folder</p>
      <div id="move-folder-list" class="move-folder-list" role="listbox"></div>
      <div class="modal-actions">
        <button id="move-cancel" class="btn btn-quiet">Cancel</button>
        <button id="move-confirm" class="btn btn-primary" disabled>Move here</button>
      </div>
    </div>
  </div>

  <!-- Preview -->
  <div id="preview-modal" class="modal-overlay preview-overlay" hidden>
    <div class="modal-card preview-card">
      <div class="modal-head">
        <h3 id="preview-item-name">Preview</h3>
        <div class="preview-head-actions">
          <button id="preview-download" class="btn btn-primary btn-sm"><?= ic('download') ?><span>Download</span></button>
          <button id="preview-close" class="icon-btn" title="Close" aria-label="Close"><?= ic('x') ?></button>
        </div>
      </div>
      <div id="preview-body" class="preview-body"></div>
    </div>
  </div>
</div>

<script src="assets/account.js?v=<?= $v('account.js') ?>"></script>
<script src="assets/app.js?v=<?= $v('app.js') ?>"></script>
</body>
</html>
