# Install and update

## Requirements

- **Ubuntu Server 24.04 LTS or newer** (tested on 24.04 and 26.04), 64-bit.
- A user that can run `sudo`.
- Internet access during the install (to download Apache and PHP).
- About 1 GB of RAM is plenty. The disk space you give it is the space your drives get.
- Ports **80, 443, 8080 and 8443** for Black Vault. Ideally use a server just for Black Vault: other Apache sites on those ports are switched off (they stay in `sites-available`).

## Before you start

1. **Give the server a fixed IP address.** In your router, look for "DHCP reservation" (or "static lease") and reserve an address for the server. The certificate is made for that address.
2. **Update Ubuntu** (recommended):
   ```bash
   sudo apt update && sudo apt upgrade
   ```

## Install

```bash
git clone https://github.com/bursec-official/blackvault.git
cd blackvault
sudo bash install.sh
```

Or without `git`: on GitHub press **Code → Download ZIP**, copy the file to the server, then:

```bash
unzip blackvault-main.zip
cd blackvault-main
sudo bash install.sh
```

At the end, open the address it shows (`https://SERVER_IP/`) and type the **one-time setup code**. The [README](../README.md#first-time-setup) shows the four setup steps.

Lost the code, or it expired after 24 hours? Make a new one:

```bash
sudo blackvault-admin setup-code
```

## What the installer does

It runs in 8 steps and prints ✔ or ! for each check:

1. **Apache and PHP:** installs them if they are missing, and applies PHP's security updates.
2. **Backup:** saves the current code to `/var/backups/blackvault/` (never your files).
3. **Code:** copies the Drive to `/var/www/minidrive` and the Monitor to `/var/www/sysmonitor`. It never touches `storage/` (the drives), `state/` (accounts) or `config.php` (settings).
4. **Hardening:** Apache hides its version, refuses to serve internal folders and never runs anything inside the drives. PHP accepts uploads of up to 20 GB.
5. **HTTPS:** creates your private certificate authority and a certificate that renews itself. Port 443 serves the Drive and port 8443 the Monitor; ports 80 and 8080 only redirect to HTTPS. If Apache refuses the new setup, the previous one is put back automatically.
6. **Disks:** installs a small root service (only the web server can talk to it) so the Admin panel can prepare and connect disks, plus `smartmontools` for disk health.
7. **Accounts:** on a new server, makes the one-time setup code. Installs the rescue console `sudo blackvault-admin`.
8. **Self-test:** restarts Apache and attacks its own install: it checks that settings, accounts, internal code and stored files can't be read from the browser, and that an uploaded `.php` file never runs.

Running the installer again is always safe. It is also how you update.

## Firewall

The installer tells you if `ufw` is off and prints the exact command for your network. For example, for a `192.168.1.0/24` network:

```bash
sudo ufw allow from 192.168.1.0/24 to any port 22,80,443,8080,8443 proto tcp
sudo ufw enable
```

> [!WARNING]
> Keep a second SSH session open while you enable the firewall, so a mistake can't lock you out.

## Settings

Most things are changed in the Admin panel. A few server-level settings live in `/var/www/minidrive/config.php` (and `/var/www/sysmonitor/config.php` for the Monitor). Updates never overwrite them.

| Setting | What it does |
|---|---|
| `BV_ALLOWED_NETS` | Which networks can open Black Vault. By default: private networks, localhost and VPNs (Tailscale, WireGuard). |
| `MD_MAX_UPLOAD_MB` | Largest single file, in MB (default 20480 = 20 GB). |
| `date_default_timezone_set(...)` | Time zone for dates. The installer sets the server's own time zone. |
| `MD_MONITOR_PORT` / `SM_DRIVE_PORT` | Only if you changed the ports. |

Example, to also allow one office network:

```php
define('BV_ALLOWED_NETS', ['127.0.0.0/8', '192.168.0.0/16', '10.20.0.0/16', '100.64.0.0/10']);
```

## Update

```bash
cd blackvault        # the folder you installed from
git pull
sudo bash install.sh
```

With the ZIP, download the new ZIP and run its `install.sh` the same way. After an update, refresh the page once with **Ctrl+Shift+R** (Windows/Linux) or **Cmd+Shift+R** (macOS) to load the new design.

## Where things are

| Path | What |
|---|---|
| `/var/www/minidrive` | Drive + Admin panel |
| `/var/www/minidrive/storage/users/` | Everyone's files (unless you moved the drives to another disk) |
| `/var/www/minidrive/state/` | Accounts (password hashes only), activity log |
| `/var/www/sysmonitor` | Monitor |
| `/etc/blackvault/` | Certificates (`tls/`) and which disk holds the drives (`storage.json`) |
| `/srv/blackvault/disks/` | Where extra disks are mounted |
| `/var/backups/blackvault/` | Code and Apache backups made by the installer |
| `/usr/local/sbin/blackvault-*` | Rescue console, certificate tool, disk service |
