<!-- Source: docs/metrics.md; SHA256: 1f8f5b9a81f526894e1d25d7e2f40feec798eda1a886a189f1dd8533bd6c48f1 -->

<a id="gpu-metric-model"></a>
# Modelo de métricas de GPU

Cada backend rellena los mismos campos ([ADR 0009](../../../decisions/0009-common-metric-core-with-vendor-extensions.md)). Cada campo es un elemento de la fuente de datos de la plantilla GPU y un campo de salida de su consulta, por lo que un único conjunto de plantillas de gráficos y Thold cubre todos los fabricantes. La tabla entre los marcadores se genera desde `lib/MetricModel.php` (`MetricModel::table()`); una prueba unitaria falla si ambas difieren.

El hito 3 implementa la columna nvidia-smi. Las columnas DCGM y AMD registran la fuente que leerá un backend futuro, con la incidencia de investigación que la confirma donde aún no está resuelta.

<!-- metric-table:start -->
| Campo | Significado | Unidad | Tipo | Rango | Fuente nvidia-smi | Fuente DCGM | Fuente AMD |
|---|---|---|---|---|---|---|---|
| `util_gpu` | Utilización de GPU | porcentaje | GAUGE | 0 a 100 | `utilization.gpu` | 1613 (proporción 0.0 a 1.0, multiplicada por 100), o 203 (porcentaje, obsoleto) | `metric --usage` `gfx_activity` (#18) |
| `util_mem` | Utilización del controlador de memoria | porcentaje | GAUGE | 0 a 100 | `utilization.memory` | 1614 (proporción 0.0 a 1.0, multiplicada por 100), o 204 (unidad no indicada, #15) | `metric --usage` `umc_activity` (#18) |
| `mem_used` | Memoria de búfer de tramas usada | bytes | GAUGE | 0 a U | `memory.used` (MiB, multiplicado por 1,048,576) | 1627 (usados, bytes), o 252 (MiB, obsoleto) | `metric --mem-usage` `used_vram` (MiB, #18) |
| `mem_total` | Memoria total del búfer de tramas | bytes | GAUGE | 0 a U | `memory.total` (MiB, multiplicado por 1,048,576) | 1625 (capacidad, bytes), o 250 (MiB, obsoleto); 1626 son bytes libres y no se utiliza | `metric --mem-usage` `total_vram` (MiB, #18) |
| `temp_gpu` | Temperatura de GPU | grados C | GAUGE | U a U | `temperature.gpu` | 150 | `metric --temperature` `edge` (#18) |
| `power` | Consumo de potencia de la tarjeta | vatios | GAUGE | 0 a U | `power.draw` | 155 | `metric --power` `socket_power` |
| `power_limit` | Límite de potencia de la tarjeta | vatios | GAUGE | 0 a U | `power.limit` | 160 | #18 |
| `clock_sm` | Reloj SM | MHz | GAUGE | 0 a U | `clocks.sm` | 1603 (hercios, dividido por 1,000,000), o 100 (unidad no indicada) | `metric --clock` (#18) |
| `clock_mem` | Reloj de memoria | MHz | GAUGE | 0 a U | `clocks.mem` | 1604 (hercios, dividido por 1,000,000), o 101 (unidad no indicada) | `metric --clock` (#18) |
| `ecc_corr` | Errores ECC corregidos desde la carga del controlador | recuento | GAUGE | 0 a U | `ecc.errors.corrected.volatile.total` | 310 | `metric --ecc` `total_correctable_count` |
| `ecc_uncorr` | Errores ECC no corregidos desde la carga del controlador | recuento | GAUGE | 0 a U | `ecc.errors.uncorrected.volatile.total` | 311 | `metric --ecc` `total_uncorrectable_count` |
| `ecc_corr_new` | Errores ECC corregidos desde la muestra anterior | recuento | GAUGE | 0 a U | calculado a partir de muestras consecutivas de `ecc_corr` | calculado a partir de muestras consecutivas de `ecc_corr` | calculado a partir de muestras consecutivas de `ecc_corr` |
| `ecc_uncorr_new` | Errores ECC no corregidos desde la muestra anterior | recuento | GAUGE | 0 a U | calculado a partir de muestras consecutivas de `ecc_uncorr` | calculado a partir de muestras consecutivas de `ecc_uncorr` | calculado a partir de muestras consecutivas de `ecc_uncorr` |
| `retired_pending` | Retirada de páginas pendiente | 0 o 1 | indicador | 0 a 1 | `retired_pages.pending` | 392 | #18 |
| `retired_sbe` | Páginas retiradas tras errores ECC de un bit | páginas | GAUGE | 0 a U | `retired_pages.sbe` | 390 (`DCGM_FI_DEV_RETIRED_SBE`, sin confirmar, #15) | #18 |
| `retired_dbe` | Páginas retiradas tras un error ECC de doble bit | páginas | GAUGE | 0 a U | `retired_pages.dbe` | 391 (`DCGM_FI_DEV_RETIRED_DBE`, sin confirmar, #15) | #18 |
| `thr_sw_power_cap` | Relojes limitados por el tope de potencia por software | 0 o 1 | indicador | 0 a 1 | `clocks_event_reasons.active`, bit 0x4 | campo 112, bit 0x4 | `metric --violation` (MI300 o posterior, #18) |
| `thr_hw_slowdown` | Ralentización por hardware | 0 o 1 | indicador | 0 a 1 | `clocks_event_reasons.active`, bit 0x8 | campo 112, bit 0x8 | no aplicable |
| `thr_sw_thermal` | Ralentización térmica por software | 0 o 1 | indicador | 0 a 1 | `clocks_event_reasons.active`, bit 0x20 | campo 112, bit 0x20 | `metric --violation` (MI300 o posterior, #18) |
| `thr_hw_thermal` | Ralentización térmica por hardware | 0 o 1 | indicador | 0 a 1 | `clocks_event_reasons.active`, bit 0x40 | campo 112, bit 0x40 | `metric --violation` (MI300 o posterior, #18) |
| `thr_hw_power_brake` | Freno de potencia por hardware | 0 o 1 | indicador | 0 a 1 | `clocks_event_reasons.active`, bit 0x80 | campo 112, bit 0x80 | no aplicable |
| `age` | Segundos desde que la herramienta tomó la muestra | segundos | GAUGE | 0 a U | calculado a partir de `timestamp` | calculado a partir de la marca de tiempo de la muestra | calculado a partir de la marca de tiempo de la muestra |
<!-- metric-table:end -->

<a id="rules"></a>
## Reglas

- Un valor ausente, no compatible u obsoleto se guarda y muestra como `U`, nunca como 0 (REQ-METRIC-004). Los marcadores de nvidia-smi como `N/A`, `[N/A]` y `[Not Supported]`, y cualquier otro texto en una columna numérica, son desconocidos.
- La utilización es un porcentaje de 0 a 100, independientemente de la escala de origen (REQ-METRIC-002). El campo DCGM 1613 es una proporción entre 0.0 y 1.0 y se multiplica por 100.
- La memoria se expresa en bytes (REQ-METRIC-003). nvidia-smi imprime MiB con `nounits`; la unidad del CSV se comprobará en hardware en #14. Los campos DCGM 1627 (usada) y 1625 (capacidad) son bytes; 1626 representa bytes libres y no forma parte del modelo.
- Los valores ECC son gauges del total comunicado desde la carga del controlador (REQ-METRIC-005). Son recuentos volátiles, no totales de vida útil (REQ-ERR-003): recargar el controlador los pone a 0, por lo que un umbral sobre el total puede restablecerse tras reiniciar sin que se haya corregido la causa.
- `ecc_corr_new` y `ecc_uncorr_new` son los errores desde la muestra anterior, calculados por el plugin a partir de dos totales consecutivos de la misma GPU. Son desconocidos en la primera muestra, tras una lectura perdida o desconocida y cuando el total disminuye (recarga del controlador). Son gauges, nunca contadores de Cacti o RRD: Thold 1.8.2 lanza un `TypeError` de PHP cuando una fuente DERIVE o COUNTER con umbral devuelve `U`, y Cacti desactiva Thold. Alerte sobre estos valores, no sobre los totales.
- `retired_sbe`, `retired_dbe` y `retired_pending` son los recuentos de páginas retiradas y el indicador pendiente de la GPU. Persisten entre cargas del controlador.
- Una GPU sin ECC o con ECC desactivado devuelve `U` para todos los valores ECC, nunca 0 (REQ-ERR-002). El plugin también guarda `ecc.mode.current` y la página de estado muestra el motivo: ECC desactivado o no compatible.
- No hay campo XID. Ninguna fuente XID encaja en los comandos fijos de `nvidia-smi` permitidos por ADR 0008: `--query-gpu` no tiene campo XID (comprobado contra una lista publicada de `--help-query-gpu`; confirmarlo en controladores actuales forma parte de #14), no está documentado que `nvidia-smi event-log` comunique XID y el registro del kernel queda fuera de esos comandos. La decisión #8 selecciona la fuente ([research/xid.md](../../../research/xid.md)).
- Ningún valor de error provoca reinicios, drenaje, borrado de contadores ECC ni retirada de páginas (REQ-ERR-006). El plugin solo lee.
- Los indicadores de limitación valen 1 si su bit está activo en la máscara de motivos de eventos de reloj, 0 si no lo está y desconocido si la máscara es desconocida. La máscara se analiza en hexadecimal (prefijo `0x`) o decimal. `thr_hw_slowdown` (0x8) conserva su indicador hasta que una comprobación real (#17) muestre si aparece sin 0x40 ni 0x80. La máscara bruta se conserva en la caché, por lo que no se pierden bits sin indicador, incluidos los que agregue un controlador posterior.
- `age` son los segundos desde que la herramienta tomó la muestra, según la marca de tiempo de esta, nunca desde que el plugin la leyó (REQ-METRIC-006). Solo es desconocido para una GPU nunca muestreada, por lo que una recolección detenida aparece como una antigüedad creciente sobre la que Thold puede alertar.
- Los nombres de fuente de datos tienen como máximo 19 caracteres, el límite de Cacti. Los nombres `throttle_*` del plan pasaron a `thr_*` porque `throttle_sw_power_cap`, `throttle_hw_slowdown` y `throttle_hw_power_brake` son más largos.
- Un campo que un backend no puede proporcionar es desconocido para ese backend y no se estima (REQ-METRIC-007).

Cada muestra en caché registra también su hora de observación, hora de ingesta, backend y versión de la herramienta.
