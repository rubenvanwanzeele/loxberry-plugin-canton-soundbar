#!/bin/bash
# Post-install - runs as loxberry user
# Installs Python dependencies used by the FFAA monitor

echo "<INFO> Installing Python dependencies..."
pip3 install --quiet paho-mqtt requests 2>&1
echo "<OK> Python dependencies installed."
exit 0