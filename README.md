# Gestión de negocio · Backend

API Laravel 13 con PostgreSQL y sesiones Sanctum. Una instalación administra un negocio con varias sedes configurables. El frontend Angular se mantiene en el repositorio hermano `../frontend/`.

Administra productos, componentes de costo, precios por cantidad, cotizaciones, inventario por etapa y sede, reservas, pedidos, pagos, devoluciones, gastos y personas autorizadas. Los pedidos conservan su copia de costos y precios; las reservas se protegen con transacciones y bloqueos de PostgreSQL por sede.

En **Ajustes** se configuran nombre, actividad, ciudad, país, cobertura de entregas, formato regional, zona horaria, moneda y nombres de la unidad de inventario y sus etapas. Las sedes tienen dirección y datos de contacto propios; el catálogo y los usuarios se comparten dentro del negocio. Todos los usuarios activos pueden consultar sus sedes según su rol; no hay permisos individuales por sede.

El inventario actual cuenta **una unidad base común**, distribuida entre sedes y etapas. Cada producto indica cuántas unidades base consume. Por ejemplo, un arreglo puede consumir tres plantas o un paquete tres piezas; un servicio puede consumir cero. Los componentes de costo no tienen existencias independientes: esta versión no sustituye un inventario de múltiples materias primas o SKU. Las cantidades de inventario son enteras.

Los importes se registran en **unidades enteras de una sola moneda**, sin centavos ni conversión de divisas. La moneda se elige antes de cargar productos o costos; no puede cambiarse mientras existan precios, pedidos, gastos o costos configurados. La zona horaria se usa para fechas operativas y cortes del resumen mensual; los timestamps se almacenan en UTC.

## Desarrollo local

Requiere **PHP 8.4**, Composer 2 y **PostgreSQL 18**, con la extensión PHP `pdo_pgsql` y las extensiones requeridas por Laravel. Usa las herramientas de este proyecto o las ya instaladas; no hace falta instalar Node dentro del backend.

En una instancia local de PostgreSQL, crea únicamente las bases y roles dedicados que aún no existan. Desde `psql -h 127.0.0.1 -U postgres -d postgres`:

```sql
CREATE ROLE gestion_app LOGIN;
\password gestion_app
CREATE DATABASE gestion_negocio OWNER gestion_app;
CREATE ROLE vivero_test LOGIN;
\password vivero_test
CREATE DATABASE vivero_test OWNER vivero_test;
```

`\password` solicita las contraseñas sin escribirlas en el SQL. Si esos nombres ya existen, comprueba que pertenecen a este proyecto antes de reutilizarlos.

Desde la raíz del backend:

```sh
cp .env.example .env
composer install
```

Edita `.env`: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=gestion_negocio`, `DB_USERNAME=gestion_app` y la contraseña local. Conserva `APP_URL=http://localhost:8000`, `SESSION_SECURE_COOKIE=false` y los dominios locales de Sanctum incluidos en el ejemplo.

```sh
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan app:create-admin
php artisan serve --host=127.0.0.1 --port=8000
```

`app:create-admin` pide nombre, correo, contraseña oculta y confirmación. No hay credenciales predeterminadas. La carga inicial crea **Mi negocio**, una sede **Principal**, inventario cero y un catálogo vacío. Ejecutarla de nuevo conserva los datos existentes. Al entrar, configura el negocio, sus sedes, los productos y el inventario real.

Para iniciar específicamente el negocio de suculentas, antes de registrar productos o existencias puede ejecutarse esta carga opcional:

```sh
php artisan db:seed --class=ViveroSeeder
```

Crea los dos productos y 250 plantas sin clasificar, con moneda COP, etapas de cultivo y tarifa de mano de obra inicial de 10.000 COP/hora. Los costos y precios son editables. Requiere un negocio vacío y moneda COP; rechaza mezclar esos datos con otro inventario. Repetirla cuando ambos productos ya existen no agrega existencias ni sobrescribe sus cambios. No se ejecuta automáticamente al desplegar.

La interfaz se abre en `http://localhost:4200`, siguiendo el README del frontend. `GET /api/health` comprueba la API y PostgreSQL; `/up` comprueba el arranque de Laravel. `artisan serve` es para desarrollo; el VPS utiliza PHP-FPM y Nginx.

## Sesión y API

Angular solicita `GET /sanctum/csrf-cookie`, después `POST /api/login`, conserva la cookie y envía `X-XSRF-TOKEN`. El proxy de desarrollo mantiene las peticiones bajo `localhost:4200`. `SANCTUM_STATEFUL_DOMAINS` del ejemplo incluye los puertos 4200 y 8000. En producción se utiliza un solo dominio HTTPS. [Sanctum oficial](https://laravel.com/docs/13.x/sanctum).

Las rutas `/api/state`, `/api/quote`, `/api/inventory`, `/api/orders` y pagos requieren una cuenta activa. Cambiar productos, ajustes, sedes y accesos requiere administrador. Los operadores pueden gestionar cotizaciones, pedidos, inventario y registrar gastos. `php artisan route:list --path=api` muestra el contrato completo.

`GET /api/state?locationId=<id>` devuelve el inventario, movimientos, pedidos, gastos y resumen de esa sede, junto con `locations`, `locationId`, catálogo y ajustes globales. Las nuevas entradas de inventario, pedidos y gastos deben incluir `locationId`. Cada pedido conserva su sede; sus pagos, reservas y entregas se aplican a esa sede aunque se haya cambiado el filtro de la interfaz. Las sedes con historia se conservan y pueden desactivarse; esta versión no realiza traslados entre sedes.

El contrato usa `stockUnitsPerUnit`, `stockUnitsNeeded` y `stockUnitsReserved`. Los nombres anteriores relacionados con plantas se aceptan temporalmente como alias para actualizar instalaciones existentes.

## Pruebas PostgreSQL

**`RefreshDatabase` elimina y vuelve a crear tablas: utiliza solamente la base dedicada `vivero_test`, nunca datos reales.** El rol `vivero_test` del ejemplo tiene su propia contraseña. `composer test` limpia la caché de configuración antes de ejecutar la suite.

En Bash, configura las credenciales solo para ese proceso:

```sh
read -r -s -p "Contraseña de vivero_test: " VIVERO_TEST_PASSWORD
printf '\n'
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=vivero_test DB_USERNAME=vivero_test DB_PASSWORD="$VIVERO_TEST_PASSWORD" \
DB_URL= APP_ENV=testing composer test
unset VIVERO_TEST_PASSWORD
```

Si usas PowerShell, establece temporalmente esas mismas variables de proceso antes de `composer test` y restaura sus valores al terminar. `phpunit.xml` no debe contener contraseñas reales. Las variables de entorno del proceso tienen precedencia sobre sus valores predeterminados; comprueba base, servidor y rol antes de ejecutar. La suite utiliza PostgreSQL, sin sustituirlo por SQLite.

## Producción

Consulta **[deploy/README.md](deploy/README.md)** para Docker Compose, HTTPS en Ubuntu, respaldo y restauración. Los dos repositorios deben estar como carpetas hermanas. No se versionan `.env`, contraseñas, `vendor/`, respaldos ni cachés generadas.

Docker instala desde `composer.lock` sin dependencias de desarrollo y genera la caché de configuración al arrancar con las variables del servidor. La verificación local de PHP/PostgreSQL no equivale a haber ejecutado el contenedor o desplegado el VPS.

Para actualizar una instalación anterior, respalda PostgreSQL, actualiza **ambos** repositorios y ejecuta las migraciones nuevas y `db:seed`. La migración asigna los datos existentes a la sede Principal; no reinicia el inventario ni reemplaza el catálogo. Consulta la guía de despliegue para los comandos completos. Los nombres de los repositorios se mantienen para conservar sus enlaces.

Para otro negocio, usa una instalación independiente con su propio dominio, base de datos, `APP_KEY`, configuración de Compose y respaldos. Las sedes de una instalación pertenecen al mismo negocio.

## Licencia

El framework Laravel es software de código abierto bajo la [licencia MIT](https://opensource.org/licenses/MIT). Se conserva esta atribución del proyecto original; cada dependencia mantiene su licencia.
