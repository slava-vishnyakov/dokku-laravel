#!/usr/bin/env bash

_term() {
  echo "Caught SIGTERM signal!"
  go=""
  wait $(jobs -p)
}

trap _term SIGTERM
go=true

while [[ "${go}" ]]; do
    echo "Run cron"
    if [[ ! -e /app/restarting ]]; then
        php artisan schedule:run &
    else
        echo "[!] /app/restarting is present, not running"
    fi
    echo "Sleep"
    sleep 60
done
