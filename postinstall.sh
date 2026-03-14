#!/bin/bash
# Post-install — runs as loxberry user
# Installs Python dependencies

echo "<INFO> Installing Python dependencies..."
pip3 install --quiet paho-mqtt wakeonlan requests 2>&1
echo "<OK> Python dependencies installed."
exit 0