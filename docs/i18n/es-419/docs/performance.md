<!-- Source: docs/performance.md; SHA256: bde5c9d411b29e59818e12c1b4830dd2ac947cba5222b2e1fb366219a59113eb -->

<a id="performance-and-scale"></a>
# Rendimiento y escala

Mediciones para #49 (REQ-PERF-001 a REQ-PERF-004), tomadas con `tests/bin/scale-flow.php`. Proceden de una estación de desarrollo, no de hardware productivo. Interprételas como un límite superior de sobrecarga del complemento, no como capacidad admitida: un servidor Cacti de producción, su base de datos y la red a nodos reales son diferentes.

<a id="machine"></a>
## Máquina

| Elemento | Valor |
|---|---|
| Host | Apple M3 Max, 14 núcleos y 36 GiB de memoria |
| Entorno de contenedores | OrbStack (Docker 29.8.2), 14 CPU y 15.7 GiB para su máquina virtual |
| Cacti | 1.2.31 y rama 1.2.x, PHP 8.2, MariaDB en un contenedor del mismo host |
| Agente de nodo | Un `snmpd` real de net-snmp en contenedor, SNMPv3, para todos los nodos; el `nvidia-smi` simulado imprime el conjunto de ocho GPU |

Todos los nodos comparten agente en el mismo host: la latencia es casi cero y el costo por nodo del agente se cuenta una vez por lectura. Un despliegue real agrega una ida y vuelta por solicitud SNMP y el costo de `nvidia-smi` en cada nodo (#25).

<a id="method"></a>
## Método

La prueba registra `<gpus> / 8` dispositivos Cacti como nodos GPU del mismo agente. Un lector de prueba envuelve al lector SNMP extend y modifica cada UUID con el identificador del dispositivo para que cada nodo tenga sus propias GPU; cada lectura sigue siendo un intercambio SNMPv3 real. Mide dos rondas de recopilación, la primera de registro y la segunda estable; la entrada del servidor de scripts, con una llamada index por nodo y get por elemento de fuente como hace Cacti; y el renderizado de GPU Health, verificando que no envía paquetes SNMP. Con `provision` también crea objetos Cacti mediante el aprovisionador, ejecuta un ciclo de sondeo y comprueba que el presupuesto detiene una ronda anticipadamente y cuenta los nodos omitidos.

<a id="results"></a>
## Resultados

Todavía no registrados. CI ejecuta el caso de 100 GPU con objetos Cacti en cada tarea de la matriz. La ejecución de 1,000 GPU con objetos y la recopilación de 8,000 GPU en esta máquina se documentarán aquí cuando se realicen, junto con el número de filas. Hasta entonces REQ-PERF-001 sigue abierto y no se declara un número de GPU admitido.

<a id="what-this-supports"></a>
## Qué respaldan estos datos

Nada todavía. Los cálculos previstos para 1,000 nodos con ocho GPU están en [PLAN.md](../../../PLAN.md#performance). Solo se declarará una cantidad admitida tras medirla aquí y confirmarla en hardware de tamaño productivo.

<a id="not-measured"></a>
## No medido

- Coste real de `nvidia-smi` por nodo e intervalo mínimo (#25).
- Base de datos remota, servidor Cacti productivo y latencia hacia nodos.
- Páginas propias de dispositivos, fuentes y gráficos de Cacti con 8,000 GPU (REQ-PERF-004): esa ejecución no aprovisionó objetos Cacti.
