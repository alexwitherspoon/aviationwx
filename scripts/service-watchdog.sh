#!/bin/bash
# Service Watchdog - Monitors and restarts FTP/SFTP services
# Runs in background, checks every 30 seconds

set -e

LOG_FILE="/var/log/service-watchdog.log"
MAX_RESTART_ATTEMPTS=5
RESTART_BACKOFF_BASE=60  # Start with 60 seconds

# Track restart attempts per service
declare -A restart_counts
declare -A last_restart_time

# Logging function
log_message() {
    local level="$1"
    shift
    local message="$@"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" >> "$LOG_FILE"
}

# Check and restart a service
check_and_restart_service() {
    local service_name="$1"
    local process_name="$2"
    local restart_cmd="$3"
    
    # Check if process is running
    if pgrep -x "$process_name" > /dev/null; then
        # Service is running - reset restart counter if it was previously down
        if [ "${restart_counts[$service_name]}" -gt 0 ]; then
            log_message "INFO" "Service $service_name recovered, resetting restart counter"
            restart_counts[$service_name]=0
            last_restart_time[$service_name]=0
        fi
        return 0
    fi
    
    # Service is down
    local current_time=$(date +%s)
    local last_restart=${last_restart_time[$service_name]:-0}
    local restart_count=${restart_counts[$service_name]:-0}
    
    # Calculate backoff time
    local backoff_seconds=$((RESTART_BACKOFF_BASE * (2 ** restart_count)))
    if [ $backoff_seconds -gt 960 ]; then
        backoff_seconds=960  # Cap at 16 minutes
    fi
    
    # Check if we should attempt restart (enough time has passed)
    if [ $restart_count -ge $MAX_RESTART_ATTEMPTS ]; then
        if [ $((current_time - last_restart)) -gt 3600 ]; then
            # Reset after 1 hour
            log_message "INFO" "Resetting restart counter for $service_name after 1 hour"
            restart_counts[$service_name]=0
            restart_count=0
        else
            log_message "WARN" "Service $service_name is down but max restart attempts ($MAX_RESTART_ATTEMPTS) reached, waiting..."
            return 1
        fi
    fi
    
    # Check if backoff period has passed
    if [ $((current_time - last_restart)) -lt $backoff_seconds ]; then
        return 1  # Still in backoff period
    fi
    
    # Attempt restart
    log_message "WARN" "Service $service_name is down, attempting restart (attempt $((restart_count + 1))/$MAX_RESTART_ATTEMPTS)"
    
    if eval "$restart_cmd" 2>&1 | tee -a "$LOG_FILE"; then
        restart_counts[$service_name]=$((restart_count + 1))
        last_restart_time[$service_name]=$current_time
        
        # Verify service started
        sleep 2
        if pgrep -x "$process_name" > /dev/null; then
            log_message "INFO" "Service $service_name restarted successfully"
            return 0
        else
            log_message "ERROR" "Service $service_name restart command succeeded but process not running"
            return 1
        fi
    else
        log_message "ERROR" "Service $service_name restart command failed"
        restart_counts[$service_name]=$((restart_count + 1))
        last_restart_time[$service_name]=$current_time
        return 1
    fi
}

# Initialize restart counters
restart_counts["vsftpd"]=0
restart_counts["sshd"]=0
last_restart_time["vsftpd"]=0
last_restart_time["sshd"]=0

# Main watchdog loop
log_message "INFO" "Service watchdog started"

while true; do
    check_and_restart_service "vsftpd" "vsftpd" "service vsftpd start"
    check_and_restart_service "sshd" "sshd" "service ssh start"
    
    sleep 30
done

