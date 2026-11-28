<!-- Source: README.md; SHA256: 97ba37df4a883426d3984ed7d14547ad7367e973411a8abd930409ce259f545b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [Български](../bg-BG/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [Ελληνικά](../el-GR/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [ქართული](../ka-GE/README.md) | [한국어](../ko-KR/README.md) | [Latviešu](../lv-LV/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Português (Portugal)](../pt-PT/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<a id="plugin_gpu"></a>
# plugin_gpu

Un plugin para Cacti 1.2 que incorpora utilización, memoria, temperatura, potencia, errores ECC y motivos de limitación por GPU desde las herramientas NVIDIA y AMD en Cacti. Cada GPU (o instancia MIG) se convierte en un índice de una consulta de datos del dispositivo de su nodo, de modo que sus gráficos aparecen junto a los demás del nodo bajo los permisos de Cacti, y Thold y Maint pueden alertar sobre ellos y suprimir las alertas.

> **Estado: fases 1 a 3 implementadas en código, aún sin publicar.** La fase 1 (NVIDIA `nvidia-smi` mediante SNMP extend, seguimiento de identidad, gráficos, señales de limitación y ECC, alertas con Thold y Maint y página de estado), la fase 2 (analizador/cliente HTTP(S) de solo lectura para NVIDIA DCGM Exporter y analizador para AMD `amd-smi`), la fase 3 (seguimiento de identidad de instancias MIG, columnas de Schema v4, ejecución del recolector remoto acotada al sondeador y hooks `replicate_out` y `remote_agent`), la CLI de operador y CasC (`cli/gpu.php`) y los hooks del ecosistema (`abilities_register`, `doctor_register_checks`, `casc_register_providers`) están implementados y cubiertos por pruebas unitarias, de seguridad y de integración con Cacti desechable. Las ejecuciones en vivo hasta ahora usan `snmpd` sintético y cargas de prueba en lugar de hardware GPU físico. Antes de etiquetar una versión siguen pendientes: pilotos en hardware real ([docs/pilot-plan.md](../../pilot-plan.md), #54), una revisión externa de seguridad ([docs/security-review.md](../../security-review.md)), muestras capturadas de herramientas reales (#14) y mediciones a escala en hardware de tamaño productivo ([docs/performance.md](docs/performance.md)). La recolección de errores XID sigue abierta bajo la decisión #8.

<a id="access-roles"></a>
## Roles de acceso

| Ámbito | Acceso |
| --- | --- |
| GPU View | Páginas de monitorización existentes. |
| GPU Operator | Monitorización, página Operaciones y confirmaciones personales de clasificación. |
| GPU Admin | Acceso Operator más configuración y acciones administrativas existentes. |

Tras copiar los archivos de actualización, abra Plugins como administrador para registrar el nuevo ámbito. Asigne expresamente Operator en **Configuración > Usuarios** o en grupos de usuarios. Las actualizaciones conservan los identificadores y concesiones existentes de View y Admin. En cada solicitud, Admin hereda Operator y Operator hereda View; esto no agrega concesiones persistentes a usuarios ni grupos. Los ajustes del plugin requieren GPU Admin además del permiso Settings de Cacti.

Los permisos de dispositivo de Cacti siguen limitando las filas y acciones de cada usuario; los objetos sin asignar requieren un administrador. Credenciales, configuración, asignación, borrado y otros cambios administrativos permanecen en las páginas administrativas. Las confirmaciones duran solo en la sesión del usuario actual, como máximo una hora y mientras siga vigente la misma observación. Las observaciones nuevas o modificadas las invalidan. Nunca marcan una medición fallida como correcta ni suprimen alertas.

<a id="why"></a>
## Motivo

Los nodos GPU suelen estar ya en Cacti para CPU, memoria e interfaces. La salud de GPU es otra cuestión que sus datos SNMP no resuelven: una tarjeta puede sufrir limitación térmica, acumular errores ECC o comunicar un fallo XID mientras el nodo parece sano. Las herramientas del fabricante lo comunican (`nvidia-smi`, DCGM, `amd-smi`), pero su salida queda fuera de los gráficos, permisos y alertas de Cacti.

El plugin cubre esa carencia convirtiendo la salida en fuentes de datos Cacti identificadas por la identidad de cada GPU, no por su posición, para que un reinicio que reordene las GPU no asigne el historial de una tarjeta a otra. Reutiliza gráficos, permisos, recolectores remotos, Thold y Maint de Cacti en lugar de crear versiones paralelas.

<a id="planned-scope"></a>
## Alcance previsto

| Fase | Alcance | Estado | Hitos |
|---|---|---|---|
| 0, investigación | Salida de herramientas, señales XID y de limitación, identidad GPU, privilegios y decisión de ruta de recolección | Completo | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Recolección `nvidia-smi`, inventario por UUID, gráficos, señales de limitación y ECC, Thold y Maint, página de estado | Implementado (probado con muestras) | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, backends | Analizador/cliente HTTP(S) de solo lectura para DCGM Exporter (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`), analizador AMD `amd-smi` (`lib/AmdSmiParser.php`) | Implementado (pruebas unitarias) | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, mig, remoto y ecosistema | Seguimiento de identidad de instancias MIG, recolectores de datos remotos (`INFO`, `poller_gpu.php --poller=N`, `replicate_out`, `remote_agent`), CLI CasC (`cli/gpu.php`), hooks MCP/Doctor/CasC | Implementado (pruebas unitarias y de integración CI) | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Fuera de alcance: comandos que cambien una GPU o nodo (relojes, límites de potencia, reinicios, disposición MIG), motor de alertas propio, demonio o protocolo de red personalizado y contabilidad del planificador o de trabajos. Consulte el plan para las investigaciones pendientes.

<a id="what-milestone-4-adds"></a>
## Qué agrega el hito 4

- **Recolector de solo lectura para NVIDIA DCGM Exporter** (`lib/DcgmExporterParser.php`, `lib/DcgmExporterClient.php`). Analiza métricas de exposición de texto de Prometheus (`DCGM_FI_DEV_GPU_UTIL`, `DCGM_FI_DEV_FB_USED`, `DCGM_FI_DEV_FB_FREE`, `DCGM_FI_DEV_POWER_USAGE`, `DCGM_FI_DEV_GPU_TEMP`, `DCGM_FI_DEV_ECC_DBE_VOL_TOTAL`, `DCGM_FI_DEV_CLOCK_THROTTLE_REASONS`, y las etiquetas MIG `GPU_I_ID` y `GPU_I_PROFILE`). El cliente HTTP(S) aplica una lista explícita de hosts o CIDR permitidos, verifica por defecto los certificados TLS del par, limita tiempos de espera y tamaño de respuesta, deshabilita redirecciones y rechaza esquemas que no sean HTTP(S).
- **Analizador de solo lectura para AMD `amd-smi`** (`lib/AmdSmiParser.php`). Normaliza la salida JSON o SNMP-extend de `amd-smi` (utilización, VRAM, potencia, temperatura de unión y borde, ECC, metadatos de partición y acumuladores de limitación separados mediante `Reading::$vendorExtensions`) al modelo común de métricas bajo `Vendor::Amd`.
- **Seguimiento de instancias MIG y Schema v4** (`lib/GpuReport.php`, `lib/GpuSnapshot.php`, `lib/GpuRecord.php`, `lib/Schema.php`, `lib/ScriptServer.php`). Registra los UUID de instancias MIG (`MIG-<uuid>` y `MIG-GPU-<parent>/<gi>/<ci>`), `mig_mode`, `mig_instance_id`, `mig_profile`, `parent_uuid` y `partition_info` junto con las GPU principales, manteniendo protegidas en las actualizaciones y validadas antes de las consultas a base de datos las claves de identidad inmutables (`vendor`, `uuid`, `parent_uuid`).
- **Recolectores de datos remotos** (`INFO`, `poller_gpu.php`, `lib/Poller.php`, `lib/ReplicationRepository.php`, `setup.php`). Declara `remote_collect:1, remote_poller:1` en `INFO`, admite ejecución acotada al sondeador mediante `php plugins/gpu/poller_gpu.php --poller=N`, omite el aprovisionamiento de objetos Cacti en sondeadores no principales, replica las tablas por sondeador mediante `replicate_out` y responde a las acciones de solo lectura `gpu_health` y `gpu_inventory` mediante `remote_agent`.
- **CLI de operador y CasC** (`cli/gpu.php`, `lib/CascProvider.php`). Se ejecuta en PHP 8.2+ (se analiza sintácticamente en PHP 8.0) sin dependencias externas de Composer: `php plugins/gpu/cli/gpu.php export [--format=yaml|xml]`, `validate <file> [--format=yaml|xml]`, `plan <file> [--format=yaml|xml] [--output=text|json]`, `apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]` y `health [--format=text|json]`. Los planes calculan un hash de confirmación SHA-256 determinista, `apply` falla de forma segura si el hash no coincide y el análisis XML rechaza las declaraciones `DOCTYPE` y `ENTITY`.
- **Hooks de integración del ecosistema** (`lib/EcosystemIntegration.php`, `setup.php`). Registra `abilities_register` (`gpu-list-nodes`, `gpu-list-devices`, `gpu-get-health`; de solo lectura, restringido al ámbito `GPU View` y a los permisos de dispositivo de Cacti), `doctor_register_checks` (`gpu_schema`, `gpu_poller_freshness`, `gpu_node_health`) y `casc_register_providers`.
- **Motivos de limitación y ECC como valores de alerta** ([docs/metrics.md](docs/metrics.md)). Decodifica máscaras de eventos de reloj en indicadores 0/1 y calcula los errores ECC corregidos y no corregidos nuevos por intervalo como gauges para que Thold 1.8.2 y Maint 1.3 alerten y supriman sin contadores negativos ni picos tras reinicio ([docs/alerting.md](docs/alerting.md)).
- **Estado de GPU, permisos, fallo/restauración y escala.** Estado de GPU (GPU View) muestra la actualidad almacenada, el resultado de la última recolección, el estado Maint, el backend, las versiones y las máscaras de limitación brutas sin contactar con los nodos. Pruebas automatizadas de extremo a extremo verifican los permisos, la recuperación ante fallos de agente/BD/RRD ([docs/backup.md](docs/backup.md)) y una ejecución a escala de 100 GPU en CI ([docs/performance.md](docs/performance.md), [docs/operations.md](docs/operations.md), [CHANGELOG.md](../../../CHANGELOG.md)).

<a id="what-milestone-3-adds"></a>
## Qué agrega el hito 3

- **Recolección mediante SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). El administrador del nodo agrega dos líneas `extend` fijas a `snmpd.conf` ([docs/node-setup.md](docs/node-setup.md)); el plugin lee NET-SNMP-EXTEND-MIB mediante las funciones SNMP de Cacti y las credenciales del dispositivo; se recomienda SNMPv3. No ejecuta nada en el servidor Cacti ni en el nodo.
- **Análisis estricto.** Los campos se leen por posición, cada línea identifica su GPU por UUID y cualquier estado de salida no cero, tiempo agotado, salida truncada, cantidad de campos incorrecta o texto de error mezclado hace fallar toda la recolección: los valores pasan a `U`, ninguna GPU se marca ausente y Nodos GPU muestra el motivo.
- **Un recolector tras cada ciclo del sondeador.** Lee el agente de cada nodo habilitado, actualiza los registros de identidad y guarda la última muestra en caché. La entrada del servidor de scripts solo lee esa caché, por lo que un sondeo de Cacti nunca espera a un nodo GPU.
- **Objetos Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Las plantillas y la consulta *GPU - Devices* se distribuyen como XML y se importan al instalar y actualizar. Guardar un nodo vincula la consulta a su dispositivo existente con método de reindexación None; Nodos GPU solo puede crear un dispositivo si el nodo no tiene ninguno. Cada GPU es un índice por fabricante y UUID, con una fuente compartida por sus gráficos de Utilización, Memoria, Temperatura, Potencia, Porcentaje de potencia, Relojes, Limitación, Errores ECC, Errores ECC nuevos, Páginas retiradas y Antigüedad de muestra. Cada campo del modelo aparece en algún gráfico, porque Cacti solo guarda campos utilizados por gráficos. El plugin reindexa tras cada cambio de GPU, conserva una reindexación omitida por Cacti mientras el dispositivo estaba caído o desactivado y la ejecuta cuando vuelve a estar activo, y crea gráficos para GPU nuevas.
- **El historial permanece con la tarjeta.** Una GPU ausente sigue siendo un índice, de modo que sus gráficos muestran un hueco y continúan en el mismo RRD cuando vuelve. Una tarjeta de sustitución en la misma ranura recibe su propia fuente. **Eliminar de la consulta de datos**, en la página del nodo, retira una GPU ausente de la lista; sus RRD y gráficos se conservan hasta que los borre.
- **Permisos de dispositivo en Nodos GPU.** GPU Admin solo ve, cambia, exporta y mueve nodos y GPU de dispositivos que puede ver en Cacti; un traslado de GPU requiere ambos nodos. Un nodo cuyo dispositivo se borró puede desactivarse o eliminarse.
- **Modelo común de métricas** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](docs/metrics.md)). La memoria está en bytes, la máscara de eventos de reloj se decodifica en indicadores `thr_*` y `age` es la antigüedad de la hora de muestra propia de la GPU, o `U` si se desconoce.

El usuario que instala (servidor web) debe poder escribir en `<cacti>/resource/script_server/`, donde el plugin coloca el XML de consulta; en caso contrario, instalación y actualización se detienen con un mensaje y reintentan la próxima vez. La desinstalación elimina plantillas y XML solo si se marca *Eliminar los objetos Cacti del plugin al desinstalar*, y nunca elimina RRD, dispositivos, gráficos ni fuentes de datos ([docs/operations.md](docs/operations.md#uninstall)). Los nodos en recolectores de datos remotos se ejecutan acotados a su `poller_id` asignado.

<a id="what-milestone-2-ships"></a>
## Qué incluye el hito 2

- **Instalación protegida.** Con PHP anterior a 8.2 se rechazan instalación y activación con un mensaje que indica la versión requerida; el resto de Cacti sigue funcionando. `setup.php` y los scripts de página se analizan en PHP 8.0.
- **Ciclo de vida.** Instalación, actualización, desactivación y desinstalación desde Plugins de Cacti. Las actualizaciones siguen un orden, terminan tras una interrupción y cambian la versión registrada solo si tienen éxito. La desinstalación conserva las tablas salvo que desmarque *Conservar tablas del plugin al desinstalar*; nunca toca dispositivos, fuentes, gráficos ni RRD de Cacti.
- **Ámbitos separados.** *GPU Admin* abre **Nodos GPU**, donde registra dispositivos Cacti como nodos GPU, confirma o rechaza traslados entre nodos y exporta todos los registros a JSON. *GPU View* abre **Inventario GPU**, que solo enumera GPU de dispositivos visibles para el usuario. Ambas páginas están en Administración y todo cambio es un POST comprobado por csrf-magic.
- **Registros de identidad GPU.** Cada GPU se identifica por fabricante y UUID. Índice, ID de bus PCI, nombre, controlador y herramienta que informa son atributos actualizados en el mismo registro. Un UUID nuevo en una ranura conocida se marca como posible sustitución y comienza su propio historial; una GPU vista en otro nodo solo se mueve tras confirmación de un operador; una GPU ausente en recoleccións correctas se marca ausente tras un número configurable y nunca se elimina. Por ahora solo se aceptan UUID NVIDIA de GPU completas; MIG espera la decisión #13 y AMD la investigación #19. Las reglas se ejercitan con secuencias de muestras porque todavía ningún recolector las alimenta.
- **Pruebas y CI.** Suites unitarias y de reglas de código sin GPU ni base de datos; comprobaciones de ciclo de vida y permisos contra Cacti 1.2.31 y 1.2.x actual con PHP 8.2 a 8.5; tarea de rechazo PHP 8.1; análisis sintáctico de entradas con PHP 8.0; PHPStan nivel 9 y REUSE. El entorno de pruebas es provisional hasta resolver #12.

<a id="install-for-evaluation"></a>
### Instalar para evaluar

Estos pasos instalan el código actual para probarlo; [docs/operations.md](docs/operations.md) contiene la guía completa. Requieren Cacti 1.2.31 o posterior con PHP 8.2 o posterior.

1. Copie el repositorio a `plugins/gpu` bajo el directorio de Cacti. Debe llamarse `gpu`.
2. En Cacti, abra **Configuración > Plugins**, instale y active **GPU**.
3. Asigne *GPU View*, *GPU Operator* o *GPU Admin* en **Configuración > Usuarios**, según la función del usuario. El usuario que instala recibe View y Admin; Admin incluye el acceso Operator.
4. Configure el agente SNMP de cada nodo según [docs/node-setup.md](docs/node-setup.md) y agregue el nodo en **Administración > Nodos GPU**.
5. Para alertas, importe las plantillas Thold incluidas como indica [docs/alerting.md](docs/alerting.md).
6. Opcionalmente ajuste **Configuración > Ajustes > GPU**: *Marcar ausente tras* (3 recoleccións correctas por defecto, de 1 a 100), *Obsoleto tras* (antigüedad antes de mostrar valores `U`) y *Conservar tablas del plugin al desinstalar* (activado por defecto).

<a id="how-metrics-reach-cacti"></a>
## Cómo llegan las métricas a Cacti

La decisión [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) eligió SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): el agente del nodo ejecuta un comando `nvidia-smi` fijo y el plugin solo lee el resultado. El plan compara opciones en [Rutas de recolección](../../PLAN.md#collection-paths).

<a id="compatibility-targets"></a>
## Objetivos de compatibilidad

| Componente | Objetivo |
|---|---|
| Cacti | 1.2.31 o posterior, sin parches del núcleo |
| PHP | 8.2 o posterior; `setup.php` sigue siendo analizable en 8.0 para que un entorno no compatible reciba un rechazo en lugar de romper la interfaz de Cacti |
| NVIDIA | Líneas de controlador y herramienta pendientes de investigación [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26); identificadores DCGM comprobados en v3.3.9 y v4.7.0 |
| AMD | `amd-smi` en versiones ROCm pendientes de investigación [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | Thold 1.8.2 y Maint 1.3 sin cambios, probados de extremo a extremo ([docs/alerting.md](docs/alerting.md)) |

<a id="design"></a>
## Diseño

- [docs/PLAN.md](../../PLAN.md) contiene el plan completo: arquitectura, hechos de proyectos originales de los que depende (con fuentes), modelo de identidad, límites de seguridad, cálculos de rendimiento, pruebas y riesgos.
- [docs/decisions/](../../decisions) contiene los registros de decisiones arquitectónicas ya tomadas.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) relaciona cada identificador de requisito con su incidencia.

```mermaid
flowchart LR
    NODE[GPU node: snmpd extend / DCGM Exporter / amd-smi] -->|Read-only SNMPv3 or allowlisted HTTPS| C[Poller-scoped collector]
    C --> CACHE[(Plugin cache keyed by vendor + GPU/MIG UUID)]
    CACHE --> SS[Script server data query input]
    CACHE --> ECO[CasC CLI, MCP abilities, Doctor checks]
    SS --> RRD[(Cacti RRD files, one data source per GPU)]
    SS -->|poller_output| T[Thold and Maint]
    RRD --> G[Cacti graphs and permissions]
```

<a id="how-work-is-tracked"></a>
## Cómo se sigue el trabajo

- Los **hitos** siguen los del plan. Cada uno tiene una incidencia épica con sus subincidencias y las evidencias de finalización.
- Las **incidencias** se clasifican por etiqueta: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Las de requisito contienen requisitos numerados y verificables (`REQ-AREA-NNN`) y criterios de aceptación.
- Las **etiquetas** también incluyen `area:`, `phase:`, `priority:` y `status:`. Consulte [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- El [tablero del proyecto](https://github.com/users/somethingwithproof/projects/9) muestra todas las incidencias de los hitos.

<a id="contributing"></a>
## Contribuir

Lea [CONTRIBUTING.md](../../../CONTRIBUTING.md). Los commits requieren firma DCO. El diseño se debate en incidencias; las decisiones abiertas llevan `type: decision`. La salida capturada de herramientas GPU reales es la aportación más útil al hito 1; elimine antes nombres de host, números de serie y UUID.

<a id="security"></a>
## Seguridad

Comunique las vulnerabilidades de forma privada; consulte [SECURITY.md](../../../SECURITY.md).

<a id="license"></a>
## Licencia

Licencia Pública General GNU v2.0, la misma que Cacti y sus plugins. Consulte [LICENSE](../../../LICENSE).
