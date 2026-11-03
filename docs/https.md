# Remove the certificate warning

Black Vault always uses HTTPS, so everything between your devices and the server is encrypted. The certificate comes from a small **certificate authority (CA)** that the installer created on your own server. Browsers don't know that CA yet, so the first time they show a warning.

Install the CA **once on each device** and the warning is gone for good.

> [!NOTE]
> This CA is safe to install. It has *name constraints*: it can only vouch for your server's own IP addresses and names. Even if someone stole it, it would be useless for any other website. Its private key never leaves `/etc/blackvault/tls/` on the server (root only).

## 1. Download the CA

On the device, open:

```text
http://SERVER_IP/blackvault-ca.crt
```

The file is public, not a secret. It is also copied to your home folder on the server (`~/blackvault-ca.crt`), so you can copy it with `scp` if you prefer.

## 2. Check the fingerprint

On the server, run:

```bash
sudo blackvault-tls show
```

The SHA-256 fingerprint it prints must be the same one your device shows for the downloaded file. This proves nobody swapped the file on the way.

## 3. Trust it

**Windows**
1. Double-click `blackvault-ca.crt` → **Install Certificate**.
2. Choose **Local Machine** → **Place all certificates in the following store** → **Browse** → **Trusted Root Certification Authorities**.
3. Finish, then restart the browser.

**macOS**
1. Double-click the file. Keychain Access opens.
2. Double-click **Black Vault local CA** → open **Trust** → set *When using this certificate* to **Always Trust**.
3. Close the window and type your Mac password.

**iPhone and iPad**
1. Open the link in **Safari** → **Allow**.
2. Settings → **Profile Downloaded** → **Install**.
3. Settings → General → About → **Certificate Trust Settings** → turn on **Black Vault local CA**.

**Android**
1. Download the file.
2. Settings → Security → Encryption & credentials → **Install a certificate** → **CA certificate** → choose the file.
   (The menu names change a little between phone brands; search Settings for "certificate".)

**Linux (Chrome, Chromium, Edge, system tools)**
```bash
sudo cp blackvault-ca.crt /usr/local/share/ca-certificates/blackvault-ca.crt
sudo update-ca-certificates
```
Chrome on Linux keeps its own list too: Settings → Privacy and security → Security → **Manage certificates** → Authorities → **Import**.

**Firefox (any system)**

Firefox has its own list: Settings → Privacy & Security → Certificates → **View Certificates** → Authorities → **Import** → tick *Trust this CA to identify websites*.

Then open `https://SERVER_IP/`. You should see the padlock with no warning.

## Good to know

- The server certificate renews itself every year. You don't need to do anything.
- Give the server a **fixed IP address** (a DHCP reservation in your router). The CA only covers the IP the server had when it was installed.
- If the IP changes anyway (for example after moving the server to another network), run `sudo blackvault-tls new-ca` and install the new CA on your devices again.

```bash
sudo blackvault-tls show      # fingerprint, expiry date, download address
sudo blackvault-tls renew     # renew now if needed (also runs every day by itself)
sudo blackvault-tls new-ca    # start over with a new CA (devices must install it again)
```
