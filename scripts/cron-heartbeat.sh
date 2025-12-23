#!/bin/sh
# Cron Heartbeat Script
# Writes a JSON-formatted heartbeat message to the log file
# This script is called by cron to ensure reliable execution

LOG_FILE="/var/log/aviationwx/cron-heartbeat.log"
TIMESTAMP=$(date -u +%Y-%m-%dT%H:%M:%S+00:00)

echo "{\"ts\":\"${TIMESTAMP}\",\"level\":\"info\",\"message\":\"cron heartbeat\",\"source\":\"cron\"}" >> "${LOG_FILE}" 2>&1

