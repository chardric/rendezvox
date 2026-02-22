#!/bin/sh
# Set PHP timezone from TZ environment variable
if [ -n "$TZ" ]; then
  echo "date.timezone = $TZ" > /usr/local/etc/php/conf.d/timezone.ini
  cp /usr/share/zoneinfo/$TZ /etc/localtime 2>/dev/null || true
  echo "$TZ" > /etc/timezone 2>/dev/null || true
fi

# Dump container environment to a file cron jobs can source
env | grep -E '^(IRADIO_|ICECAST_|POSTGRES_|TZ=)' | sed 's/^/export /' > /etc/environment.iradio

# Ensure media and log directories are writable by www-data
chmod 777 /var/log/iradio        2>/dev/null || true
chmod 777 /var/lib/iradio/music   2>/dev/null || true
chmod 777 /var/lib/iradio/jingles 2>/dev/null || true
chmod 777 /var/lib/iradio/avatars 2>/dev/null || true
mkdir -p /var/lib/iradio/music/upload
chmod 777 /var/lib/iradio/music/upload 2>/dev/null || true

# Start cron daemon in background (log level 6 = info)
crond -b -l 6

# Clear stale lock files from previous container run, then start media organizer
# Auto-restart loop ensures organizer recovers from crashes
rm -f /tmp/media-organizer.lock /tmp/media-organizer.lock.stop
(while true; do
  rm -f /tmp/media-organizer.lock
  php /var/www/html/src/cli/media-organizer.php >> /var/log/iradio/media-organizer.log 2>&1
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] Organizer exited, restarting in 5s..." >> /var/log/iradio/media-organizer.log
  sleep 5
done) &

# Start PHP-FPM in foreground (PID 1)
exec php-fpm
