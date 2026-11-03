# Troubleshooting

### The browser says the connection is not private

Expected the first time: the certificate comes from your own server. Continue past the warning, then [install the CA on your devices](https.md) to remove it for good.

### "Access denied" (403) when opening the page

Only private networks can open Black Vault. If your network uses unusual addresses, add it to `BV_ALLOWED_NETS` in `/var/www/minidrive/config.php` and `/var/www/sysmonitor/config.php` (see [Settings](install.md#settings)).

### I lost the setup code, or it says it expired

```bash
sudo blackvault-admin setup-code
```

The code works for 24 hours and 10 tries. It is deleted as soon as the administrator exists.

### I forgot the administrator password

```bash
sudo blackvault-admin reset-password admin
```

Use your administrator's name if it isn't `admin`. `sudo blackvault-admin status` lists all accounts.

### "Too many attempts. Try again in 15 minutes."

That device typed a wrong password 5 times. Wait, or clear it from the server:

```bash
sudo blackvault-admin unblock-ips
```

### A member says their account is locked

Admin → Accounts → **Unlock**. Or from the server: `sudo blackvault-admin unlock NAME`.

### Some files fail to upload from a Mac

If the message says the files "could not be opened on this computer", the browser can't read them. They are usually in **iCloud and not downloaded** to the Mac ("Optimize Mac Storage"). In Finder, right-click the folder → **Download Now**, wait until the cloud icons are gone, then press **Try again** in the message. Uploading the same folder again also works: Black Vault only sends the files that are missing.

### The page looks old or broken after an update

Refresh it once without the cache: **Ctrl+Shift+R** (Windows/Linux) or **Cmd+Shift+R** (macOS).

### The Monitor shows no temperature

Some machines need a sensor module:

```bash
sudo modprobe coretemp    # Intel
sudo modprobe k10temp     # AMD
```

Virtual machines usually have no temperature sensors at all.

### A disk shows "Health unknown"

Some USB enclosures don't pass health data through, and virtual disks have none. Everything else still works.

### Devices can't reach the server

- Is the firewall letting the ports in? `sudo ufw status` (see [Firewall](install.md#firewall)).
- Did the server's IP change? Check with `hostname -I`. If it did, run `sudo blackvault-tls new-ca` and install the new CA on your devices.

### Something else

Run the installer again: it repairs the setup and its self-test points at what's wrong. Apache's logs are in `/var/log/apache2/minidrive_error.log` and `/var/log/apache2/sysmonitor_error.log`. If you're still stuck, [open an issue](https://github.com/bursec-official/blackvault/issues) with the installer's output (remove any IP addresses or names you'd rather keep private).
