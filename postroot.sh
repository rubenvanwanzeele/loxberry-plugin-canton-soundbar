#!/bin/bash
# Post-install — runs as root
# Creates, enables and starts the systemd service for the monitor daemon

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
