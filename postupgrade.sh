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

ADB_BIN="$(command -v adb 2>/dev/null || true)"
if [ -z "$ADB_BIN" ] && [ -x /usr/bin/adb ]; then
    ADB_BIN="/usr/bin/adb"
fi

if [ -n "$ADB_BIN" ]; then
    echo "<OK> adb detected ($ADB_BIN)"
else
    echo "<INFO> adb not detected in postupgrade user phase."
    echo "<INFO> postroot.sh (root phase) installs adb when available."
fi
exit 0
