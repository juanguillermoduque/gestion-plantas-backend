# Gestión de negocio · Backend

API Laravel 13 con PostgreSQL y sesiones Sanctum. Una instalación administra un negocio con varias sedes configurables. El frontend Angular se mantiene en el repositorio hermano `../frontend/`.

Administra insumos, recetas de productos, precios por cantidad, cotizaciones, inventario por insumo, etapa y sede, reservas, pedidos, pagos, devoluciones, gastos y personas autorizadas. Los pedidos conservan su copia de recetas, costos y precios; las reservas se protegen con transacciones y bloqueos de PostgreSQL.

En **Ajustes** se configuran nombre, actividad, ciudad, país, cobertura de entregas, formato regional, zona horaria, moneda y nombres de la unidad de inventario y sus etapas. Las sedes tienen dirección y datos de contacto propios; el catálogo y los usuarios se comparten dentro del negocio. Todos los usuarios activos pueden consultar sus sedes según su rol; no hay permisos individuales por sede.

El catálogo de **Insumos** registra plantas, materas, abono, empaques u otros materiales por separado, con nombre, categoría, unidad, costo unitario y mínimo de inventario. Cada insumo físico tiene existencias propias por sede y etapa. Las cantidades admiten tres decimales; los costos unitarios, cuatro. Usa una unidad coherente por insumo: si el abono se registra en gramos, sus entradas y recetas también se expresan en gramos, sin conversiones automáticas entre gramos y kilogramos.

Un producto se define con una **receta**: insumos y cantidades consumidas por unidad, más costo adicional unitario y tiempo de preparación. El costo se deriva del catálogo vigente. Por ejemplo, comprar 1.000 gramos por 12.000 COP implica 12 COP/gramo; consumir 150 gramos cuesta 1.800 COP. El asistente del frontend calcula costo del lote dividido entre cantidad; aplicar ese cálculo no registra una entrada ni un gasto.

Los conceptos **no inventariables**, como publicidad o servicios, pueden formar parte del costo unitario de una receta sin reservar existencias. La publicidad específica de un pedido también puede registrarse en el cotizador. El mismo costo no debe cargarse en ambos lugares: la aplicación suma los conceptos expresamente registrados. Los materiales incluidos en una receta ya reducen el aporte de la venta; registrarlos de nuevo como gasto general vuelve a descontarlos.

Confirmar un pedido reserva todos los insumos físicos de sus recetas en la sede elegida; entregarlo los descuenta y cancelarlo libera la reserva. Una cotización no reserva. El pedido conserva la receta y los costos usados aunque después se edite el producto o el catálogo. Los servicios pueden tener una receta vacía y no consumir inventario.

Los precios de venta, pagos, gastos y totales se registran en **unidades enteras de una sola moneda**, sin centavos ni conversión de divisas. Los costos fraccionarios de insumos se acumulan antes de redondear el total. La moneda se elige antes de cargar insumos, productos o costos; no puede cambiarse mientras existan valores vinculados. La zona horaria se usa para fechas operativas y cortes del resumen mensual; los timestamps se almacenan en UTC.

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

Crea los dos productos del ejemplo anterior y 250 plantas sin clasificar, con moneda COP, etapas de cultivo y tarifa de mano de obra inicial de 10.000 COP/hora. Se conserva como carga compatible con el inventario anterior: los productos muestran una receta pendiente de configurar. Desde Insumos registra los materiales y sus existencias reales, y convierte explícitamente cada producto a su receta. No se inventan saldos de materas o decoración. Requiere un negocio vacío y moneda COP; repetirla cuando ambos productos ya existen no agrega existencias ni sobrescribe sus cambios. No se ejecuta automáticamente al desplegar.

La interfaz se abre en `http://localhost:4200`, siguiendo el README del frontend. `GET /api/health` comprueba la API y PostgreSQL; `/up` comprueba el arranque de Laravel. `artisan serve` es para desarrollo; el VPS utiliza PHP-FPM y Nginx.

## Sesión y API

Angular solicita `GET /sanctum/csrf-cookie`, después `POST /api/login`, conserva la cookie y envía `X-XSRF-TOKEN`. El proxy de desarrollo mantiene las peticiones bajo `localhost:4200`. `SANCTUM_STATEFUL_DOMAINS` del ejemplo incluye los puertos 4200 y 8000. En producción se utiliza un solo dominio HTTPS. [Sanctum oficial](https://laravel.com/docs/13.x/sanctum).

Las rutas `/api/state`, `/api/analytics`, `/api/quote`, `/api/inventory`, `/api/orders` y pagos requieren una cuenta activa. Cambiar insumos, productos, ajustes, sedes y accesos requiere administrador. Los operadores pueden gestionar cotizaciones, pedidos, inventario, informes y registrar gastos. `php artisan route:list --path=api` muestra el contrato completo.

`GET /api/state?locationId=<id>` devuelve `supplies` (catálogo global), `balances` (saldos por insumo), movimientos, pedidos, gastos y resumen de esa sede, junto con `locations`, `locationId`, productos y ajustes globales. Las entradas de inventario incluyen `supplyId` y `locationId`; los pedidos y gastos incluyen `locationId`. Cada pedido conserva su sede y los requerimientos de insumos. Las sedes con historia se conservan y pueden desactivarse; esta versión no realiza traslados entre sedes.

Las recetas nuevas usan `components: [{supplyId, quantity}]` y `extraCost`. La cotización devuelve `requirements` con cantidades y costos por insumo. No se suman gramos, litros y unidades en una única cantidad física. Los campos anteriores `stock`, `stockUnitsPerUnit`, `stockUnitsNeeded` y sus alias se conservan para el recurso de inventario anterior; los flujos nuevos utilizan balances y requerimientos por insumo.

## Informes y gráficas

`GET /api/analytics?locationId=<id>&month=YYYY-MM` entrega seis meses de ventas, costos, aporte, gastos y resultado operativo, además de productos principales, distribución de costos, categorías de gastos y valoración estimada del inventario. Las ventas y los costos de venta se reconocen al entregar los pedidos, con sus snapshots; los pagos recibidos son un dato distinto.

La venta por producto se presenta antes de descuentos globales y cobro de entrega. Su aporte directo resta receta y preparación, antes de transporte, publicidad por pedido, otros costos y gastos generales. Por eso esos valores no se presentan como la utilidad final del negocio. El resultado operativo mensual resta los gastos generales registrados una sola vez y se muestra antes de impuestos.

La valoración del inventario usa cantidades actuales multiplicadas por el costo unitario actualmente registrado. Es una estimación a ese costo, sin FIFO ni promedio ponderado de compras. Los insumos no inventariables se excluyen. Los informes no constituyen un libro de caja completo ni registran compras o movimientos bancarios automáticamente.

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

Para actualizar una instalación anterior, respalda PostgreSQL, actualiza **ambos** repositorios y ejecuta las migraciones nuevas y `db:seed`. Las cantidades anteriores se conservan en un insumo especial de inventario anterior por sede, sin crear existencias ficticias para los materiales. Ese saldo puede elegirse explícitamente en una receta después de revisar qué insumo representa, su nombre, unidad y costo; no lo agregues de nuevo como una entrada. Los pedidos anteriores conservan importes y reservas; los productos anteriores siguen disponibles en modo compatible hasta que configures sus recetas. Esa conversión afecta pedidos nuevos, no los ya guardados. Consulta la guía de despliegue para los comandos completos. Los nombres de los repositorios se mantienen para conservar sus enlaces. La reversión automática de la migración de insumos se bloquea fuera del entorno de pruebas; utiliza una copia verificada para regresar de versión.

Para otro negocio, usa una instalación independiente con su propio dominio, base de datos, `APP_KEY`, configuración de Compose y respaldos. Las sedes de una instalación pertenecen al mismo negocio.

## Licencia

El framework Laravel es software de código abierto bajo la [licencia MIT](https://opensource.org/licenses/MIT). Se conserva esta atribución del proyecto original; cada dependencia mantiene su licencia.
