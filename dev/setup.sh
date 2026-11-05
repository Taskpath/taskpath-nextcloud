#!/bin/sh
# One-time setup of the local Nextcloud for developing the app.
set -e
occ() { docker compose exec -T -u www-data nextcloud php occ "$@"; }
# Wait until it answers over HTTP: the image's entrypoint has finished by then and
# won't rewrite config.php under the settings below (it did once, dropping one).
until curl -sf http://localhost:8080/status.php 2>/dev/null | grep -q '"installed":true'; do sleep 3; done
occ app:enable taskpath
occ app:disable firstrunwizard
# Browsers reach Taskpath on localhost; this server reaches it via the host gateway.
PORT="${TASKPATH_PORT:-3000}"
occ config:app:set taskpath url --value "http://localhost:$PORT"
occ config:app:set taskpath server_url --value "http://host.docker.internal:$PORT"
occ config:system:set allow_local_remote_servers --value true --type boolean
occ background:cron
echo "Nextcloud: http://localhost:8080 (admin / admin)"
