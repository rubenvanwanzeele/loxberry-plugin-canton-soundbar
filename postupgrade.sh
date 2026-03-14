#!/bin/bash
# Post-upgrade — runs as loxberry user
# Restores backed-up config, re-installs Python deps

CFGDIR="/opt/loxberry/config/plugins/cantonbar"
BACKUP="/tmp/cantonbar_cfg_backup.cfg"

echo "<INFO> Restoring config after upgrade..."
if [ -f "$BACKUP" ]; then
    cp "$BACKUP" "$CFGDIR/cantonbar.cfg"
    rm -f "$BACKUP"
    echo "<OK> Config restored."
else
    echo "<INFO> No backup found — keeping default config."
fi

echo "<INFO> Re-installing Python dependencies..."
pip3 install --quiet paho-mqtt wakeonlan requests 2>&1
echo "<OK> Python dependencies re-installed."

if command -v adb >/dev/null 2>&1; then
    echo "<OK> adb detected ($(command -v adb))"
else
    echo "<WARNING> adb not found in postupgrade check."
    echo "<WARNING> This script runs as loxberry and cannot apt-install system packages."
    echo "<WARNING> Install manually: sudo apt install -y android-tools-adb"
fi
exit 0
