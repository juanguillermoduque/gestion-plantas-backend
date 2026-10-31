#!/bin/sh
set -eu

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
    printf '%s\n' 'Uso: sh scripts/restore-copy.sh archivo.dump [nombre_de_base_nueva]' >&2
    exit 1
fi

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
deploy_dir=$(dirname -- "$script_dir")
cd "$deploy_dir"
archive=$1
target=${2:-recuperacion_$(date -u +%Y%m%d_%H%M%S)}

if [ ! -f "$archive" ]; then
    printf 'No se encuentra el archivo: %s\n' "$archive" >&2
    exit 1
fi
case "$target" in
    ''|[0-9]*|*[!a-z0-9_]*)
        printf '%s\n' 'El nombre nuevo debe comenzar con letra o guion bajo y usar solo letras minúsculas, números y guion bajo.' >&2
        exit 1
        ;;
esac
if [ "${#target}" -gt 60 ]; then
    printf '%s\n' 'Usa un nombre de base de datos de hasta 60 caracteres.' >&2
    exit 1
fi

docker compose exec -T db pg_restore --list < "$archive" > /dev/null
docker compose exec -T db sh -eu -c \
    'exec psql --username "$POSTGRES_USER" --dbname postgres --set=ON_ERROR_STOP=1 --set=target="$1" --set=owner="$APP_DB_USER"' \
    sh "$target" <<'SQL'
SELECT format('CREATE DATABASE %I OWNER TO %I', :'target', :'owner') \gexec
SQL

docker compose exec -T db sh -eu -c \
    'export PGPASSWORD="$APP_DB_PASSWORD"; exec pg_restore --host 127.0.0.1 --username "$APP_DB_USER" --dbname "$1" --exit-on-error --single-transaction --no-owner --no-privileges' \
    sh "$target" < "$archive"

printf 'Copia restaurada en la base nueva: %s\n' "$target"
printf '%s\n' 'La base usada por la aplicación no se ha reemplazado.'
printf '%s\n' 'Revisa la copia. Para usarla, detén frontend/backend, cambia DB_DATABASE en .env y vuelve a crearlos.'
