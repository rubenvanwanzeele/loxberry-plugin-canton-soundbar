#!/bin/bash
# Post-install — runs as loxberry user
# Installs Python dependencies

echo "<INFO> Installing Python dependencies..."
pip3 install --quiet paho-mqtt wakeonlan requests 2>&1
echo "<OK> Python dependencies installed."

if command -v adb >/dev/null 2>&1; then
	echo "<OK> adb detected ($(command -v adb))"
else
	echo "<WARNING> adb not found. LibreKNX auto-recovery will stay unavailable until android-tools-adb is installed."
fi
exit 0