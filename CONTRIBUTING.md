# Contributing

Thanks for helping! Black Vault aims to stay small, easy to read and safe by default.

## Reporting bugs

Open an [issue](https://github.com/bursec-official/blackvault/issues/new/choose) and include:

- your Ubuntu version (`lsb_release -d`) and PHP version (`php -v`),
- the browser and device,
- what you did, what you expected and what happened,
- the installer's output if the problem is about installing (remove IP addresses or names you'd rather keep private).

Security problems go through private reporting instead: see [SECURITY.md](SECURITY.md).

## Changing the code

The project is plain PHP, JavaScript and CSS. There is no framework, no database and no build step.

```text
minidrive/        Drive + Admin panel (PHP pages, api/, includes/, assets/)
sysmonitor/       Monitor
tools/            root helpers: disk service and certificates
install.sh        installer / updater
```

A few rules keep it safe and simple:

- **Every request that changes something** checks the CSRF token and the signed-in account.
- **Every path from the browser** goes through `md_resolve_path()`. Never build file paths by hand.
- **No inline scripts or third-party resources:** the Content Security Policy blocks them.
- **No new root powers in the web app.** Anything that needs root belongs in `tools/blackvault-disk`, as a small, validated action.
- **Plain English** in the interface: short sentences, active voice, and say how to fix a problem.

Before opening a pull request:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l      # PHP syntax
for f in $(find . -name '*.js'); do node --check "$f"; done   # JavaScript syntax
for f in install.sh tools/blackvault-tls; do bash -n "$f"; done   # shell syntax
```

Then try it for real on a test Ubuntu Server (a virtual machine is fine): run `sudo bash install.sh` and read its self-test at the end. Every check should show ✔.

By sending a pull request, you agree that your changes are published under the project's license ([PolyForm Shield 1.0.0](LICENSE)), and that the author may also include them in commercial licenses of Black Vault.
