<?php
/**
 * Black Vault Monitor — settings
 *
 * The Monitor uses the Black Vault accounts: only ADMINISTRATORS can open it.
 * The installer copies this file the first time only and never overwrites it.
 */

// Folder of the Drive app, where the accounts live (default: next to this one)
// define('SM_DRIVE_DIR', '/var/www/minidrive');

// --- Where to keep state between readings ---
define('SM_STATE_FILE', __DIR__ . '/state/state.json');

// --- Name shown in the interface ---
define('SM_SITE_NAME', 'Black Vault // Monitor');

// --- Time zone --- (the installer sets the server's own time zone here)
date_default_timezone_set('UTC');

// --- Who can reach the Monitor ---
// By default only private networks (home/office, localhost, Tailscale/WireGuard).
// To choose the networks yourself, uncomment and edit:
// define('BV_ALLOWED_NETS', ['127.0.0.0/8', '192.168.1.0/24', '100.64.0.0/10']);
// To switch this protection off (NOT recommended):
// define('BV_ALLOW_ANY_NETWORK', true);

// Port of the Drive / Admin panel (for the "Admin" link in the top bar).
// Default: 443 over HTTPS, 80 over plain HTTP.
// define('SM_DRIVE_PORT', 443);
