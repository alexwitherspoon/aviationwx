#!/bin/bash
# Wrapper script to ensure cron job output goes to Docker logs
# This script captures stdout/stderr and ensures it's visible in Docker logs

exec >> /proc/1/fd/1 2>> /proc/1/fd/2

# Execute the command passed as arguments
exec "$@"

