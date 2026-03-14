#!/bin/bash
# Uninstall — runs as root

echo "<INFO> Removing Canton Smart Soundbar service..."
systemctl stop cantonbar.service 2>/dev/null || true
systemctl disable cantonbar.service 2>/dev/null || true
rm -f /etc/systemd/system/cantonbar.service
systemctl daemon-reload
echo "<OK> Service removed."
exit 0
