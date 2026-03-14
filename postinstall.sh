#!/bin/bash
# Post-install — runs as loxberry user
# Installs Python dependencies

echo "<INFO> Installing Python dependencies..."
pip3 install --quiet paho-mqtt wakeonlan requests 2>&1
echo "<OK> Python dependencies installed."

if command -v adb >/dev/null 2>&1; then
	echo "<OK> adb detected ($(command -v adb))"
else
	echo "<WARNING> adb not found in postinstall check."
	echo "<WARNING> Root phase should install it; if still missing run: sudo apt install -y android-tools-adb"
fi
exit 0