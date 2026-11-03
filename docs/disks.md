# Disks and disk health

At first, the drives live on the server's own disk. When you need more space, plug in another disk (USB or internal) and move all the drives onto it from the browser. No terminal needed.

## Add a disk and move the drives

Go to **Admin → Storage & disks** and press **Look for disks**.

1. **Prepare for Black Vault.** Erases the disk and sets it up (ext4). To confirm, type `ERASE` and your password.
2. **Move the drives here.** Copies every drive and trash to the new disk, checks the copy, then switches over. A progress bar shows how it's going. Files are read-only while it runs.
3. **Remove old copy.** When you are happy, frees the space on the previous disk.

Other buttons:
- **Eject**: before unplugging a disk that is not in use.
- **Connect**: a Black Vault disk that was plugged back in.
- **Check health now**: ask every disk for its health again.

## Safety

- The system disk, and anything the system uses (swap, LVM, RAID, encrypted volumes), is never offered for erasing.
- Disks are mounted with `nodev,nosuid,noexec`, so nothing on them can run as a program.
- The web server never gets root. A tiny root service (`/usr/local/sbin/blackvault-disk`, socket `/run/blackvault-disk.sock`, reachable only by the web server) accepts a short list of checked actions and never runs a shell.
- If the disk with the drives is unplugged, the drives go **offline** with a clear message, and nothing is written anywhere else. They come back by themselves when it is plugged in again, and after a restart.

## Disk health

The Monitor shows each disk's health (from SMART): overall state, temperature, hours switched on and wear. It never wakes up a sleeping disk just to ask. The result is kept for 10 minutes.

From the terminal:

```bash
sudo blackvault-disk list     # disks the server can see
sudo blackvault-disk smart    # health of every disk
```

> [!NOTE]
> Some USB enclosures don't pass SMART data through. Those disks show "Health unknown"; that is the enclosure, not a problem with Black Vault.

## Backups

Black Vault keeps your files on one disk at a time. Disks fail, so keep a **backup** of the drives on another device, for example a second disk you copy to now and then. The Trash protects against mistakes, not against a broken disk.

RAID devices (made with Ubuntu's own tools) have not been tested with the disk page yet.
