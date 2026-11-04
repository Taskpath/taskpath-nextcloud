#!/bin/sh
# A fresh demo Nextcloud: the app enabled and pointed at the Taskpath dev
# server, and the fictional archive in admin's files under "Archief", shared
# with two colleagues (0020): Bob and Carol in the group "kantoor" can see the
# archive, Bob may also file into Administratie/Inkoop. Not connected yet:
# connecting is part of the demo. Wipes the local Nextcloud.
#   ./dev/demo.sh [--language nl]       (TASKPATH_PORT=3001 if Taskpath runs on another port)
set -e
cd "$(dirname "$0")/.."
LANGUAGE=en
[ "$1" = "--language" ] && LANGUAGE="$2"

docker compose down -v >/dev/null 2>&1 || true
docker compose up -d
./dev/setup.sh
occ() { docker compose exec -T -u www-data nextcloud php occ "$@"; }
occ user:setting admin core lang "$LANGUAGE"
occ config:system:set default_language --value "$LANGUAGE"
occ app:disable updatenotification >/dev/null

python3 - <<'PY'
import base64, urllib.parse, urllib.request, urllib.error
auth = "Basic " + base64.b64encode(b"admin:admin").decode()
base = "http://localhost:8080/remote.php/dav/files/admin/Archief/"
def call(method, path, data=None):
    req = urllib.request.Request(base + urllib.parse.quote(path), data=data, method=method, headers={"Authorization": auth})
    try:
        urllib.request.urlopen(req)
    except urllib.error.HTTPError as e:
        if e.code not in (405,):
            raise
call("MKCOL", "")
for line in open("dev/sample/folders.txt"):
    if line.strip(): call("MKCOL", line.strip())
for line in open("dev/sample/files.txt"):
    if line.strip(): call("PUT", line.strip(), b"%PDF-1.4 placeholder")
print("Archive created")
PY

# Colleagues: the archive is shared read-only with "kantoor"; Bob files invoices.
# Setting up Taskpath then shares the intake folder with "kantoor" too.
for person in "bob:Bob Bakker" "carol:Carol Smit"; do
  uid="${person%%:*}"
  docker compose exec -T -u www-data -e OC_PASS="$uid-demo-pw-1" nextcloud php occ user:add --password-from-env \
    --display-name "${person#*:}" --group kantoor "$uid" >/dev/null
  occ user:setting "$uid" core lang "$LANGUAGE"
done
share() {
  curl -sf -u admin:admin -H "OCS-APIRequest: true" -o /dev/null \
    -X POST http://localhost:8080/ocs/v2.php/apps/files_sharing/api/v1/shares \
    --data-urlencode "path=$1" -d "shareType=$2" -d "shareWith=$3" -d "permissions=$4"
}
share /Archief 1 kantoor 1
share /Archief/Administratie/Inkoop 0 bob 31
echo "Colleagues: bob / bob-demo-pw-1 (files invoices), carol / carol-demo-pw-1 (drops documents)"
echo
echo "Ready: http://localhost:8080 (admin / admin), Settings > Administration > Taskpath."
echo "Sample documents to drop in the intake folder: dev/sample/documents/"
