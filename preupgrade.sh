#!/bin/bash
# Pre-upgrade — runs as loxberry user
# Backs up config so LoxBerry's overwrite does not lose settings

CFGDIR="/opt/loxberry/config/plugins/cantonbar"

echo "<INFO> Backing up config before upgrade..."
if [ -f "$CFGDIR/cantonbar.cfg" ]; then
    cp "$CFGDIR/cantonbar.cfg" "/tmp/cantonbar_cfg_backup.cfg"
    echo "<OK> Config backed up."
fi

echo "<INFO> Stopping service before upgrade..."
sudo /bin/systemctl stop cantonbar.service 2>/dev/null || true
exit 0
