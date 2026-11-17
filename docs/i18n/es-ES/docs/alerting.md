<!-- Source: docs/alerting.md; SHA256: b677b227964a6c7b0e999eb33c07ccac9e4f1b6c72ba49e3f7c84617f21b5464 -->

<a id="alerting-with-thold-and-maint"></a>
# Alertas con Thold y Maint

El plugin no tiene motor de alertas ni envía notificaciones propias (REQ-ALERT-005, [ADR 0002](../../../decisions/0002-alert-through-cacti-data-sources.md)). Cada valor de GPU es un elemento de su fuente de datos, por lo que Thold alerta sobre él como sobre cualquier otra fuente de Cacti, y Maint suprime esas alertas para el dispositivo del nodo. Ninguno de los dos plugins se modifica.

Probado con Thold v1.8.2 (`1eb406bd0f81f3204358cce7bd43c8bb5adc8e4a`) y Maint v1.3 (`cd62de9cc323a4f5a8ac80a8d9dda3c7fa16c470`), instalados sin cambios, en Cacti 1.2.31 y la rama 1.2.x actual (`tests/bin/alert-flow.php`, ejecutado en CI).

<a id="set-up-the-shipped-thresholds"></a>
## Configurar los umbrales incluidos

1. Instale y active Thold, y también Maint si utiliza ventanas de mantenimiento.
2. En **Administración > Plantillas de umbral**, elija **Importar** e importe `plugins/gpu/templates/gpu_thold_templates.xml`.
3. En **Plantillas > Plantillas de dispositivo > GPU Node**, añada cada plantilla de umbral *GPU - ...* en *Plantillas de umbral asociadas*.
4. Active *Crear umbrales automáticamente* en Thold para que cree los umbrales cuando el plugin cree los gráficos de cada GPU. Este es el procedimiento de la prueba de extremo a extremo.
5. Los dispositivos que ya tenían la plantilla GPU Node antes del paso 3 aún no están vinculados: añada las plantillas en *Plantillas de umbral asociadas* de la página de cada dispositivo, seleccione los dispositivos en **Administración > Dispositivos** y ejecute **Aplicar umbrales**.

Thold crea un umbral por plantilla para la fuente de datos de cada GPU. Las listas de notificación, los correos y los ajustes de repetición pertenecen a Thold; configúrelos en las plantillas o en los umbrales.

<a id="shipped-templates"></a>
## Plantillas incluidas

| Plantilla | Elemento | Se supera cuando | Tras |
|---|---|---|---|
| GPU - Temperature High | `temp_gpu` | supera 85 °C | 2 sondeos |
| GPU - Power Above Limit | `power_pct` | supera el 100 por ciento del límite de potencia actual | 2 sondeos |
| GPU - Software Thermal Slowdown | `thr_sw_thermal` | 1 | 1 sondeo |
| GPU - Hardware Thermal Slowdown | `thr_hw_thermal` | 1 | 1 sondeo |
| GPU - Hardware Power Brake | `thr_hw_power_brake` | 1 | 1 sondeo |
| GPU - Hardware Slowdown | `thr_hw_slowdown` | 1 | 1 sondeo |
| GPU - New Uncorrected ECC Errors | `ecc_uncorr_new` | aparece cualquier error nuevo desde la muestra anterior | 1 sondeo |
| GPU - Page Retirement Pending | `retired_pending` | 1 | 1 sondeo |
| GPU - Sample Too Old | `age` | supera 900 segundos | 1 sondeo |

Los valores son puntos de partida, no límites del fabricante; ajústelos por modelo de GPU. Se omiten deliberadamente las plantillas para:

- **Límite de potencia por software** (`thr_sw_power_cap`): una GPU a plena carga suele mantenerse en ese límite.
- **Idle y los demás bits de la máscara** sin indicador: Idle es normal y la documentación de nvidia-smi lo marca, junto con Application Clocks Setting, como obsoleto (REQ-THR-002).
- **Totales ECC volátiles** (`ecc_corr`, `ecc_uncorr`): bajan a 0 al cargar el controlador, por lo que un umbral sobre ellos se restablece tras reiniciar aunque la causa no se haya corregido. Utilice `ecc_uncorr_new` para alertar.
- **Nuevos errores ECC corregidos**: los errores corregidos ocasionales son normales y no se conoce una tasa predeterminada; añada un umbral si su parque tiene una definida.

La lista de plantillas se genera desde `lib/TholdTemplateXml.php`; una prueba unitaria falla si el archivo incluido difiere.

<a id="rules-for-gpu-thresholds"></a>
## Reglas para los umbrales de GPU

- **Duraciones.** Configure cada disparador de fallo con al menos un intervalo de recogida (REQ-ALERT-004). El disparador cuenta sondeos de Cacti y el plugin recoge cada nodo una vez por ciclo del sondeador, por lo que un sondeo equivale a un intervalo. El sondeo de Cacti y la recogida no están sincronizados: un valor puede leerse dos veces o saltarse, por lo que un disparador de un solo sondeo puede activarse con una muestra y un fallo más corto que el intervalo puede pasar inadvertido.
- **Valores desconocidos.** `U` nunca supera un umbral (REQ-ALERT-002): una GPU sin ECC, una recogida fallida o una muestra obsoleta devuelve `U` y Thold no alerta. Tampoco mantiene una alerta existente: Thold 1.8.2 considera que un valor no numérico no supera el umbral, de modo que un umbral en alerta se restablece y envía su aviso de restablecimiento cuando su valor pasa a `U`. Un restablecimiento mientras los valores de GPU son desconocidos significa que el plugin perdió el valor, no que el fallo desapareció; `age` permite distinguirlo, porque sigue siendo numérico siempre que exista una muestra y continúa aumentando, y el umbral *GPU - Sample Too Old* alerta sobre él.
- **Solo GAUGE.** Todos los elementos de fuente de datos son GAUGE. Thold 1.8.2 lanza un `TypeError` de PHP cuando una fuente DERIVE o COUNTER con umbral devuelve `U`, y Cacti desactiva entonces Thold para todos. Por eso el plugin calcula los errores ECC nuevos a partir de dos muestras consecutivas de la misma GPU y guarda el resultado como gauge: desconocido en la primera muestra, después de una lectura perdida y tras un reinicio del contador. No cambie un elemento GPU a COUNTER ni DERIVE.
- **Mantenimiento.** Una programación de Maint en el dispositivo del nodo suprime sus umbrales GPU (REQ-ALERT-003): Thold no cuenta ni notifica superaciones durante el mantenimiento y los archivos RRD siguen registrando valores. La página Estado de GPU muestra si Maint mantiene cada nodo en mantenimiento.

<a id="what-the-end-to-end-test-proves"></a>
## Qué demuestra la prueba de extremo a extremo

`tests/bin/alert-flow.php` importa las plantillas incluidas con el importador de Thold, las vincula a GPU Node y deja que Thold cree los umbrales cuando el plugin crea los gráficos. La fuente de datos `power_pct` se calcula con los valores `power` y `power_limit` de la muestra actual, sin redondear a un entero. Las lecturas ausentes y los límites no positivos producen un valor desconocido, que no supera el umbral. Después la prueba cambia la salida del `nvidia-smi` simulado tras un `snmpd` real, recoge por SNMPv3, ejecuta el sondeador de Cacti y la comprobación de Thold, y lee su registro:

- una GPU caliente, limitada por hardware, con errores nuevos no corregidos y retirada de páginas pendiente activa cada umbral; la temperatura solo en el segundo sondeo, y cada uno se restablece cuando el valor vuelve a la normalidad;
- una recarga del controlador reduce el total ECC a 0: el valor de errores nuevos devuelve `U`, no se supera ningún umbral y Thold sigue activo; dos errores nuevos tras el reinicio del contador vuelven a activar el umbral, que después se restablece;
- un fallo de la herramienta convierte los demás valores en `U`, no supera ningún umbral y restablece un umbral de ralentización por hardware que estaba en alerta, mientras `age` alerta y se restablece cuando vuelven las muestras;
- durante una programación de Maint no se cuenta ni notifica una superación, aunque el RRD la registra; al terminar se vuelve a contar y notificar.
