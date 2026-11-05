# Taskpath for Nextcloud

A governed intake folder: drop a document in it, Taskpath suggests a name and a
folder following how your archive is already organised, you approve in the Files
sidebar or from a notification, and the app files it. Design:
`docs/design/0018-nextcloud-intake-folder-app.md` in the Taskpath repository.

- **The app acts, Taskpath advises.** This app moves files inside Nextcloud, as the
  intake folder's owner. Taskpath never gets Nextcloud credentials and never
  connects to this server; all traffic is outbound.
- **What leaves Nextcloud:** documents dropped in the intake folder (read, then
  deleted by Taskpath) and the names under the chosen archive folder.

## Develop

Needs Docker and a Taskpath dev server on the host
(`bin/rails server -b 0.0.0.0 -p 3000` in the Taskpath repo).

```sh
docker compose up -d
./dev/setup.sh          # enables the app, points it at the host's Taskpath
```

Nextcloud runs at http://localhost:8080 (admin / admin). Background jobs (uploads,
notifications, approvals made in Taskpath) run from cron; locally:

```sh
docker compose exec -u www-data nextcloud sh -c 'while true; do php cron.php; sleep 10; done'
```

Browsers cache app scripts per app version: bump `<version>` in
`taskpath/appinfo/info.xml` and run `occ upgrade` after changing JS or CSS.

## Licence

AGPL-3.0-or-later (see COPYING).

## Frontend

The Taskpath page is a Vue 3 app using Nextcloud's components (`@nextcloud/vue` 9),
built with Vite into `taskpath/js/` (not committed):

```sh
cd taskpath && npm ci && npm run build     # or: npm run watch
```

Bump `<version>` in `appinfo/info.xml` and run `occ upgrade` so browsers load the new build.
