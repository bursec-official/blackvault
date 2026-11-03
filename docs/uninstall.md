# Uninstall

These steps remove Black Vault from the server. Read them all first.

> [!CAUTION]
> Everyone's files are in `/var/www/minidrive/storage/users/` (or on the extra disk mounted under `/srv/blackvault/disks/`, if you moved the drives there). **Copy them somewhere safe first.** The steps below keep them unless you delete them yourself in the last step.

## 1. Switch off the sites and services

```bash
sudo a2dissite blackvault
sudo a2disconf zz-blackvault-security
sudo systemctl disable --now blackvault-tls-renew.timer blackvault-disk.socket
sudo rm -f /etc/systemd/system/blackvault-tls-renew.{service,timer} \
           /etc/systemd/system/blackvault-disk.socket /etc/systemd/system/blackvault-disk@.service \
           /etc/systemd/system/apache2.service.d/blackvault-sysmonitor.conf
sudo rm -f /etc/apache2/sites-available/blackvault.conf /etc/apache2/conf-available/zz-blackvault-security.conf
sudo rm -f /etc/php/*/apache2/conf.d/99-blackvault.ini /etc/php/*/fpm/conf.d/99-blackvault.ini
sudo systemctl daemon-reload
sudo systemctl restart apache2
```

If the installer switched off another site of yours, turn it back on with `sudo a2ensite NAME`.

## 2. Remove the tools

```bash
sudo rm -f /usr/local/sbin/blackvault-admin /usr/local/sbin/blackvault-tls /usr/local/sbin/blackvault-disk
sudo rm -rf /var/www/blackvault-public /var/lib/blackvault
```

## 3. Extra disks (only if you added one)

```bash
grep blackvault /etc/fstab        # lines added for Black Vault disks
```

Unmount those disks (`sudo umount /srv/blackvault/disks/UUID`) and delete their lines from `/etc/fstab` with `sudo nano /etc/fstab`. The files on the disks are not touched.

## 4. The apps and certificates

```bash
sudo rm -rf /var/www/sysmonitor
sudo rm -rf /etc/blackvault            # certificates and disk settings
```

Then remove the CA from your devices (the same place where you added it).

## 5. The Drive and everyone's files

Only when you are **sure** the files are copied somewhere else:

```bash
sudo rm -rf /var/www/minidrive
sudo rm -rf /var/backups/blackvault    # old code backups made by the installer
```
