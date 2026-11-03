#!/usr/bin/env bash
# =====================================================================
#  Black Vault (Drive + Admin + Monitor) — installer / updater
#  For Ubuntu Server. Safe to run again: it updates without touching your files.
#
#  Usage (on the server, from the downloaded folder):
#      sudo bash install.sh
#
#  What it does:
#   1. Installs Apache + PHP if needed and applies PHP's security updates.
#   2. Backs up the current code of both apps.
#   3. Installs MiniDrive (/var/www/minidrive) and SysMonitor (/var/www/sysmonitor)
#      WITHOUT touching: storage/ (your files), config.php (user/password)
#      and state/ (login attempt records).
#   4. Hardens Apache (no version leaks, no TRACE, storage/state/includes locked).
#   5. HTTPS: creates a private certificate authority limited to this server,
#      a certificate that renews itself, and Apache sites on 443 (Drive) and
#      8443 (Monitor). Plain HTTP on 80/8080 only redirects to HTTPS.
#      If Apache refuses the new setup, the previous one is put back.
#   6. Disks: a small root-only service (socket for www-data only) so the
#      Admin panel can prepare, connect, eject disks and move the drives,
#      and the Monitor can show disk health. Installs smartmontools.
#   7. Accounts: on a fresh install, prints a one-time setup code for the
#      first-time setup page in the browser (creates the administrator).
#      Installs the rescue console:  sudo blackvault-admin
#   8. Restarts Apache and ATTACKS its own install to check that
#      config.php, storage/, state/ and includes/ cannot be read from outside.
# =====================================================================
set -Eeuo pipefail

WWW="${BV_WWW:-/var/www}"
BACKUP_DIR="${BV_BACKUP_DIR:-/var/backups/blackvault}"
TEST_MODE="${BV_TEST_MODE:-0}"          # internal testing only: skips apt and Apache
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
WEBUSER="www-data"

if [[ -t 1 ]]; then B=$'\e[1m'; G=$'\e[32m'; Y=$'\e[33m'; R=$'\e[31m'; N=$'\e[0m'; else B=""; G=""; Y=""; R=""; N=""; fi
step() { printf '\n%s==> %s%s\n' "$B" "$*" "$N"; }
ok()   { printf '  %s✔%s %s\n' "$G" "$N" "$*"; }
warn() { printf '  %s!%s %s\n' "$Y" "$N" "$*"; WARNED=1; }
die()  { printf '\n%s✘ %s%s\n' "$R" "$*" "$N" >&2; exit 1; }
WARNED=0
APACHE_BK=""            # snapshot of /etc/apache2 taken right before the HTTPS changes
APACHE_ROLLBACK=0       # 1 while the new Apache setup has not been proven to start
restore_apache() {
    (( APACHE_ROLLBACK )) && [[ -f "$APACHE_BK" ]] || return 0
    tar -czf "${APACHE_BK%.tar.gz}-failed.tar.gz" -C /etc apache2 2>/dev/null || true   # kept for diagnosis
    rm -rf /etc/apache2.bv-new && mv /etc/apache2 /etc/apache2.bv-new
    if tar -xzf "$APACHE_BK" -C /etc; then rm -rf /etc/apache2.bv-new; else mv /etc/apache2.bv-new /etc/apache2; fi
    APACHE_ROLLBACK=0
}
on_error() {
    if (( APACHE_ROLLBACK )); then
        restore_apache
        systemctl restart apache2 >/dev/null 2>&1 || true
        printf '  (Apache configuration put back exactly as it was before)\n' >&2
    fi
    die "Something failed at line $1. Your files (storage/) and passwords (config.php) were not touched. Copy the message above if you need help."
}
trap 'on_error $LINENO' ERR

[[ $EUID -eq 0 ]] || die "Run it with sudo:  sudo bash $0"
for app in minidrive sysmonitor; do
    [[ -f "$SRC/$app/index.php" ]] || die "Cannot find the $app folder next to install.sh ($SRC)."
done
[[ -f "$SRC/tools/blackvault-tls" && -f "$SRC/tools/blackvault-disk" ]] || die "Cannot find the tools/ folder next to install.sh ($SRC)."
command -v openssl >/dev/null 2>&1 || { [[ "$TEST_MODE" == "1" ]] || { apt-get install -y -qq openssl >/dev/null 2>&1 || die "openssl is needed for HTTPS: sudo apt install openssl"; }; }
[[ "$SRC" != "$WWW"* ]] || die "Do not run the installer from inside $WWW; unzip in your home folder."

VERSION="$(tr -dc '0-9A-Za-z.+-' < "$SRC/VERSION" 2>/dev/null || true)"
printf '%sBlack Vault %s · installer%s  (from: %s → to: %s)\n' "$B" "${VERSION:-dev}" "$N" "$SRC" "$WWW"

# ---------------------------------------------------------------------
step "1/8  Apache and PHP (install / security updates)"
# ---------------------------------------------------------------------
if [[ "$TEST_MODE" == "1" ]]; then
    ok "(test mode: skipped)"
else
    # A fresh Ubuntu Server has none of these: install whatever is missing
    NEED=()
    for pkg in apache2 php-cli libapache2-mod-php php-mbstring openssl; do
        dpkg-query -W -f='${db:Status-Abbrev}' "$pkg" 2>/dev/null | grep -q '^ii' || NEED+=("$pkg")
    done
    if (( ${#NEED[@]} )); then
        printf '  Installing: %s …\n' "${NEED[*]}"
        apt-get update -qq || die "apt update failed. Check the internet connection and try again."
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${NEED[@]}" >/dev/null || die "Could not install ${NEED[*]}. Try:  sudo apt install ${NEED[*]}"
        ok "Installed: ${NEED[*]}"
    fi
    mapfile -t PHP_PKGS < <(dpkg-query -W -f='${Package} ${db:Status-Abbrev}\n' 'php*' 'libapache2-mod-php*' 2>/dev/null | awk '$2 ~ /^ii/ {print $1}' || true)
    if (( ${#PHP_PKGS[@]} == 0 )); then
        die "PHP is not installed. Try:  sudo apt install apache2 php-cli libapache2-mod-php php-mbstring"
    else
        if apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --only-upgrade "${PHP_PKGS[@]}"; then
            ok "PHP packages up to date (${#PHP_PKGS[@]} packages checked)"
        else
            warn "Could not update PHP now (no internet?). Retry later: sudo apt update && sudo apt upgrade"
        fi
    fi

    # Minimum patched version for your Ubuntu (Canonical advisory)
    CODENAME="$(. /etc/os-release && echo "${VERSION_CODENAME:-}")"
    case "$CODENAME" in
        resolute) PKG=php8.5-common; FIXED=8.5.4-0ubuntu1.5 ;;
        noble)    PKG=php8.3-common; FIXED=8.3.6-0ubuntu0.24.04.13 ;;
        jammy)    PKG=php8.1-common; FIXED=8.1.2-1ubuntu2.28 ;;
        *)        PKG=""; FIXED="" ;;
    esac
    if [[ -n "$PKG" ]] && CUR="$(dpkg-query -W -f='${Version}' "$PKG" 2>/dev/null)" && [[ -n "$CUR" ]]; then
        if dpkg --compare-versions "$CUR" ge "$FIXED"; then
            ok "PHP protected against PHP2Shell: $PKG $CUR (minimum $FIXED)"
        else
            warn "PHP is STILL VULNERABLE: $PKG $CUR (need $FIXED or newer). Try: sudo apt update && sudo apt upgrade"
        fi
    else
        ok "PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null) (keep it updated: sudo apt update && sudo apt upgrade)"
    fi
fi

# ---------------------------------------------------------------------
step "2/8  Backing up the current code"
# ---------------------------------------------------------------------
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
TOBACKUP=()
for app in minidrive sysmonitor; do [[ -d "$WWW/$app" ]] && TOBACKUP+=("$app"); done
if (( ${#TOBACKUP[@]} )); then
    tar -czf "$BACKUP_DIR/code-$STAMP.tar.gz" -C "$WWW" --exclude='minidrive/storage' "${TOBACKUP[@]}"
    chmod 600 "$BACKUP_DIR/code-$STAMP.tar.gz"
    ok "Saved to $BACKUP_DIR/code-$STAMP.tar.gz (without storage/; your files are untouched)"
else
    ok "Fresh install: nothing to back up"
fi

# ---------------------------------------------------------------------
step "3/8  Installing the new code"
# ---------------------------------------------------------------------
install_app() {
    local app="$1" dest="$WWW/$1"
    mkdir -p "$dest"
    # Removes only the old code: NEVER storage/, state/ or config.php
    find "$dest" -mindepth 1 -maxdepth 1 ! -name storage ! -name state ! -name config.php -exec rm -rf {} +
    tar -C "$SRC/$app" \
        --exclude=./storage --exclude=./state --exclude=./config.php \
        --exclude='._*' --exclude='.DS_Store' --exclude='__MACOSX' \
        -cf - . | tar -C "$dest" -xf -
    if [[ -f "$dest/config.php" && "$app" == "sysmonitor" ]]; then
        # The Monitor now signs in with the Black Vault administrator accounts:
        # its old separate username / password hash are no longer used, so they go.
        sed -i "/define('SM_USERNAME'/d; /define('SM_PASSWORD_HASH'/d" "$dest/config.php"
        ok "$app: config.php kept (sign-in now uses the administrator accounts)"
    elif [[ -f "$dest/config.php" ]]; then
        ok "$app: config.php kept (your settings are unchanged)"
    else
        install -m 640 "$SRC/$app/config.php" "$dest/config.php"
        # Use the server's own time zone (e.g. Europe/London) instead of UTC
        TZNAME="$(timedatectl show -p Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null || true)"
        if [[ "$TZNAME" =~ ^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$ ]]; then
            sed -i "s|date_default_timezone_set('UTC');|date_default_timezone_set('$TZNAME');|" "$dest/config.php"
        fi
        ok "$app: config.php installed (time zone: ${TZNAME:-UTC})"
    fi
    mkdir -p "$dest/state"
    printf 'Require all denied\n' > "$dest/state/.htaccess"
    if [[ "$app" == "minidrive" ]]; then
        mkdir -p "$dest/storage"
        install -m 644 "$SRC/minidrive/storage/.htaccess" "$dest/storage/.htaccess"   # protection always up to date
    fi
    ok "$app installed in $dest"
}
install_app minidrive
install_app sysmonitor

# ---------------------------------------------------------------------
step "4/8  Permissions and Apache hardening"
# ---------------------------------------------------------------------
if [[ "$TEST_MODE" == "1" ]]; then WEBUSER="$(id -un)"; fi
for app in minidrive sysmonitor; do
    d="$WWW/$app"
    chown -R "$WEBUSER:$WEBUSER" "$d"
    find "$d" -path "$d/storage" -prune -o -type d -exec chmod 755 {} +
    find "$d" -path "$d/storage" -prune -o -type f -exec chmod 644 {} +
    chmod 640 "$d/config.php"
    chmod 750 "$d/state"; chmod 640 "$d/state/"* 2>/dev/null || true
done
chmod 750 "$WWW/minidrive/storage"
ok "Owner $WEBUSER; config.php and state/ closed to other system users"

HOST_NAME="$(hostname -s 2>/dev/null | tr 'A-Z' 'a-z')"; [[ "$HOST_NAME" =~ ^[a-z0-9][a-z0-9-]*$ ]] || HOST_NAME=blackvault
# "zz-" so it loads after Ubuntu's own security.conf (which says ServerTokens OS)
CONF=/etc/apache2/conf-available/zz-blackvault-security.conf
if [[ "$TEST_MODE" != "1" ]]; then
if [[ -e /etc/apache2/conf-enabled/blackvault-security.conf ]]; then a2disconf -q blackvault-security >/dev/null 2>&1 || true; fi
rm -f /etc/apache2/conf-available/blackvault-security.conf
cat > "$CONF" <<CONF_EOF
# BlackVault — Apache hardening (generated by install.sh)
ServerName $HOST_NAME
ServerTokens Prod
ServerSignature Off
TraceEnable Off
FileETag MTime Size

<IfModule mod_headers.c>
    Header always unset X-Powered-By
    Header unset X-Powered-By
</IfModule>

# Stored files are NEVER served directly or executed,
# even if someone deleted the .htaccess inside.
<Directory $WWW/minidrive/storage>
    AllowOverride None
    Require all denied
    <IfModule mod_php.c>
        php_admin_flag engine off
    </IfModule>
    <IfModule mod_php8.c>
        php_admin_flag engine off
    </IfModule>
</Directory>

# Internal code and attempt logs: closed to the browser.
<DirectoryMatch "^$WWW/(minidrive|sysmonitor)/(state|includes)(/|\$)">
    Require all denied
</DirectoryMatch>
<FilesMatch "^(config\.php|install\.sh|README\.md|\.ht.*)\$">
    Require all denied
</FilesMatch>
CONF_EOF
    # SysMonitor needs the full /proc. Ubuntu 26.04 starts Apache with
    # ProcSubset=pid / ProtectProc=invisible, which hides CPU, memory and network.
    mkdir -p /etc/systemd/system/apache2.service.d
    printf '[Service]\nProtectProc=default\nProcSubset=all\n' > /etc/systemd/system/apache2.service.d/blackvault-sysmonitor.conf
    systemctl daemon-reload
    ok "Apache can read /proc (needed by SysMonitor)"
    # PHP limits for big uploads and whole folders, at server level so they hold
    # whether PHP runs as mod_php or PHP-FPM (the .htaccess only covers mod_php)
    PHPINI=0
    for d in /etc/php/*/apache2/conf.d /etc/php/*/fpm/conf.d; do
        [[ -d "$d" ]] || continue
        printf '; Black Vault (install.sh): big uploads and whole folders\nfile_uploads = On\nupload_max_filesize = 20G\npost_max_size = 20G\nmax_file_uploads = 200\nmax_execution_time = 3600\nmax_input_time = 3600\n' > "$d/99-blackvault.ini"
        PHPINI=1
    done
    (( PHPINI )) && ok "PHP accepts uploads of up to 20 GB and whole folders" || warn "Could not find PHP's configuration folder (/etc/php/*/apache2/conf.d)."
    for svc in $(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do systemctl restart "$svc" >/dev/null 2>&1 || true; done
    a2enmod -q headers >/dev/null 2>&1 || true
    a2enconf -q zz-blackvault-security >/dev/null
    ok "Apache hardened ($CONF)"
else
    ok "(test mode: Apache configuration skipped)"
fi

# ---------------------------------------------------------------------
step "5/8  HTTPS (everything encrypted)"
# ---------------------------------------------------------------------
TLS_DIR=/etc/blackvault/tls
PUB_DIR="$WWW/blackvault-public"
if [[ "$TEST_MODE" == "1" ]]; then
    ok "(test mode: skipped)"
else
    # Certificate tool, with this install's public folder baked in
    sed "s#^PUB_DIR=.*#PUB_DIR=\"\${BV_PUBLIC_DIR:-$PUB_DIR}\"#" "$SRC/tools/blackvault-tls" > /usr/local/sbin/blackvault-tls
    chmod 755 /usr/local/sbin/blackvault-tls
    /usr/local/sbin/blackvault-tls setup
    chmod 755 "$PUB_DIR"
    ok "Certificates in $TLS_DIR (private keys readable by root only)"

    # Daily renewal check
    cat > /etc/systemd/system/blackvault-tls-renew.service <<'UNIT_EOF'
[Unit]
Description=Black Vault: renew the HTTPS certificate when needed

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/blackvault-tls renew
UNIT_EOF
    cat > /etc/systemd/system/blackvault-tls-renew.timer <<'UNIT_EOF'
[Unit]
Description=Black Vault: daily HTTPS certificate check

[Timer]
OnCalendar=daily
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
UNIT_EOF
    systemctl daemon-reload
    systemctl enable --now blackvault-tls-renew.timer >/dev/null 2>&1 || warn "Could not start the renewal timer (systemctl enable --now blackvault-tls-renew.timer)"
    ok "Automatic renewal enabled (checked every day)"

    APACHE_BK="$BACKUP_DIR/apache-$STAMP.tar.gz"
    tar -czf "$APACHE_BK" -C /etc apache2 && chmod 600 "$APACHE_BK"
    APACHE_ROLLBACK=1
    ok "Apache configuration backed up to $APACHE_BK"
    a2enmod -q ssl rewrite headers >/dev/null

    # Old sites on the same ports would fight with the new ones: switch them off
    # (they stay in sites-available; they come back if anything fails).
    SITES_DISABLED=()
    for f in /etc/apache2/sites-enabled/*; do
        [[ -e "$f" ]] || continue
        site="$(basename "$f")"; site="${site%.conf}"
        [[ "$site" == "blackvault" ]] && continue
        if grep -Eiq '<VirtualHost[^>]*:(80|443|8080|8443)([[:space:]>])' "$f"; then
            a2dissite -q "$site" >/dev/null
            SITES_DISABLED+=("$site")
        fi
    done
    (( ${#SITES_DISABLED[@]} )) && ok "Old sites replaced by the HTTPS ones: ${SITES_DISABLED[*]}"

    LISTEN=""
    for port in 80 443 8080 8443; do
        grep -RqsE --exclude=blackvault.conf "^[[:space:]]*Listen[[:space:]]+([^[:space:]]*:)?$port([[:space:]]|\$)" \
            /etc/apache2/ports.conf /etc/apache2/conf-enabled/ /etc/apache2/sites-enabled/ || LISTEN+="Listen $port"$'\n'
    done

    TLS_OPTS="SSLEngine on
    SSLCertificateFile $TLS_DIR/server.crt
    SSLCertificateKeyFile $TLS_DIR/server.key
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-CHACHA20-POLY1305
    SSLHonorCipherOrder off
    SSLSessionTickets off
    Protocols http/1.1"

    cat > /etc/apache2/sites-available/blackvault.conf <<SITE_EOF
# Black Vault — web sites (generated by install.sh, rewritten on every update)
${LISTEN}
# ---- Plain HTTP: only redirects to HTTPS. The one exception is the CA
#      certificate (public, not secret) so each device can download it once.
<VirtualHost *:80>
    ServerName $HOST_NAME
    DocumentRoot $PUB_DIR
    <Directory $PUB_DIR>
        Options None
        AllowOverride None
        Require all granted
        <IfModule mod_php.c>
            php_admin_flag engine off
        </IfModule>
        <Files "blackvault-ca.crt">
            ForceType application/x-x509-ca-cert
            Header set Content-Disposition "attachment; filename=blackvault-ca.crt"
        </Files>
    </Directory>
    RewriteEngine On
    RewriteRule ^/blackvault-ca\.crt\$ - [L]
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [R=301,L]
    ErrorLog \${APACHE_LOG_DIR}/blackvault_error.log
    CustomLog \${APACHE_LOG_DIR}/blackvault_access.log combined
</VirtualHost>

<VirtualHost *:8080>
    ServerName $HOST_NAME
    DocumentRoot $PUB_DIR
    RewriteEngine On
    RewriteRule ^ https://%{SERVER_NAME}:8443%{REQUEST_URI} [R=301,L]
    ErrorLog \${APACHE_LOG_DIR}/blackvault_error.log
    CustomLog \${APACHE_LOG_DIR}/blackvault_access.log combined
</VirtualHost>

# ---- Drive + Admin (HTTPS)
<VirtualHost *:443>
    ServerName $HOST_NAME
    DocumentRoot $WWW/minidrive
    <Directory $WWW/minidrive>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
    # Files of up to 20 GB travel in one request (Apache alone stops at 1 GB);
    # PHP's own limit (20 GB, see .htaccess) still applies.
    <Location "/api/upload.php">
        LimitRequestBody 0
    </Location>
    $TLS_OPTS
    ErrorLog \${APACHE_LOG_DIR}/minidrive_error.log
    CustomLog \${APACHE_LOG_DIR}/minidrive_access.log combined
</VirtualHost>

# ---- Monitor (HTTPS)
<VirtualHost *:8443>
    ServerName $HOST_NAME
    DocumentRoot $WWW/sysmonitor
    <Directory $WWW/sysmonitor>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
    $TLS_OPTS
    ErrorLog \${APACHE_LOG_DIR}/sysmonitor_error.log
    CustomLog \${APACHE_LOG_DIR}/sysmonitor_access.log combined
</VirtualHost>
SITE_EOF
    a2ensite -q blackvault >/dev/null
    if ! apache2ctl configtest >/dev/null 2>&1; then
        ERRS="$(apache2ctl configtest 2>&1 | tail -5 || true)"
        restore_apache
        die "Apache did not accept the HTTPS setup, so the previous one was put back (still HTTP). Details:
$ERRS"
    fi
    ok "Apache sites: Drive https://…/ (443), Monitor https://…:8443/, HTTP only redirects"
fi

# ---------------------------------------------------------------------
step "6/8  Disks and disk health"
# ---------------------------------------------------------------------
if [[ "$TEST_MODE" == "1" ]]; then
    ok "(test mode: skipped)"
else
    # Tools it relies on (best effort: without internet it still installs)
    MISSING=()
    command -v smartctl >/dev/null 2>&1 || MISSING+=(smartmontools)
    command -v rsync    >/dev/null 2>&1 || MISSING+=(rsync)
    command -v sfdisk   >/dev/null 2>&1 || MISSING+=(fdisk)
    if (( ${#MISSING[@]} )); then
        if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends "${MISSING[@]}" >/dev/null 2>&1; then
            ok "Installed: ${MISSING[*]}"
        else
            warn "Could not install ${MISSING[*]} (no internet?). Later: sudo apt install ${MISSING[*]}"
        fi
    fi

    install -d -m 755 -o root -g root /etc/blackvault /var/lib/blackvault /srv/blackvault /srv/blackvault/disks
    install -m 755 -o root -g root "$SRC/tools/blackvault-disk" /usr/local/sbin/blackvault-disk
    # Where the drives live. Only this installer and the disk service write it.
    if [[ ! -f /etc/blackvault/storage.json ]]; then
        printf '{\n    "internal": "%s",\n    "active": "internal",\n    "disks": {}\n}\n' "$WWW/minidrive/storage" > /etc/blackvault/storage.json
    else
        php -r '$f="/etc/blackvault/storage.json"; $d=json_decode((string)file_get_contents($f),true); if(!is_array($d)) $d=["active"=>"internal","disks"=>[]]; $d["internal"]=$argv[1]; file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$WWW/minidrive/storage"
    fi
    chmod 644 /etc/blackvault/storage.json

    cat > /etc/systemd/system/blackvault-disk.socket <<'UNIT_EOF'
[Unit]
Description=Black Vault disk service (only root and www-data may connect)

[Socket]
ListenStream=/run/blackvault-disk.sock
SocketUser=root
SocketGroup=www-data
SocketMode=0660
Accept=yes
MaxConnections=16

[Install]
WantedBy=sockets.target
UNIT_EOF
    cat > /etc/systemd/system/blackvault-disk@.service <<'UNIT_EOF'
[Unit]
Description=Black Vault disk service (one request)

[Service]
# Root on purpose: it formats and mounts disks. It accepts only a short list
# of checked actions (see the top of the program) and never runs a shell.
# No PrivateTmp/ProtectSystem here: those would hide its mounts from the
# rest of the system.
ExecStart=/usr/bin/php /usr/local/sbin/blackvault-disk --socket
StandardInput=socket
StandardOutput=socket
StandardError=journal
NoNewPrivileges=yes
UNIT_EOF
    # If Apache is sandboxed with a read-only system, let it write to the disks
    if [[ "$(systemctl show apache2 -p ProtectSystem --value 2>/dev/null)" == "strict" ]]; then
        printf '[Service]\nReadWritePaths=-/srv/blackvault\n' > /etc/systemd/system/apache2.service.d/blackvault-disks.conf
        ok "Apache may write to the Black Vault disks (/srv/blackvault)"
    fi
    systemctl daemon-reload
    systemctl enable --now blackvault-disk.socket >/dev/null 2>&1 && ok "Disk service ready (socket /run/blackvault-disk.sock, group www-data only)" \
        || warn "Could not start the disk service: sudo systemctl status blackvault-disk.socket"
    # Health once now, so the Admin panel and the Monitor have something to show
    /usr/local/sbin/blackvault-disk smart >/dev/null 2>&1 || true
    if [[ "$(grep -c '# blackvault' /etc/fstab 2>/dev/null || true)" != "0" ]]; then ok "Black Vault disks in /etc/fstab come back by themselves after a restart"; fi
fi

# ---------------------------------------------------------------------
step "7/8  Accounts"
# ---------------------------------------------------------------------
if [[ "$TEST_MODE" == "1" ]]; then CONSOLE="$WWW/blackvault-admin"; else CONSOLE=/usr/local/sbin/blackvault-admin; fi
cat > "$CONSOLE" <<CONSOLE_EOF
#!/usr/bin/env bash
# Black Vault — account console (generated by install.sh). Root only.
#   sudo blackvault-admin status | create-admin | unlock <user> | reset-password <user> | unblock-ips
[[ \$EUID -eq 0 ]] || exec sudo "\$0" "\$@"
exec runuser -u $WEBUSER -- php $WWW/minidrive/includes/manage.php "\$@"
CONSOLE_EOF
chmod 700 "$CONSOLE"
if [[ "$TEST_MODE" == "1" ]]; then
    run_console() { php "$WWW/minidrive/includes/manage.php" "$@"; }
else
    run_console() { "$CONSOLE" "$@"; }
fi
ok "Rescue console installed: sudo blackvault-admin"
SETUP_CODE=""
RC=0; run_console migrate >/dev/null || RC=$?
if (( RC == 3 )); then
    # No administrator yet (fresh install): the first-time setup page in the
    # browser creates it, protected by a one-time code shown only here.
    SETUP_CODE="$(run_console setup-code --plain 2>/dev/null | tail -1 || true)"
    if [[ "$SETUP_CODE" =~ ^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$ ]]; then
        ok "First-time setup ready (the code is shown at the end)"
    else
        SETUP_CODE=""
        warn "Could not make the setup code. Run:  sudo blackvault-admin setup-code"
    fi
elif (( RC != 0 )); then
    warn "Could not check the accounts (code $RC). Try:  sudo blackvault-admin status"
else
    ok "Administrator account present"
fi
[[ -f "$WWW/minidrive/state/users.json" ]] && chmod 640 "$WWW/minidrive/state/"*.json 2>/dev/null || true

# ---------------------------------------------------------------------
step "8/8  Checks, restart and self-attack"
# ---------------------------------------------------------------------
BAD=0
while IFS= read -r -d '' f; do
    if ! php -l "$f" >/dev/null 2>&1; then warn "Syntax error in $f"; BAD=1; fi
done < <(find "$WWW/minidrive" "$WWW/sysmonitor" -path "$WWW/minidrive/storage" -prune -o -name '*.php' -print0)
(( BAD == 0 )) && ok "All PHP files are valid" || die "Some PHP files have errors"

if [[ "$TEST_MODE" == "1" ]]; then
    ok "(test mode: no restart or HTTP tests)"; exit 0
fi

if ! apache2ctl configtest >/dev/null 2>&1; then
    ERRS="$(apache2ctl configtest 2>&1 | tail -5 || true)"
    restore_apache; systemctl restart apache2 >/dev/null 2>&1 || true
    die "Apache configuration has errors; the previous one was put back. Details:
$ERRS"
fi
if ! systemctl restart apache2 || ! sleep 1 || ! curl -s -o /dev/null -m 8 --cacert /etc/blackvault/tls/ca.crt https://127.0.0.1/login.php; then
    # also needed to load the patched PHP; if HTTPS does not answer, nothing is left half-done
    restore_apache
    systemctl restart apache2 || true
    die "Apache did not work with HTTPS, so the previous configuration was put back exactly as it was. See: sudo journalctl -u apache2 -n 30"
fi
APACHE_ROLLBACK=0      # from here on, the new setup is the good one
ok "Apache restarted"
sleep 1

if command -v curl >/dev/null 2>&1; then
    CA="$TLS_DIR/ca.crt"
    D="https://127.0.0.1"; MON="https://127.0.0.1:8443"
    # --cacert: the certificate must really verify against our CA, no shortcuts
    code() { curl -s -o /dev/null -m 8 --cacert "$CA" -w '%{http_code}' "$1" 2>/dev/null || true; }
    # Decoy file in storage/: must be unreachable from the browser
    PROBE="$WWW/minidrive/storage/zz-bv-probe.txt"; echo probe > "$PROBE"; chown "$WEBUSER:$WEBUSER" "$PROBE"
    echo '{}' > "$WWW/minidrive/state/zz-probe.json"; chown "$WEBUSER:$WEBUSER" "$WWW/minidrive/state/zz-probe.json"

    expect_blocked() {  # url, description
        local c; c="$(code "$1")"
        if [[ "$c" == "403" || "$c" == "404" ]]; then ok "Blocked as it should be: $2 (HTTP $c)"
        else warn "DANGER! $2 returns HTTP $c — it should be blocked."; fi
    }
    expect_ok() {
        local c; c="$(code "$1")"
        # 302 = a fresh install sends the sign-in page to the first-time setup
        [[ "$c" == "200" || "$c" == "302" ]] && ok "$2 responds over HTTPS with a valid certificate" || warn "$2 returned HTTP '$c' over HTTPS. Try opening it in the browser anyway."
    }
    expect_redirect() {  # url, expected location, description
        local r; r="$(curl -s -o /dev/null -m 8 -w '%{http_code} %{redirect_url}' "$1" 2>/dev/null || true)"
        [[ "$r" == "301 $2" ]] && ok "$3 → redirected to HTTPS" || warn "$3 should redirect to $2 but answered: $r"
    }
    expect_ok "$D/login.php" "Drive"
    expect_ok "$MON/login.php" "Monitor (:8443)"
    expect_redirect "http://127.0.0.1/login.php" "https://127.0.0.1/login.php" "http://…/login.php"
    expect_redirect "http://127.0.0.1:8080/" "https://127.0.0.1:8443/" "http://…:8080/"
    expect_redirect "http://127.0.0.1/config.php" "https://127.0.0.1/config.php" "http://…/config.php (never served in clear)"
    c="$(curl -s -o /dev/null -m 8 -w '%{http_code}' http://127.0.0.1/blackvault-ca.crt 2>/dev/null || true)"
    [[ "$c" == "200" ]] && ok "CA certificate downloadable for your devices" || warn "The CA certificate download returned HTTP $c"
    TLSV="$(curl -s -o /dev/null -m 8 --cacert "$CA" -w '%{ssl_version}' "$D/login.php" 2>/dev/null || true)"
    [[ -z "$TLSV" ]] && TLSV="$(curl -sv -o /dev/null -m 8 --cacert "$CA" "$D/login.php" 2>&1 | grep -o 'TLSv1\.[23]' | head -1 || true)"
    [[ -n "$TLSV" ]] && ok "Encryption: $TLSV" || true
    if curl -s -o /dev/null -m 8 --cacert "$CA" --tlsv1.1 --tls-max 1.1 "$D/login.php" 2>/dev/null; then
        warn "Old TLS 1.1 is still accepted."
    else
        ok "Old protocols (TLS 1.0/1.1) refused"
    fi

    expect_blocked "$D/storage/zz-bv-probe.txt"        "files in storage/"
    # Any file type can be uploaded: prove that none of them can run or reconfigure Apache
    PD="$WWW/minidrive/storage/users/zz-bv-probe-$$"
    mkdir -p "$PD"; printf 'Require all granted\nOptions +Indexes\n' > "$PD/.htaccess"; printf '<?php echo "EXEC"."UTED";' > "$PD/probe.php"
    chown -R "$WEBUSER:$WEBUSER" "$PD"
    R="$(curl -s -m 8 --cacert "$CA" -w ' %{http_code}' "$D/storage/users/zz-bv-probe-$$/probe.php" 2>/dev/null || true)"
    if [[ "$R" == *EXECUTED* ]]; then warn "DANGER! A .php file in the drives was executed."; elif [[ "$R" == *" 403" || "$R" == *" 404" ]]; then ok "Uploaded .php and .htaccess files are never run nor obeyed (HTTP ${R##* })"; else warn "Unexpected answer for an uploaded .php file: $R"; fi
    rm -rf "$PD"
    # The upload limits PHP really applies (as Apache runs it)
    PROBE_PHP="$WWW/minidrive/zz-bv-limits-$RANDOM$RANDOM.php"
    printf '<?php echo ini_get("post_max_size"),"|",ini_get("upload_max_filesize"),"|",ini_get("max_file_uploads");' > "$PROBE_PHP"; chown "$WEBUSER:$WEBUSER" "$PROBE_PHP"
    LIM="$(curl -s -m 8 --cacert "$CA" "$D/${PROBE_PHP##*/}" 2>/dev/null || true)"; rm -f "$PROBE_PHP"
    case "$LIM" in
        20G\|20G\|*|20480M\|20480M\|*) ok "Upload limits active: $LIM (post | file | files per request)";;
        *) warn "PHP upload limits are lower than expected ($LIM). Big uploads still work file by file, but check /etc/php/*/apache2/conf.d/99-blackvault.ini";;
    esac
    expect_blocked "$D/storage/.htaccess"              "storage/.htaccess"
    expect_blocked "$D/config.php"                     "Drive config.php"
    expect_blocked "$D/state/zz-probe.json"            "Drive state/"
    expect_blocked "$D/includes/security.php"          "Drive includes/"
    expect_blocked "$D/state/users.json"               "accounts file (state/users.json)"
    expect_blocked "$D/state/audit.log"                "activity log (state/audit.log)"
    expect_blocked "$D/storage/users/"                 "private drives (storage/users/)"
    expect_blocked "$D/includes/manage.php"            "account console (includes/manage.php)"
    expect_blocked "$D/state/login-names.json"         "sign-in lock records"
    expect_blocked "$D/state/setup.json"               "first-time setup code (state/setup.json)"
    c="$(code "$D/api/admin.php")"
    [[ "$c" == "401" ]] && ok "The admin API requires a session (HTTP 401)" || warn "The admin API returned HTTP $c without a session (expected 401)."
    c="$(code "$D/api/list.php")"
    [[ "$c" == "401" ]] && ok "The API requires a session (HTTP 401 when signed out)" || warn "The API returned HTTP $c without a session (expected 401)."
    expect_blocked "$MON/config.php"            "Monitor config.php"
    expect_blocked "$MON/state/login-sm.json"   "Monitor state/"
    expect_blocked "$MON/includes/security.php" "Monitor includes/"
    rm -f "$PROBE" "$WWW/minidrive/state/zz-probe.json"

    if [[ -S /run/blackvault-disk.sock ]]; then
        P="$(stat -c '%a %U %G' /run/blackvault-disk.sock)"
        [[ "$P" == "660 root www-data" ]] && ok "Disk service socket: only root and www-data (mode $P)" || warn "Disk service socket has unexpected permissions: $P"
        R="$(runuser -u "$WEBUSER" -- php -r '$f=@stream_socket_client("unix:///run/blackvault-disk.sock",$e,$s,5); if(!$f){echo "noconn";exit;} fwrite($f,json_encode(["action"=>"status"])."\n"); stream_socket_shutdown($f,STREAM_SHUT_WR); $r=json_decode(stream_get_contents($f),true); echo !empty($r["ok"])?"ok":"bad";' 2>/dev/null || true)"
        [[ "$R" == "ok" ]] && ok "The web can reach the disk service" || warn "The web cannot reach the disk service ($R): sudo journalctl -u 'blackvault-disk@*' -n 20"
        R="$(runuser -u nobody -- php -r '$f=@stream_socket_client("unix:///run/blackvault-disk.sock",$e,$s,5); echo $f?"connected":"refused";' 2>/dev/null || true)"
        [[ "$R" == "refused" ]] && ok "Other system users cannot reach the disk service" || warn "DANGER! Another system user could reach the disk service ($R)."
        R="$(runuser -u "$WEBUSER" -- php -r '$f=@stream_socket_client("unix:///run/blackvault-disk.sock",$e,$s,5); fwrite($f,json_encode(["action"=>"format","path"=>"/dev/sda; rm -rf /","id"=>"0000000000000000","confirm"=>"ERASE"])."\n"); stream_socket_shutdown($f,STREAM_SHUT_WR); $r=json_decode(stream_get_contents($f),true); echo empty($r["ok"])?"refused":"accepted";' 2>/dev/null || true)"
        [[ "$R" == "refused" ]] && ok "The disk service refuses malformed requests" || warn "DANGER! The disk service accepted a malformed request ($R)."
    fi

    HDR="$(curl -sI -m 8 --cacert "$CA" "$D/login.php" 2>/dev/null || true)"
    grep -qi '^x-powered-by' <<<"$HDR" && warn "X-Powered-By is still advertised (cosmetic)." || ok "PHP version is not advertised"
    grep -qi '^server: Apache/' <<<"$HDR" && warn "Apache shows its version in the Server header." || ok "Apache does not show its version"
    grep -qi '^content-security-policy' <<<"$HDR" && ok "Security headers active (CSP, anti-clickjacking…)" || warn "Security headers are missing."
    grep -qi '^set-cookie:.*secure' <<<"$HDR" && ok "Session cookie only travels over HTTPS (Secure)" || warn "The session cookie is not marked Secure."
fi

# Network recommendations
# This server's real LAN (e.g. 192.168.1.20/24 -> 192.168.1.0/24), for the firewall advice
NET="$(ip -o -f inet addr show scope global 2>/dev/null | awk '{print $4; exit}')"
if [[ "$NET" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)\.([0-9]+)/([0-9]+)$ ]]; then
    n=$(( (BASH_REMATCH[1] << 24) | (BASH_REMATCH[2] << 16) | (BASH_REMATCH[3] << 8) | BASH_REMATCH[4] ))
    b=${BASH_REMATCH[5]}; m=$(( b == 0 ? 0 : (0xFFFFFFFF << (32 - b)) & 0xFFFFFFFF )); n=$(( n & m ))
    NET="$(( (n >> 24) & 255 )).$(( (n >> 16) & 255 )).$(( (n >> 8) & 255 )).$(( n & 255 ))/$b"
else
    NET="YOUR_LAN/24"
fi
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ok "Firewall (ufw) active"
    for port in 443 8443; do
        ufw status 2>/dev/null | grep -Eq "(^|[ ,])$port([/ ,]|$)" || warn "The firewall does not let port $port in: devices will not reach HTTPS. Open it with:  sudo ufw allow from $NET to any port $port proto tcp"
    done
else
    warn "The firewall (ufw) is not active. Recommended (keep a second SSH session open):  sudo ufw allow from $NET to any port 22,80,443,8080,8443 proto tcp && sudo ufw enable"
fi

IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
printf '\n%s%s✔ Done.%s\n' "$B" "$G" "$N"
if [[ -n "$SETUP_CODE" ]]; then
    printf '\n  %s┌─ Finish the setup in your browser ─────────────────────────┐%s\n' "$B" "$N"
    printf '     1. Open      %shttps://%s/%s\n' "$B" "${IP:-SERVER_IP}" "$N"
    printf '     2. Type this one-time setup code:   %s%s%s\n' "$B$G" "$SETUP_CODE" "$N"
    printf '     3. Create the administrator (and your first user).\n'
    printf '     The code works for 24 hours. New one: sudo blackvault-admin setup-code\n'
    printf '  %s└────────────────────────────────────────────────────────────┘%s\n\n' "$B" "$N"
    printf '  The browser will warn about the certificate the first time: that is expected\n'
    printf '  (it is your own server). Continue, and see "HTTPS" below to remove the warning.\n\n'
fi
printf '  Drive (users):           %shttps://%s/%s\n' "$B" "${IP:-SERVER_IP}" "$N"
printf '  Admin panel (admins):    %shttps://%s/admin.php%s\n' "$B" "${IP:-SERVER_IP}" "$N"
printf '  Monitor (admins):        %shttps://%s:8443/%s\n' "$B" "${IP:-SERVER_IP}" "$N"
printf '\n  %sHTTPS — once on each device (computer, phone, tablet):%s\n' "$B" "$N"
printf '    1. Download  %shttp://%s/blackvault-ca.crt%s  and install it as a trusted certificate (see README).\n' "$B" "${IP:-SERVER_IP}" "$N"
printf '    2. Check its SHA-256 fingerprint is exactly:\n       %s\n' "$(openssl x509 -noout -fingerprint -sha256 -in "$TLS_DIR/ca.crt" 2>/dev/null | cut -d= -f2)"
printf '    Also copied to your home folder: ~/blackvault-ca.crt\n\n'
printf '  Rescue console:          %ssudo blackvault-admin%s  (unlock, reset passwords, setup code)\n' "$B" "$N"
printf '  After an update, hard-refresh the page once: Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (macOS).\n'
(( WARNED )) && printf '  %sThere are warnings (!) above: read them before relying on this setup.%s\n' "$Y" "$N"
printf '  If something goes wrong, the previous version is in %s/code-%s.tar.gz\n\n' "$BACKUP_DIR" "$STAMP"
