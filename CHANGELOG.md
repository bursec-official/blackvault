# Changelog

## 2.4.0 — first public release

**License**
- Free to use for everyone, companies included, under the PolyForm Shield License 1.0.0. Selling it or offering it to others as a product or service needs a commercial license from the author.

**New**
- **First-time setup in the browser.** A new server opens a setup page: type the one-time code shown by the installer, create the administrator (named `admin` by default), optionally add your first user, done.
- The installer now works on a fresh Ubuntu Server: it installs Apache and PHP when they are missing and uses the server's own time zone.
- `sudo blackvault-admin setup-code` makes a new setup code.
- The Monitor's sign-in page explains when the server is not set up yet.

**Uploads**
- Every file is sent with its number and size, so files a browser leaves out, or sends incomplete, are detected and sent again another way.
- Files the browser cannot open (for example files in iCloud that are not downloaded) get a clear message and a **Try again** button.
- Uploading a folder again only sends what is missing (same name and size are skipped, or keep both copies if you prefer).

**Before the public release**
- 2.3: Trash with Undo; disk management in the Admin panel (prepare, move the drives, eject, connect); disk health (SMART) in the Monitor; configurable lock after 3–10 wrong passwords; Lock / Unlock button for each account.
- 2.2: HTTPS everywhere with a private, name-constrained certificate authority that renews its certificate by itself.
- 2.1: Administrators became separate accounts with no drive; members are locked after wrong passwords; the Monitor is for administrators only.
- 2.0: Several users with private drives and storage limits, Admin panel, security audit, new design.
- 1.x: The first Drive and Monitor.
