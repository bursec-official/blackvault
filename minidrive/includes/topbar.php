<?php
/**
 * Shared top bar for Drive and Admin.
 * Expects: $me (account), $active ('drive' | 'admin'), $monitorPort, optional $showSearch.
 * Members only see their Drive; administrators only see Admin and Monitor.
 */
$isAdmin = ($me['role'] ?? '') === 'admin';
?>
  <header class="topbar">
    <a class="brand" href="<?= $isAdmin ? 'admin.php' : 'index.php' ?>" aria-label="Black Vault, go to home">
      <?php include __DIR__ . '/logo.php'; ?>
    </a>

    <nav class="suite-nav" aria-label="Sections">
      <?php if ($isAdmin): ?>
      <a href="admin.php"<?= $active === 'admin' ? ' aria-current="page"' : '' ?>><?= ic('shield-check') ?><span>Admin</span></a>
      <a href="#" id="nav-monitor" data-port="<?= (int)$monitorPort ?>"><?= ic('activity') ?><span>Monitor</span></a>
      <?php else: ?>
      <a href="index.php"<?= $active === 'drive' ? ' aria-current="page"' : '' ?>><?= ic('hard-drive') ?><span>Drive</span></a>
      <?php endif; ?>
    </nav>

    <?php if (!empty($showSearch)): ?>
    <label class="search">
      <?= ic('search') ?>
      <input type="search" id="search" placeholder="Search this folder" autocomplete="off" aria-label="Search this folder">
      <kbd class="search-kbd">/</kbd>
    </label>
    <?php endif; ?>

    <div class="topbar-actions">
      <span class="secure-pill" title="<?= bv_is_https() ? 'Encrypted connection (HTTPS). ' : '' ?>Only devices on your local network or VPN can reach this server"><?= ic('shield-check') ?><?= bv_is_https() ? 'Encrypted' : 'Secure session' ?> · LAN only</span>
      <button class="icon-btn" data-open-prefs title="Appearance" aria-label="Appearance"><?= ic('palette') ?></button>
      <button type="button" class="user-chip" id="account-btn" aria-haspopup="menu" aria-expanded="false"
              data-name="<?= htmlspecialchars($me['username']) ?>" data-role="<?= $isAdmin ? 'admin' : 'user' ?>" data-page="<?= htmlspecialchars($active) ?>">
        <span class="avatar"><?= ic('user') ?></span><span><?= htmlspecialchars($me['username']) ?></span>
      </button>
      <form method="post" action="logout.php" class="inline-form" id="logout-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(bv_csrf_token()) ?>">
        <button type="submit" class="icon-btn" title="Sign out" aria-label="Sign out"><?= ic('log-out') ?></button>
      </form>
    </div>
  </header>
