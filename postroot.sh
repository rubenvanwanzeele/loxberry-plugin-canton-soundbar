#!/bin/bash
# Post-install — runs as root
# Creates, enables and starts the systemd service for the monitor daemon

echo "<INFO> Ensuring adb is installed for LibreKNX auto-recovery..."
if command -v adb >/dev/null 2>&1; then
    echo "<OK> adb already installed ($(command -v adb))"
else
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y >/dev/null 2>&1 || true
    if apt-get install -y android-tools-adb >/dev/null 2>&1; then
        echo "<OK> Installed android-tools-adb"
    else
        echo "<WARNING> Could not install android-tools-adb in postroot."
        echo "<WARNING> Install manually: sudo apt install -y android-tools-adb"
    fi
fi

echo "<INFO> Installing Canton Smart Soundbar systemd service..."
cat > /etc/systemd/system/cantonbar.service << 'EOF'
[Unit]
Description=Canton Smart Soundbar Monitor (LoxBerry Plugin)
After=network.target mosquitto.service
Wants=mosquitto.service

[Service]
Type=simple
User=loxberry
ExecStart=/usr/bin/python3 /opt/loxberry/bin/plugins/cantonbar/monitor.py \
    --config /opt/loxberry/config/plugins/cantonbar/cantonbar.cfg \
    --logfile /opt/loxberry/log/plugins/cantonbar/monitor.log
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable cantonbar.service
systemctl restart cantonbar.service
echo "<OK> Canton Smart Soundbar service installed and started."
exit 0
