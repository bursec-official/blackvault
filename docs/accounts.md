# Accounts and storage limits

## Two kinds of account

Members and administrators are kept completely separate.

| | Member | Administrator |
|---|:-:|:-:|
| Private drive for files | ✓ | — |
| Admin panel | — | ✓ |
| Monitor (port 8443) | — | ✓ |
| Locked after wrong passwords | ✓ (an administrator unlocks it) | Never |

- The first administrator is created on the first-time setup page. More can be added in **Admin → Add user**, or from the server with `sudo blackvault-admin create-admin`.
- Administrators **cannot open anyone's files**. They manage accounts, storage and disks.
- A person who needs both can have two accounts: one member, one administrator.
- Everyone can change their own password and sign out their other devices from the menu on their name.

## Storage limits

Each member gets one of these:

| Option | Meaning |
|---|---|
| **Equal share** | The free space is split evenly between everyone on "Equal share", and recalculated by itself. |
| **Part of the storage** | ½, ⅓, ¼, ⅕ or any percentage of the space for drives. |
| **Fixed size** | An exact number of GB. |
| **No limit** | Up to the free space on the disk. |

**Keep free for the system** (Admin → Storage & disks) reserves part of the disk (10% by default) so the server never fills up completely. Items in the Trash count toward the limit until they are deleted for good (after 30 days, or when the Trash is emptied).

## Locked accounts

- A member who types a wrong password **3 times in a row** is locked and signed out everywhere. Change the number (3–10) in **Admin → Security**.
- Only an administrator can unlock it: **Admin → Unlock**, or give the account a new password.
- Usernames that don't exist behave exactly the same, so nobody can find out which accounts exist.
- Administrators are never locked by wrong passwords, so you can always get in. They are protected by the device brake instead: **5 wrong passwords from the same device → that device waits 15 minutes** (other devices can still sign in).
- The **Lock / Unlock** button on each account locks someone by hand. Their files stay untouched.

## Rescue console

If you can't get in from the browser, use the server's terminal:

```bash
sudo blackvault-admin status                 # list accounts and their state
sudo blackvault-admin unlock NAME            # unlock an account
sudo blackvault-admin reset-password NAME    # new password (also unlocks and signs out everywhere)
sudo blackvault-admin create-admin           # create another administrator
sudo blackvault-admin unblock-ips            # clear the 15-minute device brake
sudo blackvault-admin setup-code             # only on a server that is not set up yet
```

## Activity log

**Admin → Activity** lists sign-ins, failed sign-ins, locks and every change an administrator makes, with the time and the device's IP address.
