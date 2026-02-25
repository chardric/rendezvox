#!/bin/sh
# Set PHP timezone from TZ environment variable
if [ -n "$TZ" ]; then
  echo "date.timezone = $TZ" > /usr/local/etc/php/conf.d/timezone.ini
  cp /usr/share/zoneinfo/$TZ /etc/localtime 2>/dev/null || true
  echo "$TZ" > /etc/timezone 2>/dev/null || true
fi

# Dump container environment to a file cron jobs can source
env | grep -E '^(RENDEZVOX_|ICECAST_|POSTGRES_|TZ=)' | sed 's/^/export /' > /etc/environment.rendezvox

# Ensure media and log directories are writable by www-data
for dir in /var/log/rendezvox /var/lib/rendezvox/music /var/lib/rendezvox/jingles /var/lib/rendezvox/avatars /var/lib/rendezvox/logos; do
  mkdir -p "$dir"
  chown www-data:www-data "$dir" 2>/dev/null || true
  chmod 775 "$dir" 2>/dev/null || true
done
# Only chown PHP-owned logs — leave liquidsoap.log alone (owned by UID 100)
for f in /var/log/rendezvox/*.log; do
  [ -f "$f" ] || continue
  case "$(basename "$f")" in liquidsoap.log|icecast_*.log) continue ;; esac
  chown www-data:www-data "$f" 2>/dev/null || true
done
for mdir in tagged imports upload _untagged; do
  mkdir -p "/var/lib/rendezvox/music/$mdir"
  chown www-data:www-data "/var/lib/rendezvox/music/$mdir" 2>/dev/null || true
  chmod 775 "/var/lib/rendezvox/music/$mdir" 2>/dev/null || true
done

# Ensure crontab has correct permissions for BusyBox crond (requires 0600)
chmod 0600 /etc/crontabs/www-data 2>/dev/null || true

# Start cron daemon in background (log level 6 = info)
crond -b -l 6

# Clear stale lock files from previous container run, then start media organizer
# Auto-restart loop ensures organizer recovers from crashes
rm -f /tmp/media-organizer.lock /tmp/media-organizer.lock.stop
(while true; do
  rm -f /tmp/media-organizer.lock
  php /var/www/html/src/cli/media-organizer.php >> /var/log/rendezvox/media-organizer.log 2>&1
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] Organizer exited, restarting in 5s..." >> /var/log/rendezvox/media-organizer.log
  sleep 5
done) &

# Start PHP-FPM in foreground (PID 1)
exec php-fpm
