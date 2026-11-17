<!-- Source: docs/node-setup.md; SHA256: 3babf869a8665fbf304f5b8f079391435900cf50ab354addb85d287dd9dacdbc -->

<a id="setting-up-a-gpu-node"></a>
# Configurar un nodo GPU

El complemento lee la salida `nvidia-smi` de cada nodo mediante su agente SNMP y NET-SNMP-EXTEND-MIB ([ADR 0008](../../../decisions/0008-collect-through-snmp-extend.md)). El administrador añade dos entradas `extend` a `snmpd.conf`, y una tercera solo para `nvidia-smi` antiguo. El complemento envía únicamente lecturas usando los ajustes SNMP del dispositivo Cacti del nodo. No se instala nada más que `snmpd` (net-snmp) en el nodo.

<a id="snmpdconf"></a>
## snmpd.conf

Cada nodo necesita estas dos líneas exactamente como aparecen. Conserve los nombres, la lista y el orden de los campos: el complemento los interpreta por posición. Un número incorrecto de campos se rechaza, pero reordenar campos numéricos válidos puede producir una interpretación errónea sin detectarse.

<!-- extend-lines:start -->
```
extend -cacheTime 30 cacti_gpu /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_event_reasons.active --format=csv,noheader,nounits
extend -cacheTime 300 cacti_gpu_names /usr/bin/env -i /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=uuid,name --format=csv,noheader
```
<!-- extend-lines:end -->

Función de cada parte:

- `extend` sin `-execType sh` ejecuta el programa con un vector fijo de argumentos y sin shell. Nada procedente de Cacti, el nodo o una GPU llega a la línea de comandos.
- `/usr/bin/env -i` inicia la herramienta con un entorno vacío. La entrada de métricas solo fija `TZ=UTC`: `nvidia-smi` imprime la hora sin zona y el complemento la interpreta como UTC.
- `/usr/bin/timeout -k 2 10` detiene la herramienta a los 10 segundos y la mata dos segundos después. snmpd espera a su proceso hijo sin tiempo límite propio, por lo que un `nvidia-smi` bloqueado podría bloquear el agente.
- `-cacheTime 30` conserva una muestra 30 segundos. El tiempo límite es menor que esa ventana, de modo que una ejecución acaba antes de comenzar otra. Cada sondeo dentro de ella ve la misma muestra y el complemento informa de su antigüedad real.
- Los nombres GPU se obtienen aparte porque son texto libre y pueden contener el separador CSV. Cambian poco, por lo que esa entrada se guarda en caché cinco minutos.
- Todos los comandos y argumentos están por debajo del límite de 510 bytes de extend.
- Se admiten como máximo 128 GPU por nodo para mantener la salida por debajo de los 100 KiB de snmpd. Una línea midió entre 194 y 197 bytes en los datos de prueba, y 370 si cada métrica imprime `[Not Supported]`: 128 GPU ocupan unos 46 KiB como máximo. La entrada de nombres produce 64 bytes por GPU en esos datos. Por encima del límite, snmpd devolvió estado 4 sin salida en la prueba completa, que se muestra como *Permission denied*. Se desconoce la causa: respete el límite.

El complemento trata como recopilación fallida con valores desconocidos, nunca como datos, los estados de salida distintos de cero, incluidos los tiempos agotados; la salida cortada por los límites de 100 KiB o 1024 líneas; las líneas con un número de campos distinto; y cualquier otro texto, incluidos errores de stderr incorporados por snmpd.

<a id="optional-an-nvidia-smi-older-than-the-clock-event-reasons-rename"></a>
### Opcional: nvidia-smi anterior al cambio de nombre de los motivos de reloj

La entrada solicita `clocks_event_reasons.active` (REQ-NVSMI-005). El historial de nvidia-smi indica que v530 cambió los nombres `clocks_throttle_reasons.*` y v535 restauró las consultas antiguas ([investigación](../../../research/clock-event-reasons.md)); un `nvidia-smi` anterior puede conocer solo el nombre antiguo. No se han confirmado los controladores correspondientes. Esa herramienta rechaza la entrada con estado 2, argumento inválido, y la recopilación falla hasta añadir también esta línea:

<!-- extend-legacy:start -->
```
extend -cacheTime 30 cacti_gpu_legacy /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_throttle_reasons.active --format=csv,noheader,nounits
```
<!-- extend-legacy:end -->

`cacti_gpu_legacy` usa el nombre antiguo `clocks_throttle_reasons.active` en la misma posición. Se lee solo si `cacti_gpu` termina con estado 2, por lo que nunca se ejecuta con un controlador actual. Ambos nombres imprimen la misma máscara y se decodifican igual. Un nodo configurado con una versión anterior de esta página debe sustituir su línea principal de métricas por la consulta `cacti_gpu_legacy` mostrada arriba, cambiando solo su nombre de entrada a `cacti_gpu` y manteniendo `clocks_throttle_reasons.active` como último campo: esta versión añade `ecc.mode.current`, `retired_pages.sbe` y `retired_pages.dbe`. Hasta sustituirla, el número de campos no coincide y aparece *Output rejected*. La alternativa de estado 2 no cubre ese caso porque la línea antigua se ejecuta correctamente. Solo puede diferir el nombre de los motivos de reloj: también funciona una línea `cacti_gpu` con la lista actual y `clocks_throttle_reasons.active` al final, porque ambos nombres producen la misma máscara.

<a id="run-snmpd-as-an-unprivileged-user"></a>
## Ejecutar snmpd sin privilegios

Un comando `extend` usa el usuario de `snmpd`, que es root si no se establece `agentuser` o `-u`. Los campos anteriores no necesitan privilegios ([research/privileges.md](../../../research/privileges.md)). Ejecute el agente con una cuenta sin privilegios:

```
agentuser Debian-snmp
```

Use la cuenta del paquete de su distribución, o cree una. Debian y Ubuntu proporcionan `Debian-snmp` e inician snmpd con `-u Debian-snmp`. El complemento no puede ver qué usuario ejecutó el comando; confírmelo durante una recopilación, por ejemplo con `ps -o user= -C nvidia-smi`. Los usuarios predeterminados de cada distribución están pendientes de comprobación real en #22.

<a id="snmpv3-is-the-default"></a>
## SNMPv3 como opción predeterminada

Use SNMPv3 con autenticación y privacidad y limite al usuario al grupo system y las tablas extend:

```
view   cactigpu included .1.3.6.1.2.1.1
view   cactigpu included .1.3.6.1.4.1.8072.1.3.2
rouser -s usm cactigpu priv -V cactigpu
```

Cree el usuario con `net-snmp-create-v3-user`, o añada `createUser cactigpu SHA-256 <auth passphrase> AES <privacy passphrase>` a la configuración persistente mientras snmpd esté detenido (`/var/lib/snmp/snmpd.conf` en Debian y Ubuntu). Configure después el dispositivo Cacti con SNMP versión 3, el mismo usuario, autenticación SHA-256 y privacidad AES.

SNMP v1 y v2c también funcionan, pero transmiten la comunidad y todos los valores en texto claro. Si los utiliza, limite la comunidad a la misma vista y dirección del sondeador, por ejemplo `rocommunity <community> <poller address> -V cactigpu`.

<a id="in-cacti"></a>
## En Cacti

1. Compruebe que el nodo es un dispositivo Cacti con SNMP operativo. Si no, GPU Nodes puede crearlo con los valores predeterminados del sitio; ajuste después SNMP en su página de dispositivo.
2. En **Console > Management > GPU Nodes**, añada un nodo para el dispositivo. Al guardar se añade la consulta GPU.
3. El recopilador se ejecuta al final de cada ciclo de sondeo. Cada GPU encontrada se convierte en un índice de la consulta y el complemento crea sus gráficos.

Los nodos en recopiladores remotos todavía no se recopilan; corresponden a la fase 3.

<a id="an-snmp-port-other-than-161-on-cacti-12x"></a>
### Un puerto SNMP distinto de 161 en Cacti 1.2.x

La rama 1.2.x posterior a 1.2.31 pone la dirección entre corchetes para un get SNMP individual cuando el puerto no es 161, como `[192.0.2.10]:1161`. La extensión SNMP de PHP interpreta ese nombre como IPv6, por lo que falla con IPv4 o un nombre sin dirección IPv6. El registro muestra `Unknown failure while resolving '[192.0.2.10]:1161'`. Cacti 1.2.31 y los nodos en puerto 161 no se ven afectados.

El complemento utiliza una sesión SNMP Cacti como su sondeador, que solo pone corchetes a direcciones con dos puntos; así la recopilación funciona en cualquier puerto. Sin embargo, la comprobación de disponibilidad de Cacti usa el get individual: con cmd.php y otro puerto el nodo se marca caído y el complemento espera para reindexarlo. Hasta que Cacti lo corrija, en 1.2.x mantenga el agente en 161 o configure Downed Device Detection como Ping o None. La prueba completa utiliza esta última opción.
