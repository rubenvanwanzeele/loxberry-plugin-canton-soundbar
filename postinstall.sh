#!/bin/bash
# Post-install — runs as loxberry user
# Installs Python dependencies

echo "<INFO> Installing Python dependencies..."
pip3 install --quiet paho-mqtt wakeonlan requests 2>&1
echo "<OK> Python dependencies installed."

ADB_BIN="$(command -v adb 2>/dev/null || true)"
if [ -z "$ADB_BIN" ] && [ -x /usr/bin/adb ]; then
	ADB_BIN="/usr/bin/adb"
fi

if [ -n "$ADB_BIN" ]; then
	echo "<OK> adb detected ($ADB_BIN)"
else
	echo "<INFO> adb not detected in postinstall user phase."
	echo "<INFO> postroot.sh (root phase) installs adb when available."
fi
exit 0