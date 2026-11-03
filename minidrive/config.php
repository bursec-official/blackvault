<?php
/**
 * Black Vault Drive — settings
 *
 * The installer copies this file to /var/www/minidrive/config.php the first
 * time only, and never overwrites it on updates. Accounts are NOT set here:
 * the first-time setup page creates the administrator, and the Admin panel
 * manages everyone else.
 */

// --- Storage ---
// Folder on the server's own disk where the drives live. You can move the
// drives to another disk later from Admin → Storage & disks.
define('MD_STORAGE_ROOT', __DIR__ . '/storage');

// --- Limits ---
define('MD_MAX_UPLOAD_MB', 20480); // 20 GB — largest single file

// --- Name used for "Download all" zips ---
define('MD_SITE_NAME', 'Black Vault');

// --- Time zone --- (the installer sets the server's own time zone here)
date_default_timezone_set('UTC');

// --- Who can reach the Drive ---
// By default only private networks can sign in: home/office networks,
// localhost and VPNs like Tailscale or WireGuard. Even if a router port is
// opened by mistake, the rest of the internet gets "Access denied".
// To choose the networks yourself, uncomment and edit:
// define('BV_ALLOWED_NETS', ['127.0.0.0/8', '192.168.1.0/24', '100.64.0.0/10']);
// To switch this protection off (NOT recommended):
// define('BV_ALLOW_ANY_NETWORK', true);

// Port of the Monitor (for the "Monitor" link in the top bar).
// Default: 8443 over HTTPS, 8080 over plain HTTP.
// define('MD_MONITOR_PORT', 8443);
