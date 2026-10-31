#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
deploy_dir=$(dirname -- "$script_dir")
cd "$deploy_dir"

umask 077
backup_dir=${1:-./backups}
mkdir -p -- "$backup_dir"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
archive="$backup_dir/gestion-plantas-$stamp-$$.dump"
temporary="$archive.partial"

docker compose exec -T db sh -eu -c \
    'exec pg_dump --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --format=custom --no-owner --no-acl' \
    > "$temporary"
docker compose exec -T db pg_restore --list < "$temporary" > /dev/null
mv -- "$temporary" "$archive"
chmod 600 -- "$archive"
printf 'Copia guardada en: %s\n' "$archive"
