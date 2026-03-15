#!/bin/bash
# Post-upgrade - runs as loxberry user
# Restores backed-up config and re-installs Python deps

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
pip3 install --quiet paho-mqtt requests 2>&1
echo "<OK> Python dependencies re-installed."
exit 0
