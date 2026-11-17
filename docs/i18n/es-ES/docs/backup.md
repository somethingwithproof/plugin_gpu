<!-- Source: docs/backup.md; SHA256: 5a35172548eec7a8790baefe613b438587759d856d38b6b9357e7d13684e11aa -->

<a id="backup-and-restore"></a>
# Copias de seguridad y restauración

El historial GPU reside en dos lugares que deben respaldarse juntos: los registros de la base de datos que indican a qué GPU pertenece cada fuente de datos, y el historial en los archivos RRD (REQ-BACKUP-001).

<a id="what-to-back-up"></a>
## Qué respaldar

| Contenido | Ubicación | Motivo |
|---|---|---|
| Base de datos Cacti completa | Todas las tablas `plugin_gpu_*` y filas `gpu_*` de `settings` | Nodos, registros GPU por UUID, últimas muestras y fuentes, gráficos y caché de consultas que vinculan cada GPU a su RRD |
| Directorio RRD | `<cacti>/rra` por defecto | Historial |
| Ajustes del complemento | Filas `gpu_*` de la tabla `settings`, dentro del volcado | *Mark Absent After*, *Stale After*, *Keep Plugin Tables on Uninstall* y versión del esquema |
| Líneas extend de `snmpd.conf` de cada nodo | En el nodo | Cacti no las restaura; cópielas de [node-setup.md](node-setup.md) |

Realice el volcado de la base y la copia RRD al mismo tiempo, con el sondeador detenido o entre ciclos, para que ninguna sea más reciente. Volcado coherente de una base en ejecución:

```
mysqldump --single-transaction --routines cacti > cacti.sql
```

<a id="restore"></a>
## Restaurar

1. Detenga el sondeador Cacti, su entrada cron o servicio.
2. Restaure la base: `mysql cacti < cacti.sql`.
3. Restaure el directorio RRD de la misma copia.
4. Inicie el sondeador. La siguiente recopilación comprueba cada GPU por UUID contra sus registros; conserva su fuente y archivo RRD, donde continúan sus valores.

<a id="a-database-older-than-the-rrd-directory"></a>
## Una base de datos anterior al directorio RRD

Si solo se restaura la base o el volcado es anterior a los RRD, Cacti vuelve a asignar los identificadores de fuentes que tenía al volcar. Los archivos RRD reciben el nombre del identificador de fuente. Una GPU que aparece después de restaurar podría recibir una fuente cuyo nombre de archivo ya existe y contiene el historial de otra GPU.

El complemento lo evita (REQ-BACKUP-003): al crear una fuente para una GPU, si ya existe un RRD en esa ruta, lo renombra con el sufijo `.gpu-orphan-<time>` antes de que Cacti escriba y registra una advertencia con la fuente afectada. La nueva GPU comienza su propio historial. Se conserva el archivo renombrado; si necesita su historial, compruebe a qué GPU pertenecía y vincúlelo manualmente a la fuente correcta.

<a id="tested"></a>
## Probado

`tests/bin/restore-flow.php` ejecuta ambos casos de extremo a extremo en CI con `mysqldump` y `mysql`, contra un `snmpd` real:

- restaurar un volcado sobre una base que había cambiado, eliminando una GPU, recupera el inventario respaldado y cada GPU conserva su fuente y RRD, donde continúan sus propios valores (REQ-BACKUP-002);
- restaurar un volcado anterior a cuatro GPU y dejar aparecer otras cuatro les asigna fuentes cuyos nombres RRD están ocupados. Cada archivo existente se aparta antes de escribir y cada archivo nuevo contiene únicamente los valores de su GPU nueva.
