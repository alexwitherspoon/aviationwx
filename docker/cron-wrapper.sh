#!/bin/bash
# Wrapper script to ensure cron job output goes to log files
# All cron jobs should use file-based logging via the aviationwx_log() function
# This wrapper is kept for backward compatibility but no longer redirects to Docker logs

# Execute the command passed as arguments
exec "$@"

