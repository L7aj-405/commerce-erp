#!/bin/sh
# Entrypoint shared by the web, queue-worker and scheduler containers (one image, three roles).
#
# 1. Runtime gate: refuse to start if this PHP does not implement Morocco's legal time. There is deliberately
#    no bypass flag: an outdated tzdb silently shifts Moroccan business dates by an hour.
# 2. Prepare the container (permissions, storage link, framework caches; optional migrations on web only).
# 3. Start the process for the role: APP_ROLE=web (default) | worker | scheduler, or run an explicit command
#    (Coolify "custom start command", `docker run image php artisan migrate --force`, ...).
#
# Optional environment (all off/benign by default):
#   APP_OPTIMIZE=true|false   run `php artisan optimize` (config/route/view/event caches) at start. Default true.
#   RUN_MIGRATIONS=true       web role only: run `php artisan migrate --force` before serving. Default OFF: take the
#                             DB snapshot first (DEPLOYMENT_CHECKLIST.md section 9) and prefer a Coolify post-deploy command.
#   RUN_SCHEDULER=true        web role only: also run ONE `schedule:work` (as www-data) next to Apache. Default OFF.
#                             Never set it on a worker or on more than one web instance.
set -eu

cd /var/www/html
php scripts/check-timezone-runtime.php

# Mounted volumes may arrive owned by root: the app runs as www-data. Only touch what is not already correct
# (a large storage volume must not be re-chowned on every start).
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/private bootstrap/cache
find storage bootstrap/cache -not -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true

as_app() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

ROLE_START=0
if [ "$#" -eq 0 ]; then
    ROLE_START=1
    case "${APP_ROLE:-web}" in
        web) set -- apache2-foreground ;;
        worker) set -- php artisan queue:work --tries=3 --timeout=3600 ;;
        scheduler) set -- php artisan schedule:work ;;
        *) echo "Unknown APP_ROLE '${APP_ROLE}' (expected web, worker or scheduler)" >&2; exit 64 ;;
    esac
fi

# Only for the role-started long-running processes (an explicit one-off command such as `artisan migrate` or
# `artisan tinker` runs as given, without caching or migrating first).
if [ "$ROLE_START" = "1" ]; then
    if [ "${APP_OPTIMIZE:-true}" = "true" ]; then
        as_app php artisan optimize --no-interaction >/dev/null 2>&1 || echo "warning: artisan optimize failed (continuing)" >&2
    fi
    if [ "$1" = "apache2-foreground" ]; then
        # public/ is root-owned, so the link is created here (same target as `artisan storage:link`).
        [ -e public/storage ] || ln -sfn /var/www/html/storage/app/public public/storage
        if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
            as_app php artisan migrate --force --no-interaction
        fi
        # Scheduler inside the web container (no extra Coolify resource): ONE `schedule:work` supervised by a tiny
        # loop, run as www-data so files it creates in storage/ stay writable by Apache. Apache stays PID 1 and
        # receives the stop signal; the loop dies with the container. Enable on the SINGLE web instance only and
        # remove any Coolify "Scheduled Task" / cron running `schedule:run`, otherwise two schedulers would run.
        if [ "${RUN_SCHEDULER:-false}" = "true" ]; then
            (
                while true; do
                    as_app php artisan schedule:work || true
                    sleep 5
                done
            ) &
        fi
    fi
fi

if [ "$1" = "apache2-foreground" ]; then
    exec "$@"   # Apache drops privileges to www-data itself.
fi
# Worker / scheduler / one-off commands. The official php:*-apache image sets STOPSIGNAL SIGWINCH (Apache's graceful
# stop) and `docker stop` (Coolify) sends exactly that: PHP ignores it and would be SIGKILLed mid-job after the grace
# period. This shell therefore stays as PID 1, forwards SIGTERM/SIGINT/SIGWINCH to PHP as SIGTERM (graceful: the worker
# finishes its current job, then exits) and returns PHP's exit status.
setpriv --reuid=www-data --regid=www-data --init-groups "$@" &
child=$!
trap 'kill -TERM "$child" 2>/dev/null' TERM INT WINCH
status=0
while kill -0 "$child" 2>/dev/null; do
    wait "$child" && status=0 || status=$?
done
exit "$status"
