# Desplegar la gestión de un negocio

El backend Laravel y el frontend Angular son dos repositorios independientes. Esta carpeta vive dentro del repositorio backend y contiene la configuración que los reúne para el VPS Ubuntu. Cada instalación corresponde a un negocio y puede administrar varias sedes.

## Clonar los repositorios públicos en el VPS

Desde una carpeta nueva del servidor:

```sh
mkdir -p ~/apps/mi-negocio
cd ~/apps/mi-negocio
git clone https://github.com/juanguillermoduque/gestion-plantas-backend.git backend
git clone https://github.com/juanguillermoduque/gestion-plantas-frontend.git frontend
cd backend/deploy
```

Después sigue los pasos de configuración e instalación de esta guía. Los dos repositorios deben conservar los nombres de carpeta `backend` y `frontend` para que los contextos de Docker Compose coincidan.

## Estructura esperada

Coloca los repositorios como carpetas hermanas con estos nombres:

```text
mi-negocio/
  backend/
    artisan
    composer.json
    composer.lock
    Dockerfile
    deploy/
      compose.yaml
      .env.example
      README.md
      scripts/
  frontend/
    angular.json
    package.json
    pnpm-lock.yaml
    Dockerfile
    nginx.conf
```

Ejecuta todos los comandos siguientes desde `backend/deploy/`. Los contextos de construcción son `..` para Laravel y `../../frontend` para Angular.

## Componentes

- PHP 8.4 con PHP-FPM y Laravel; no se usa `artisan serve` en producción.
- Angular compilado y servido por Nginx. El navegador usa un solo origen; Nginx envía `/api`, `/sanctum`, `/login`, `/logout` y `/up` al front controller de Laravel mediante FastCGI.
- PostgreSQL 18, sin puerto publicado hacia el host. La aplicación tiene una cuenta de base de datos distinta de la cuenta administradora.
- Sesiones y caché en PostgreSQL; almacenamiento de Laravel en un volumen persistente.
- Única entrada del stack en `127.0.0.1:8080`; el proxy HTTPS del VPS atiende el dominio público.

El catálogo, la configuración regional y las cuentas pertenecen al negocio. Los usuarios autorizados comparten acceso a sus sedes según su rol; las existencias, reservas, pedidos y gastos se gestionan por sede. El servidor conserva sus fechas en UTC y aplica la zona horaria configurada del negocio a las operaciones y vistas.

Para instalar otro negocio, utiliza otra carpeta y otro `.env`, con `COMPOSE_PROJECT_NAME`, `APP_KEY`, dominio, puerto y credenciales propios. Define el nombre Compose único en el archivo `.env` de cada instalación: los scripts de backup y recuperación también lo leen. Usar solo `-p` al iniciar y otro nombre en `.env` puede dirigir después esos scripts al proyecto equivocado.

PostgreSQL 18 está soportado oficialmente. Su imagen Docker guarda los datos bajo `/var/lib/postgresql/18/docker`, por lo que el volumen se monta en `/var/lib/postgresql`. [Versiones PostgreSQL](https://www.postgresql.org/support/versioning/), [imagen oficial](https://hub.docker.com/_/postgres).

## Preparar Ubuntu y la configuración

Si Docker y Compose aún no están instalados, sigue la [instalación oficial de Docker para Ubuntu](https://docs.docker.com/engine/install/ubuntu/). Si el servidor ya utiliza un panel o un proxy, conserva su configuración y añade únicamente este proyecto.

1. Copia o clona ambos repositorios como indica la estructura anterior.
2. Entra a `backend/deploy/` y copia `.env.example` como `.env`.
3. Elige un `COMPOSE_PROJECT_NAME` propio de ese negocio. Si ya existe otra instalación en el VPS, utiliza además un `APP_PORT` libre y un dominio diferente. El ejemplo usa la base `gestion_negocio` y el rol `gestion_app`, dentro del PostgreSQL privado de esa instalación.
4. Genera dos contraseñas distintas para `POSTGRES_ADMIN_PASSWORD` y `DB_PASSWORD`. Puedes usar dos ejecuciones de `openssl rand -hex 24`. Escríbelas en `.env`; no las compartas en el chat.
5. Conserva `APP_URL=http://localhost:8080` y `SESSION_SECURE_COOKIE=false` para una prueba desde el mismo computador. Antes de publicar en el VPS aplica los valores HTTPS de la sección siguiente.

```sh
cp .env.example .env
chmod 600 .env
# Edita .env y completa ambas contraseñas antes de continuar.
docker compose config --quiet
docker compose build
```

Genera la clave Laravel:

```sh
docker compose run --rm --no-deps backend php artisan key:generate --show
```

Copia el valor completo que devuelve ese comando en `APP_KEY` dentro de `.env`. Conserva esa clave al actualizar y guárdala junto con las copias privadas de configuración. No hay contraseña administradora predeterminada.

## Primera instalación

```sh
docker compose up -d db
docker compose run --rm backend php artisan migrate --force
docker compose run --rm backend php artisan db:seed --force
docker compose run --rm backend php artisan app:create-admin
docker compose up -d backend frontend
docker compose ps
curl -fsS http://127.0.0.1:8080/up
```

El comando `app:create-admin` solicita los datos de la primera cuenta. Luego puedes crear cuentas para las personas autorizadas desde la aplicación, según las opciones implementadas.

La carga normal (`DatabaseSeeder` → `BusinessSeeder`) deja **Mi negocio**, la sede **Principal**, inventario en cero y ningún producto. No crea ventas ni credenciales de demostración. Desde la aplicación configura nombre, actividad, ubicación, cobertura, moneda, formato regional, zona horaria y nombres de las etapas/unidad de inventario. Después crea las sedes y el catálogo e ingresa las existencias reales en la sede correcta.

El inventario utiliza una unidad base compartida por negocio, con cantidades independientes por sede. Los componentes de un producto sirven para calcular su costo; esta versión no lleva existencias separadas de cada componente. Los importes son enteros en la moneda elegida, sin centavos. Configura esa moneda antes de cargar precios o historial: la aplicación bloquea cambiarla cuando esos registros ya existen.

### Carga opcional del ejemplo de suculentas

Para explorar el caso original, ejecuta explícitamente este comando **solo en un negocio vacío y con moneda COP**:

```sh
docker compose run --rm backend php artisan db:seed --class=ViveroSeeder --force
```

Esta carga agrega 250 plantas sin clasificar y los dos productos de suculentas, ajusta las etiquetas de inventario y establece $10.000 como supuesto de valor de la hora. Revisa costos, precios y existencias antes de utilizarla para operaciones reales. Si ambos productos de ejemplo ya existen, no los duplica ni modifica; si hay otros productos, movimientos, existencias, pedidos o gastos, rechaza la carga para conservar los registros. No se ejecuta automáticamente al arrancar ni forma parte de `db:seed` normal.

## Publicar con HTTPS en el VPS

Configura un dominio o subdominio cuyo DNS apunte al VPS. En `.env`, sustituye los ejemplos por el dominio real:

```dotenv
APP_URL=https://negocio.tudominio.com
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=
SANCTUM_STATEFUL_DOMAINS=negocio.tudominio.com
```

`SANCTUM_STATEFUL_DOMAINS` lleva solo el host y, si corresponde, el puerto; no lleva `https://`. Dejar `SESSION_DOMAIN` vacío permite que la cookie pertenezca al mismo host de la aplicación. El frontend solicita las cookies CSRF y envía las peticiones al mismo origen. [Autenticación SPA con Sanctum](https://laravel.com/docs/13.x/sanctum).

El proxy que ya exista en el VPS debe dirigir ese dominio a `http://127.0.0.1:8080`, conservar `Host` y enviar `X-Forwarded-Proto`. El backend debe confiar en ese proxy, sin exponer PHP-FPM a Internet.

El stack configura `TRUSTED_PROXIES=*` para reconocer HTTPS y la IP original detrás del proxy. Este valor depende de la topología incluida: PHP-FPM no publica puertos y Nginx se publica únicamente en `127.0.0.1`. Si cambias esa entrada o expones el backend por otra vía, limita los proxies de confianza a las direcciones de tu infraestructura.

Si eliges Caddy instalado en el host, utiliza su [paquete oficial para Ubuntu](https://caddyserver.com/docs/install#debian-ubuntu-raspbian). Añade el bloque de `Caddyfile.example` a su configuración existente, sustituyendo dominio y puerto. Con DNS correcto y los puertos 80/443 dirigidos a Caddy, este puede gestionar HTTPS. [Guía oficial de reverse proxy](https://caddyserver.com/docs/quick-starts/reverse-proxy).

```sh
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
docker compose up -d --force-recreate backend frontend
```

Abre el dominio HTTPS desde el computador y el celular. Verifica inicio y cierre de sesión, cotización y consulta de pedidos antes de usarlo para el negocio. El despliegue real sigue pendiente hasta disponer de dominio, acceso y revisión de los servicios existentes del VPS.

## Copias de seguridad

Desde `backend/deploy/`:

```sh
sh scripts/backup.sh
```

El script utiliza `pg_dump` en formato personalizado, verifica que el archivo pueda leerse y guarda el resultado en `backups/gestion-plantas-FECHA.dump`. PostgreSQL permite crear esta copia consistente mientras se usa la aplicación. El archivo incluye las cuentas y los registros del negocio; mantenlo privado y conserva una copia fuera del VPS. Guarda también `.env`, especialmente `APP_KEY`, en un lugar privado.

La carpeta `backups/` no se publica ni debe subirse al repositorio. El script deja un archivo `.partial` si la copia falla, sin presentarlo como respaldo válido.

### Recuperar sin sobrescribir la base actual

Este comando crea una **base de datos nueva** a partir de la copia y conserva intacta la que utiliza la aplicación:

```sh
sh scripts/restore-copy.sh ./backups/gestion-plantas-COPIA-ELEGIDA.dump
```

El nombre de la base nueva se muestra al terminar. Si quieres usar esa recuperación:

1. Haz otra copia del estado actual y guarda el valor actual de `DB_DATABASE`.
2. Detén la aplicación para que no entren registros mientras cambias de base.
3. Edita `DB_DATABASE` en `.env` con el nombre de la recuperación.
4. Recrea los servicios y comprueba cuentas, pedidos y existencias.

```sh
sh scripts/backup.sh
docker compose stop frontend backend
# Edita DB_DATABASE en .env con el nombre mostrado por restore-copy.sh.
docker compose up -d --force-recreate db backend frontend
docker compose ps
```

La base anterior permanece disponible; volver a su nombre en `.env` permite regresar a ella. La copia debe corresponder a una versión de la aplicación compatible. No ejecutes `migrate:fresh` ni elimines los volúmenes para restaurar.

## Actualizaciones

Conserva el `.env` de esa instalación; no lo reemplaces por `.env.example` ni cambies su nombre de proyecto por `mi_negocio`. Si la instalación antigua no tenía `COMPOSE_PROJECT_NAME`, consulta primero `docker compose ls` y las etiquetas de sus contenedores para identificar el nombre histórico:

```sh
docker ps --format '{{.Names}}  {{.Label "com.docker.compose.project"}}'
```

Añade a su `.env` ese mismo nombre histórico como `COMPOSE_PROJECT_NAME`. Así los servicios y volúmenes siguen perteneciendo al proyecto existente. Conserva también `DB_DATABASE`, sus credenciales y `APP_KEY`; cambiar los valores de ejemplo no migra ni renombra una base existente.

Si el `.env` antiguo tampoco definía `DB_DATABASE`, consulta el nombre efectivo antes de actualizar, con el servicio existente en ejecución, y escríbelo explícitamente en ese `.env`:

```sh
docker compose exec -T db printenv POSTGRES_DB
```

Conserva ese valor histórico: las nuevas instalaciones usan `gestion_negocio` como ejemplo, pero una base anterior puede tener otro nombre. Este comando muestra solo el nombre de la base, sin imprimir contraseñas.

Crea una copia, detén temporalmente la aplicación, actualiza ambos repositorios a versiones compatibles, reconstruye y aplica las migraciones pendientes:

```sh
sh scripts/backup.sh
docker compose stop frontend backend
# Actualiza aquí los dos repositorios a las revisiones elegidas.
docker compose build
docker compose run --rm backend php artisan migrate --force
docker compose run --rm backend php artisan db:seed --force
docker compose up -d backend frontend
docker compose ps
```

Conserva `.env`, `APP_KEY`, `COMPOSE_PROJECT_NAME` y los volúmenes; no copies las variables de un negocio sobre otro. La migración de sedes asigna los registros existentes a Principal y conserva sus datos. `BusinessSeeder` completa la estructura que falte sin reemplazar configuración, catálogo ni existencias existentes. El ejemplo de suculentas permanece separado y no debe usarse como paso de actualización.

Las migraciones de una actualización deben revisarse antes de ejecutarlas sobre los datos reales. Cambiar solo la contraseña de `.env` no cambia la contraseña de un usuario PostgreSQL ya creado.

## Validación

En el repositorio backend, con dependencias y la base de pruebas dedicada `vivero_test` configurada según su README. La suite recrea sus tablas y no debe ejecutarse con datos reales:

```sh
composer test
```

En el frontend (Node.js 24 y PNPM 11.25.0, fijado en `package.json`):

```sh
pnpm --version
pnpm install --frozen-lockfile
pnpm run build --configuration production
```

La construcción con Docker y la prueba completa contra PostgreSQL deben realizarse antes de dar el despliegue por verificado. Esta documentación no afirma que el VPS ya esté instalado ni que se haya ejecutado una imagen Docker en este entorno de preparación.
