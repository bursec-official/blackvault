# Monitor

The live server dashboard installed to `/var/www/sysmonitor` (`https://SERVER:8443/`, administrators only): CPU per core, memory, network, disk speed, temperature, busiest processes and disk health.

Everything is read straight from `/proc` and `/sys`, with no shell commands. Install it with `sudo bash install.sh` from the root of the repository. See the main [README](../README.md).
