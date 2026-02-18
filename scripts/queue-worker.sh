#!/bin/bash
# Ensures the Laravel queue worker is always running.
# Called every minute by cron. If the worker isn't running, starts it.
# The worker processes email sync jobs, categorization, subscription detection, etc.

PIDFILE="/home/spendifi/queue-worker.pid"
LOGFILE="/home/spendifi/queue-worker.log"

# Check if worker is already running
if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        # Worker is running, nothing to do
        exit 0
    fi
    # PID file exists but process is dead — clean up
    rm -f "$PIDFILE"
fi

# Start the queue worker
cd /home/spendifi/public_html
nohup /usr/bin/php artisan queue:work redis \
    --tries=3 \
    --timeout=300 \
    --sleep=3 \
    --max-jobs=100 \
    --max-time=3600 \
    >> "$LOGFILE" 2>&1 &

echo $! > "$PIDFILE"
echo "$(date): Queue worker started with PID $!" >> "$LOGFILE"
