<!-- Source: docs/operations.md; SHA256: 20a2068972e3b2402d8914653651a799ff7cec3b23723e33dfc57fb305f036d4 -->

<a id="operator-guide"></a>
# Guía del operador

Cómo instalar, ejecutar, actualizar y eliminar el plugin, qué significa cada valor no disponible y qué comprobar cuando algo parece incorrecto. La configuración de nodos GPU está en [node-setup.md](node-setup.md), las alertas en [alerting.md](alerting.md), las copias de seguridad en [backup.md](backup.md) y la escala medida en [performance.md](performance.md).

Este software aún no se ha publicado. La fase 1 solo se ha ejecutado con un `snmpd` real y un `nvidia-smi` simulado que imprime salida sintética, nunca en hardware GPU; los pilotos se siguen en #54.

Consulte [Roles de acceso](../README.md#access-roles) para los permisos View, Operator y Admin y el comportamiento de la clasificación personal.

<a id="supported-matrix"></a>
## Matriz de compatibilidad

| Componente | Compatible | Evidencia |
|---|---|---|
| Cacti | 1.2.31 y la rama 1.2.x | CI en ambos con todos los PHP siguientes |
| PHP | 8.2, 8.3, 8.4 y 8.5, con la extensión `snmp` | Matriz CI; se rechaza la instalación por debajo de 8.2 |
| Thold, Maint | Thold 1.8.2 y Maint 1.3 sin cambios; ambos opcionales | Prueba de alertas de extremo a extremo en CI ([alerting.md](alerting.md)) |
| Agente del nodo | net-snmp `snmpd` con las dos líneas `extend` documentadas | Prueba de extremo a extremo con el paquete net-snmp de la distribución |
| Controlador NVIDIA | Propuestos: R580, R595 y R620 de centro de datos ([research/compatibility-matrix.md](../../../research/compatibility-matrix.md), #26) | No ejecutado en hardware; el propietario decide la lista |
| AMD, DCGM, MIG, recolectores remotos | Fuera de la fase 1 | Fases 2 y 3 |

**Intervalo.** El recolector lee cada nodo una vez por ciclo del sondeador de Cacti, después del sondeo. El intervalo previsto es el predeterminado de Cacti, 300 segundos; las pruebas inician las recoleccións directamente, no mediante una programación. snmpd almacena la entrada de métricas durante 30 segundos, por lo que un intervalo inferior a aproximadamente un minuto lee la misma muestra dos veces. El mínimo compatible sigue pendiente de medir el costo de la herramienta en hardware (#25).

<a id="least-privilege"></a>
## Mínimos privilegios

| Ubicación | Se ejecuta como | Necesita |
|---|---|---|
| Servidor web de Cacti | Usuario del servidor web | Escritura en `<cacti>/resource/script_server/` durante instalación y actualización para el XML de consulta. Ningún otro acceso a archivos. |
| Recolector | Usuario del sondeador de Cacti, iniciado por el hook `poller_bottom` | La base de datos de Cacti con el acceso de su sondeador, y acceso de red al puerto SNMP de cada nodo. No inicia otros programas ni ejecuta nada en el nodo. |
| Nodo GPU | Usuario que ejecuta `snmpd` | Permiso para ejecutar `nvidia-smi --query-gpu`, que no necesita root ([research/privileges.md](../../../research/privileges.md)). Ejecute snmpd con una cuenta sin privilegios (`agentuser`), como indica [node-setup.md](node-setup.md). |
| SNMP | Usuario SNMPv3 de solo lectura | Solo el grupo system y las tablas extend, con autenticación y privacidad |

El plugin no guarda credenciales propias. Los ajustes SNMP del nodo permanecen en su dispositivo Cacti.

<a id="install"></a>
## Instalar

1. Copie el repositorio a `plugins/gpu` bajo el directorio de Cacti. El directorio debe llamarse `gpu`.
2. Permita al usuario del servidor web escribir en `<cacti>/resource/script_server/` durante la instalación.
3. En **Configuración > Plugins**, instale y active **GPU**. Con PHP anterior a 8.2 se rechaza la instalación con un mensaje y el resto de Cacti sigue funcionando.
4. Asigne los ámbitos en **Configuración > Usuarios**. *GPU View* abre **Inventario GPU** y **Estado de GPU**; *GPU Operator* añade **Operaciones GPU** y confirmaciones personales; *GPU Admin* abre **Nodos GPU** y hereda el acceso de Operator. El usuario que instala recibe View y Admin. Los operadores heredan el acceso de View. Todas las páginas muestran solo nodos de dispositivos que el usuario puede ver en Cacti.
5. Configure el agente de cada nodo ([node-setup.md](node-setup.md)) y agréguelo en **Administración > Nodos GPU**.
6. Para las alertas, importe las plantillas de Thold ([alerting.md](alerting.md)).

Los ajustes están en **Configuración > Ajustes > GPU**:

| Ajuste | Predeterminado | Significado |
|---|---|---|
| Marcar ausente tras | 3 | Recogidas correctas sin encontrar una GPU antes de marcarla ausente, de 1 a 100. Una recolección fallida o vacía nunca cuenta. |
| Obsoleto tras | 600 segundos | Antigüedad máxima de una muestra antes de mostrar sus valores como `U`, de 60 a 86400. `age` sigue contando. |
| Conservar tablas del plugin al desinstalar | activado | Conserva los registros de nodos y GPU salvo que se desmarque. |
| Eliminar los objetos Cacti del plugin al desinstalar | desactivado | También elimina sus plantillas sin uso y el XML de consulta sin cambios. Nunca elimina archivos RRD, dispositivos, gráficos ni fuentes de datos. |

<a id="upgrade"></a>
## Actualizar

1. Haga primero una copia de seguridad ([backup.md](backup.md)).
2. Sustituya los archivos de `plugins/gpu`.
3. Abra **Configuración > Plugins** o cualquier página GPU. El plugin migra el esquema en orden, vuelve a importar las plantillas por hash (actualiza, no duplica) y registra la nueva versión solo cuando todos los pasos terminan correctamente. Una actualización interrumpida termina en el siguiente intento.
4. **Actualice la línea `extend` de métricas de cada nodo** cuando las notas de la versión indiquen que cambió la lista de campos. El plugin solicita exactamente los campos de su modelo, por lo que una línea antigua devuelve una cantidad incorrecta y la recolección falla con *Salida rechazada* hasta actualizarla. Copie la línea de [node-setup.md](node-setup.md) y reinicie snmpd.
5. Vuelva a importar `templates/gpu_thold_templates.xml` si usa los umbrales incluidos; Thold identifica las plantillas por hash.

Versiones del esquema registradas en `gpu_schema_version`:

| Versión | Cambio |
|---|---|
| 1 | Registros de nodos y GPU |
| 2 | Tablas de recolección, observación, reindexación y retirada |
| 3 | Columnas de observación `ecc_corr_new`, `ecc_uncorr_new`, `retired_sbe`, `retired_dbe`, `ecc_mode`. La línea `extend` de métricas agrega `ecc.mode.current`, `retired_pages.sbe` y `retired_pages.dbe`. |

<a id="uninstall"></a>
## Desinstalar

1. Exporte los registros en **Nodos GPU** si desea conservarlos.
2. Desmarque *Conservar tablas del plugin al desinstalar* solo si deben borrarse los registros.
3. Marque *Eliminar los objetos Cacti del plugin al desinstalar* solo si también deben eliminarse las plantillas y el XML de consulta.
4. Desinstale **GPU** en **Configuración > Plugins**.
5. Quite las líneas `extend` del plugin (`cacti_gpu`, `cacti_gpu_names` y `cacti_gpu_legacy` si la agregó) del `snmpd.conf` de cada nodo y reinicie snmpd.

La desinstalación nunca elimina archivos RRD, dispositivos, gráficos ni fuentes de datos, independientemente de los ajustes.

Con *Eliminar los objetos Cacti del plugin al desinstalar* marcado (desactivado por defecto), también elimina:

- cada plantilla importada por el plugin, identificada por su hash: la plantilla de dispositivo *GPU Node*, la consulta *GPU - Devices*, la plantilla de datos *GPU - Metrics* y las plantillas de gráficos GPU, solo si nada ajeno al plugin las usa. Un dispositivo con la plantilla *GPU Node*, una fuente o gráfico creado desde una plantilla GPU, otra plantilla de dispositivo vinculada a un gráfico GPU o una plantilla de umbral Thold sobre la de datos GPU conserva la plantilla que usa. Una plantilla de gráfico o datos se conserva siempre que se conserve la consulta o plantilla de dispositivo que la contiene;
- `resource/script_server/plugin_gpu.xml`, solo si el plugin lo escribió, no cambió y la consulta de datos se eliminó.

El registro de Cacti enumera lo eliminado y lo conservado. Con el ajuste desactivado, todo permanece hasta que lo borre. Los umbrales Thold creados desde plantillas GPU pertenecen a Thold y siempre se conservan. Con PHP anterior a 8.2, donde el plugin no puede cargarse, la desinstalación no elimina ningún objeto Cacti.

<a id="unavailable-values"></a>
## Valores no disponibles

Un valor cuya validez el plugin no puede garantizar se guarda y muestra como `U`, nunca 0, por lo que aparece como hueco en el gráfico y nunca supera un umbral.

| Causa | Valores afectados | Cómo aparece |
|---|---|---|
| La GPU o el controlador no comunica un campo (`[N/A]`, `[Not Supported]`) | Ese campo | Hueco en esa línea del gráfico |
| ECC desactivado o no compatible | Todos los valores ECC y de páginas retiradas | Hueco; Estado de GPU muestra *ECC está desactivado en esta GPU* o *Esta GPU no comunica ECC* |
| Falló la última recolección | Todos los valores de las GPU del nodo salvo `age` | Huecos; Estado de GPU indica el fallo; `age` sigue aumentando |
| La muestra supera *Obsoleto tras* | Todos salvo `age` | Huecos; Estado de GPU muestra *Obsoleto* |
| GPU ausente | Todos salvo `age` | Huecos hasta su regreso; `age` sigue aumentando; Estado e Inventario muestran *Ausente* |
| Primera muestra, lectura perdida o recarga del controlador | `ecc_corr_new`, `ecc_uncorr_new` | Hueco durante una muestra |
| El reloj del nodo adelanta al de Cacti más de 120 segundos | Todos salvo `age` | Se rechaza la recolección y se considera fallida; Estado de GPU indica el reloj |
| GPU nunca muestreada | Todos, incluido `age` | Huecos; Estado de GPU muestra *Nunca observada* |

`age` solo es desconocido para una GPU nunca muestreada; por eso funciona un umbral de antigüedad sobre él.

<a id="troubleshooting"></a>
## Resolución de problemas

Empiece en **Administración > Estado de GPU**. Solo lee registros almacenados, por lo que abrirla nunca contacta con un nodo. Cada fila de nodo muestra la actualidad de sus datos, cómo terminó la última recolección, si Maint lo mantiene en mantenimiento y las versiones del backend, herramienta y controlador; cada fila GPU muestra presencia, antigüedad, estado ECC y máscara bruta de eventos de reloj. Las líneas del plugin llevan la etiqueta `GPU` en el registro de Cacti.

| Última recolección | Causa probable | Qué hacer |
|---|---|---|
| Error de herramienta: agente SNMP inaccesible | Agente caído, dirección o puerto incorrectos, cortafuegos o credenciales SNMPv3 erróneas | Compruebe los ajustes SNMP; la página de dispositivo de Cacti los prueba |
| Error de herramienta: falta la entrada Extend | Faltan las líneas `extend` en `snmpd.conf` o tienen otros nombres | Agréguelas como indica [node-setup.md](node-setup.md) |
| Tiempo agotado | `nvidia-smi` tardó más de los 10 segundos permitidos por `timeout(1)` | Revise el controlador del nodo; el modo de persistencia acorta su carga |
| Permiso denegado | La herramienta rechazó la cuenta de snmpd o, con más de las 128 GPU admitidas, snmpd devolvió su estado para salida superior a 100 KiB | Consulte [research/privileges.md](../../../research/privileges.md); compruebe el número de GPU |
| Error de herramienta: el comando de métricas GPU terminó con estado 2 | Un `nvidia-smi` anterior al cambio de nombre de los motivos de eventos de reloj rechaza `clocks_event_reasons.active` | Agregue la línea opcional `cacti_gpu_legacy` de [node-setup.md](node-setup.md#optional-an-nvidia-smi-older-than-the-clock-event-reasons-rename) |
| Error de herramienta: controlador no cargado | El controlador NVIDIA no está cargado o no coincide con la biblioteca | Corrija el controlador del nodo |
| Error de herramienta: herramienta no encontrada | Falta `/usr/bin/nvidia-smi` | Instale las utilidades del controlador o corrija la ruta en `extend` |
| Error de herramienta: salida rechazada | Cantidad incorrecta de campos, normalmente por una línea `extend` antigua tras actualizar o por un error mezclado en la salida | Copie la línea actual de [node-setup.md](node-setup.md) |
| Error de herramienta: salida truncada | Más salida de la que conserva snmpd (100 KiB o 1024 líneas) | No se espera con un máximo de 128 GPU por nodo ([node-setup.md](node-setup.md)); notifíquelo |
| Error de herramienta: resultado no guardado | La base de datos rechazó la escritura | Revise la base de datos y el registro de Cacti |
| Error de herramienta: salida rechazada: la muestra está N segundos por delante del reloj del servidor Cacti | El reloj del nodo está adelantado | Corrija la sincronización del nodo o del servidor Cacti; la siguiente recolección vuelve a guardar una muestra |
| …; N ejecuciones posteriores omitidas mientras otra retenía el nodo | Una ejecución seguía recogiendo el nodo cuando empezó la siguiente | Suele ser un nodo o agente lento; consulte [performance.md](performance.md) |

Otros síntomas:

- **Actualidad Retrasada u Obsoleta aunque la última recolección terminó**: el recolector no se ejecuta en cada ciclo. Compruebe que el sondeador de Cacti se ejecuta y que su registro no muestra errores GPU.
- **Un nodo en Cacti 1.2.x con puerto SNMP distinto de 161 aparece caído**: consulte [node-setup.md](node-setup.md#an-snmp-port-other-than-161-on-cacti-12x).
- **Los gráficos se detienen o tienen huecos aunque Estado de GPU indica recolección terminada**: Cacti no pudo escribir los RRD, por ejemplo por disco lleno o propietario incorrecto de `rra/`. Cacti escribe esos archivos después de que el plugin guarde la muestra, por lo que en fase 1 Estado de GPU no muestra este error; solo el registro de Cacti, como línea `RRD` o `ERROR` del sondeador. Corrija el disco o los permisos; el siguiente sondeo vuelve a escribir.
- **No hay gráficos para una GPU nueva**: el plugin solo reindexa mientras Cacti considera el dispositivo activo; reintenta cuando vuelve a estarlo.
- **Un RRD renombrado con `.gpu-orphan-` en su nombre**: la fuente de una GPU nueva encontró un RRD en su ruta, normalmente tras restaurar una base de datos más antigua que el directorio RRD. El archivo se apartó para que la nueva GPU tenga su propio historial ([backup.md](backup.md)). Si falla el cambio de nombre, la fuente permanece desactivada y el plugin reintenta cada ciclo; corrija los permisos del directorio o aparte el archivo antiguo antes de activarla.
