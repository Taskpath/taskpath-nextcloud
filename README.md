# Taskpath for Nextcloud

A governed, shared intake folder: drop a document in it, Taskpath suggests a name and
a folder following how your archive is already organised, someone who may file it
there approves on the Taskpath page, and the app files it as that person. Design:
`docs/design/0018-nextcloud-intake-folder-app.md` and `0020-shared-intake-folders.md`
in the Taskpath repository.

- **The app acts, Taskpath advises.** This app moves files inside Nextcloud, as the
  person who decided. Taskpath never gets Nextcloud credentials and never
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

## Checks

PHP tooling runs in Docker (no local PHP needed), from `taskpath/`:

```sh
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/psalm --no-cache
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/php-cs-fixer fix --dry-run
```

CI runs the same on PHP 8.2 and 8.4, plus the page build.

## Release

`./dev/release.sh` builds `build/taskpath-<version>.tar.gz` with only what an install
needs. With the Nextcloud-signed certificate in `~/.nextcloud/certificates/`
(`taskpath.key`, `taskpath.crt`) it also signs the code and writes the App Store
signature next to the tarball. `info.xml` must validate against
https://apps.nextcloud.com/schema/apps/info.xsd (element order matters).

## Licence

AGPL-3.0-or-later (see COPYING).

## Frontend

The Taskpath page is a Vue 3 app using Nextcloud's components (`@nextcloud/vue` 9),
built with Vite into `taskpath/js/` (not committed):

```sh
cd taskpath && npm ci && npm run build     # or: npm run watch
```

Bump `<version>` in `appinfo/info.xml` and run `occ upgrade` so browsers load the new build.
