# Security

## Reporting a problem

Please **don't open a public issue** for a security problem. Report it privately on GitHub instead: open the **Security** tab of this repository and press **Report a vulnerability**.

Include what you found, the steps to reproduce it, and the Black Vault version (`cat VERSION` in the folder you installed from). You'll get an answer as soon as possible, and credit in the changelog if you'd like it.

Only the latest version gets security fixes, so please update before reporting.

## How Black Vault protects you

### Network
- **Local network only.** Requests from outside private networks (home and office ranges, localhost, VPNs like Tailscale and WireGuard) get `403 Access denied`, even if a router port is opened by mistake. Only the real connection address counts; `X-Forwarded-For` headers are ignored.
- **HTTPS everywhere** with TLS 1.2 and 1.3. Plain HTTP only redirects, except for the public CA file.
- **Private certificate authority with name constraints.** It can only vouch for the server's own exact IP addresses and host names, so a stolen CA key is useless against any other site. The key never leaves `/etc/blackvault/tls/` (root only).

### Accounts and sign-in
- Passwords are stored as bcrypt hashes only.
- Members are locked after 3 wrong passwords in a row (3–10, set by the administrator). A locked account's password is not even checked.
- Each device is braked for 15 minutes after 5 failed attempts.
- Usernames that don't exist behave exactly like real ones, in the answer and in the time it takes.
- Administrators have no drive; members can't open the Admin panel or the Monitor.
- Locking, deleting or resetting an account signs it out everywhere at once.
- Sessions use `Secure`, `HttpOnly` and `SameSite=Strict` cookies, expire after 8 hours idle (24 hours at most), and are separate for the Drive and the Monitor.

### First-time setup
- A new server has no administrator. The setup page asks for a **one-time code** that only the server's terminal shows, so nobody else on the network can claim the server first.
- The code is stored hashed, expires after 24 hours, stops working after 10 wrong tries (and each device is braked after 5), and is deleted once the administrator exists. The setup page then closes for good.

### Files
- Any file type can be stored, but stored files are only data: Apache never serves the storage folder, ignores `.htaccess` files there and has PHP switched off; extra disks are mounted `noexec`.
- Files leave the server only as downloads (attachments) or through a whitelist of preview types. Text is always shown as plain text, and SVG/HTML are never shown inline.
- Paths are checked on every request: no `..`, no symbolic links, nothing outside the user's own drive.

### Web pages
- Strict Content Security Policy: no inline or third-party scripts, no embedding in other sites.
- CSRF tokens on every change; `nosniff`, no caching of private pages, and no server version in the headers.

### Server
- The web server never gets root. Disk actions go through a small root service on a socket that only the web server's group can use. It accepts a short list of validated actions and never runs a shell.
- The system disk, swap, LVM, RAID and encrypted volumes are never offered for erasing.
- The installer checks all of this on every run (it tries to read settings, accounts, code and stored files from the browser, and to run an uploaded `.php` file) and prints a warning if anything is reachable.

## Recommendations

- Don't expose Black Vault to the internet. Use a VPN (Tailscale, WireGuard) to reach it from outside.
- Turn on the firewall (`ufw`); the installer prints the right command for your network.
- Keep Ubuntu updated (`sudo apt update && sudo apt upgrade`) and run the installer again after each Black Vault update.
- Keep a backup of the drives on another device.
