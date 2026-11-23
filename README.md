# SIG (Sistema Integrado de Gestión) — control de inventario

Aplicación PHP y PostgreSQL para inventario por artículo, ubicación y movimientos auditables. Está inspirada en los principios operativos que publica O’Neil Stratus —trazabilidad de extremo a extremo, visibilidad en tiempo real y captura de cada transacción—, sin reutilizar su software ni sus materiales.

El Excel de capacidad es una fotografía de artículos y ubicaciones, no incluye capacidad física por posición ni cantidades en unidades. La aplicación conserva los códigos y estados originales; los límites de capacidad se pueden configurar después, ubicación por ubicación.

## Requisitos

- PHP 8.1 o superior con extensiones `pdo_pgsql` y `pgsql`.
- PostgreSQL con una base de datos `inventory` y el cliente `psql`.
- Python 3; el importador usa solo la biblioteca estándar para leer el `.xlsx`.

## Arranque local

El archivo `/home/ntovar/.inventory.env` configura la conexión local a la base `inventory`; se mantiene fuera del directorio web para que no pueda descargarse desde el navegador.

```sh
php bin/migrate.php
python3 bin/import_inventory.py "/home/ntovar/Descargas/CAPACIDAD ALMACEN AL CIERRE DE OCTUBRE DE 2026.xlsx"
php -S 127.0.0.1:8080
```

Abre `http://127.0.0.1:8080/`. En el primer acceso se solicita crear el usuario administrador; la contraseña de aplicación se almacena como hash.

El importador carga `CONSOLIDADO` y `DESTRUCCIÓN`, omite cualquier ID repetido y no cuenta `INICIALES` por separado porque sus 529 IDs ya forman parte del consolidado. La carga inicial se rechaza si ya hay artículos en el inventario; es de solo una ejecución inicial y no altera existencias ni trazabilidad posteriores.

## Operación

- **Recepción:** procesa códigos existentes en estado «No procesado» y los ubica; no crea artículos nuevos.
- **Códigos:** los administradores registran prefijos alfanuméricos de 1 a 3 caracteres por cliente y generan series de hasta 500 artículos por lote. Cada artículo queda en estado «No procesado» y sin ubicación; la numeración continúa después del mayor sufijo encontrado en el inventario.
- **Traslado:** mueve artículos en almacén a otra posición.
- **Salida:** registra un despacho temporal.
- **Devolución:** vuelve a ubicar un artículo en salida.
- **Baja:** retira definitivamente un artículo.
- **Bajas permanentes por lote:** los administradores filtran el inventario por «Baja permanente», seleccionan hasta 500 registros y los ocultan del inventario activo mediante soft delete; el lote se confirma completo o no se aplica.
- **Consulta local de inventario:** el dashboard responde preguntas predefinidas sobre totales, estados, clientes y edificios mediante SQL parametrizado, respetando los permisos del usuario. El ranking de clientes consolida las cuentas por los primeros cuatro dígitos del código, igual que Reportes. No envía datos a servicios externos.
- **Clientes, departamentos y contactos:** los administradores pueden crear y modificar clientes, sus departamentos y varias personas de contacto por empresa desde **Clientes**. Cada contacto registra nombre y cargo. Los departamentos no se pueden eliminar desde esa pantalla. En `inventory_items`, `account_code` guarda el código del cliente, `client_id` referencia `clients` y `department_id` referencia el departamento del cliente. Al ejecutar `php bin/migrate.php`, los códigos históricos separados por `\` se normalizan y se relacionan con ambos catálogos.
- **Reportes:** los administradores acceden a **Reportes** para consultar un resumen de artículos por cliente, con totales por estado y última actualización.
- **Trazabilidad:** los cambios se confirman juntos en una transacción; cada movimiento tiene folio, usuario, referencia, nota, ubicaciones origen/destino y estado anterior/nuevo.
- **Remesas:** una salida temporal solo admite artículos «En almacén» de una empresa; el prefijo del código determina la empresa y filtra sus contactos posibles. Requiere seleccionar un responsable de recibirla. El sistema genera el número de remesa y conserva el vínculo con el movimiento y sus artículos; **Remesas** permite buscar y consultar el detalle.
- **Capacidad:** opcional y configurable por ubicación. Las recepciones, devoluciones y traslados no exceden el límite una vez configurado.
- **Acceso por usuario:** el primer usuario es administrador y puede crear cuentas y asignar permisos por edificio desde **Usuarios y permisos**. Para otros usuarios, los artículos, fichas, historial, movimientos y ubicaciones se filtran únicamente por los edificios autorizados; los clientes siguen siendo datos del inventario, pero ya no son un criterio de seguridad. Un usuario sin edificios autorizados no ve registros.
- **Autenticación en dos pasos:** cada usuario puede activar o desactivar Google Authenticator desde **Seguridad**. Al activarla, la cuenta requiere un código TOTP además de la contraseña para iniciar sesión; la activación y desactivación requieren confirmar la contraseña. La clave de configuración se muestra localmente y no se envía a servicios externos.
- **Edificios:** el catálogo `building` mantiene cada código y `warehouse_locations.building_code` lo referencia; los códigos existentes se incorporan al ejecutar `php bin/migrate.php`.
- **Administración de edificios:** los administradores pueden crear y editar códigos y descripciones desde **Edificios**; al cambiar un código, se actualizan automáticamente sus ubicaciones y permisos.

Cada operación admite hasta 500 IDs y se revierte completa si un artículo no existe, tiene un estado incompatible o el destino no tiene espacio. Los registros importados representan una fotografía inicial: no se inventan transacciones históricas que el Excel no contiene.
