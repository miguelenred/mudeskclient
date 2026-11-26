# Rendimiento: ancho de banda, CPU y memoria por conexión

[English](../PERFORMANCE.md)

Cuánto consume **una** conexión según el códec y la calidad de color, separando lo que gasta **PHP** (la pasarela, en el hosting) de lo que gasta **el navegador** (decodificar y componer la pantalla).

## Cómo se ha medido

- **Servidor:** Windows 11 Pro 23H2 con NLA, escritorio de 1024x768, efectos visuales mínimos (sin fondo ni temas, como la conexión por defecto) y tipo de conexión LAN.
- **Herramienta:** `node tests/bench.mjs` (se puede repetir con cualquier servidor). La pasarela es `tests/rdp_probe.php` en modo `PIPE`, que usa las mismas clases que `rdp.php` y recibe entrada, cambios de tamaño y confirmaciones de fotograma igual que con el navegador. El navegador se sustituye por Node.js con **los mismos decodificadores** del navegador (`gfx.js`, `orders.js`, `bulk.js`, `rdpgfx.js`, `rfx.js`), y el escritorio se compone en memoria como lo haría el canvas.
- **Equipo:** servidor, pasarela y decodificadores en el mismo PC de 2 núcleos. Los porcentajes de CPU son de **un núcleo** (100 % = un núcleo entero) y compiten entre sí: en un despliegue real el servidor, el hosting y el navegador están en máquinas distintas y las cifras de cada uno serán iguales o mejores.
- **Cargas** (cada medida empieza con la sesión ya abierta y la carga en marcha):
  - **Reposo:** escritorio quieto (solo el reloj y el cursor).
  - **Texto:** una consola listando `C:\Windows` sin parar (texto desplazándose a pantalla casi completa: el caso más duro para el texto).
  - **Ventana:** una ventana del Explorador arrastrada en círculos (lo que más se nota al trabajar).
  - **Animación:** el salvapantallas «Cintas» a pantalla completa (lo más parecido a vídeo).
- **Columnas:**
  - *Servidor → PHP:* bytes TCP/TLS recibidos del servidor RDP.
  - *PHP → navegador:* bytes de las tramas que la pasarela envía al navegador (sin cabeceras HTTP; la compresión RDP se reenvía tal cual).
  - *PHP: CPU / RAM pico:* CPU de usuario + sistema del proceso PHP durante la medida, y `memory_get_peak_usage(true)` de la conexión.
  - *Navegador: CPU decodificar:* tiempo de los decodificadores JavaScript (en el navegador corren en un Web Worker y no bloquean la entrada) más la composición. No incluye pintar en el canvas ni la decodificación de H.264, que hace WebCodecs (normalmente en la GPU).
  - *Navegador: RAM decodificadores:* memoria JavaScript y de búferes que añaden los decodificadores y el escritorio en memoria (aproximadamente lo que crece la pestaña).
  - *Fotogramas/s:* solo existe en el canal gráfico (los mapas de bits no se envían por fotogramas).

Para repetir una medida:

```sh
BPP=16 COMPRESS=3 NOORDERS=1 node tests/bench.mjs equipo 3389 usuario clave drag 15
GFX=avc BPP=32 NOORDERS=1 node tests/bench.mjs equipo 3389 usuario clave anim 15
```

(`COMPRESS`: 0 nada, 1 MPPC, 2 NCRUSH, 3 XCRUSH; sin `NOORDERS` se activan las órdenes de dibujo; `GFX=1` canal gráfico, `GFX=avc` con H.264. Las cargas escriben en el cuadro «Ejecutar» de una sesión de Windows en español.)

## Resultados

### Reposo (escritorio quieto, 10 s)

| Modo | Servidor → PHP (KB/s) | PHP → navegador (KB/s) | PHP: CPU | PHP: RAM pico | Navegador: CPU decodificar | Navegador: RAM decodificadores | Fotogramas/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Mapas de bits 16 bits, sin compresión | 0 | 0 | 0,2 % | 2 MB | 0,0 % | 3 MB | — |
| Mapas de bits 16 bits, MPPC | 0 | 0 | 0,2 % | 2 MB | 0,0 % | 10 MB | — |
| Mapas de bits 16 bits, NCRUSH | 0 | 0 | 0,0 % | 2 MB | 0,0 % | 8 MB | — |
| Mapas de bits 16 bits, XCRUSH (por defecto) | 0 | 0 | 0,0 % | 2 MB | 0,0 % | 11 MB | — |
| Mapas de bits 15 bits, XCRUSH | 0 | 0 | 0,6 % | 4 MB | 0,0 % | 6 MB | — |
| Mapas de bits 24 bits, XCRUSH | 0 | 0 | 0,0 % | 4 MB | 0,0 % | 10 MB | — |
| Mapas de bits 32 bits, XCRUSH | 0 | 0 | 0,0 % | 6 MB | 0,0 % | 16 MB | — |
| 16 bits, XCRUSH + órdenes de dibujo | 0 | 0 | 0,0 % | 2 MB | 0,0 % | 8 MB | — |
| Canal gráfico (ClearCodec, RemoteFX progresivo) | 0 | 0 | 0,0 % | 2 MB | 0,0 % | 18 MB | — |
| Canal gráfico + H.264 | 0 | 0 | 0,0 % | 2 MB | 0,0 % | 20 MB | — |

### Texto desplazándose (consola listando C:\Windows, 15 s)

| Modo | Servidor → PHP (KB/s) | PHP → navegador (KB/s) | PHP: CPU | PHP: RAM pico | Navegador: CPU decodificar | Navegador: RAM decodificadores | Fotogramas/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Mapas de bits 16 bits, sin compresión | 3143 | 3142 | 1,9 % | 2 MB | 18,1 % | 39 MB | — |
| Mapas de bits 16 bits, MPPC | 481 | 481 | 30,2 % | 2 MB | 15,6 % | 82 MB | — |
| Mapas de bits 16 bits, NCRUSH | 197 | 196 | 7,9 % | 2 MB | 5,9 % | 52 MB | — |
| Mapas de bits 16 bits, XCRUSH (por defecto) | 119 | 119 | 6,5 % | 8 MB | 7,4 % | 75 MB | — |
| Mapas de bits 15 bits, XCRUSH | 278 | 278 | 16,0 % | 8 MB | 14,1 % | 79 MB | — |
| Mapas de bits 24 bits, XCRUSH | 208 | 208 | 12,7 % | 8 MB | 20,9 % | 87 MB | — |
| Mapas de bits 32 bits, XCRUSH | 260 | 259 | 14,1 % | 8 MB | 20,4 % | 100 MB | — |
| 16 bits, XCRUSH + órdenes de dibujo | 119 | 119 | 6,4 % | 8 MB | 8,9 % | 90 MB | — |
| Canal gráfico (ClearCodec, RemoteFX progresivo) | 60 | 59 | 0,8 % | 2 MB | 7,1 % | 44 MB | 20,9 |
| Canal gráfico + H.264 | 38 | 38 | 0,6 % | 2 MB | 4,2 % | 29 MB | 11,5 |

### Ventana moviéndose en círculos (15 s)

| Modo | Servidor → PHP (KB/s) | PHP → navegador (KB/s) | PHP: CPU | PHP: RAM pico | Navegador: CPU decodificar | Navegador: RAM decodificadores | Fotogramas/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Mapas de bits 16 bits, sin compresión | 1224 | 1224 | 1,7 % | 2 MB | 9,6 % | 34 MB | — |
| Mapas de bits 16 bits, MPPC | 203 | 203 | 12,2 % | 2 MB | 8,6 % | 69 MB | — |
| Mapas de bits 16 bits, NCRUSH | 328 | 328 | 15,3 % | 2 MB | 16,0 % | 74 MB | — |
| Mapas de bits 16 bits, XCRUSH (por defecto) | 150 | 150 | 6,5 % | 8 MB | 8,9 % | 66 MB | — |
| Mapas de bits 15 bits, XCRUSH | 170 | 170 | 8,6 % | 8 MB | 10,0 % | 79 MB | — |
| Mapas de bits 24 bits, XCRUSH | 503 | 503 | 24,2 % | 8 MB | 14,6 % | 86 MB | — |
| Mapas de bits 32 bits, XCRUSH | 424 | 424 | 18,2 % | 8 MB | 15,0 % | 88 MB | — |
| 16 bits, XCRUSH + órdenes de dibujo | 60 | 60 | 2,7 % | 6 MB | 8,2 % | 80 MB | — |
| Canal gráfico (ClearCodec, RemoteFX progresivo) | 35 | 34 | 2,1 % | 2 MB | 8,5 % | 60 MB | 19,4 |
| Canal gráfico + H.264 | 113 | 111 | 0,4 % | 2 MB | 11,4 % | 45 MB | 13,9 |

### Animación a pantalla completa (salvapantallas Cintas, 15 s)

| Modo | Servidor → PHP (KB/s) | PHP → navegador (KB/s) | PHP: CPU | PHP: RAM pico | Navegador: CPU decodificar | Navegador: RAM decodificadores | Fotogramas/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Mapas de bits 16 bits, sin compresión | 5400 | 5398 | 2,7 % | 2 MB | 24,5 % | 40 MB | — |
| Mapas de bits 16 bits, MPPC | 862 | 862 | 38,2 % | 10 MB | 10,2 % | 80 MB | — |
| Mapas de bits 16 bits, NCRUSH | 886 | 885 | 37,4 % | 12 MB | 16,5 % | 86 MB | — |
| Mapas de bits 16 bits, XCRUSH (por defecto) | 907 | 907 | 44,0 % | 16 MB | 10,6 % | 100 MB | — |
| Mapas de bits 15 bits, XCRUSH | 799 | 798 | 40,2 % | 16 MB | 12,4 % | 97 MB | — |
| Mapas de bits 24 bits, XCRUSH | 1222 | 1221 | 52,2 % | 14 MB | 14,0 % | 111 MB | — |
| Mapas de bits 32 bits, XCRUSH | 1152 | 1151 | 52,5 % | 16 MB | 13,8 % | 107 MB | — |
| 16 bits, XCRUSH + órdenes de dibujo | 751 | 750 | 35,5 % | 14 MB | 9,6 % | 92 MB | — |
| Canal gráfico (ClearCodec, RemoteFX progresivo) | 174 | 171 | 1,4 % | 2 MB | 105,3 % | 110 MB | 17,1 |
| Canal gráfico + H.264 | 201 | 197 | 0,8 % | 2 MB | 41,9 % + H.264 por hardware | 64 MB | 22,3 |

Las cargas no son exactamente repetibles (la consola lista a distinta velocidad según la CPU libre): diferencias de menos de un 30 % entre dos modos no son significativas.

## Conclusiones

- **PHP (el hosting).** Con mapas de bits comprimidos, PHP descomprime todo para mantener el historial común con el servidor: 6–15 % de un núcleo con texto o ventanas y 35–52 % con animación a pantalla completa. Sin compresión gasta solo un 2–3 %, pero manda entre 6 y 26 veces más datos que con XCRUSH. **Con el canal gráfico gasta un 0,4–2 % en todos los casos**: es el modo que más sesiones permite por servidor. La memoria de PHP por conexión es de 2–16 MB.
- **Ancho de banda.** El canal gráfico es el que menos gasta con texto (38–60 KB/s frente a 119 de XCRUSH) y al mover ventanas (35 KB/s frente a 150; 60 con las órdenes de dibujo). Con la animación, 170–200 KB/s frente a 750–1200 de los mapas de bits comprimidos y 5,4 MB/s sin comprimir. En reposo nada consume: el servidor solo envía lo que cambia.
- **Navegador.** La CPU de decodificación es moderada (4–25 %) salvo con la animación en RemoteFX progresivo (≈100 % de un núcleo en este equipo, ya tras hacer ese decodificador 5,6 veces más rápido; en el navegador va en un Web Worker y el canvas compone más rápido que esta prueba). Con H.264 baja al 42 % porque el vídeo lo decodifica WebCodecs (normalmente la GPU). La memoria de los decodificadores va de 30 a 110 MB.
- **Calidad de color.** 16 bits es el mejor compromiso: 24 y 32 bits casi duplican el tráfico y la CPU de PHP con mapas de bits; 15 bits no ahorra de forma apreciable frente a 16. El canal gráfico siempre trabaja a 32 bits y aun así gasta menos.
- **Compresión.** XCRUSH (la que se ofrece por defecto) da el menor tráfico con texto y ventanas; MPPC y NCRUSH gastan más CPU de PHP con menos ahorro.
- **Órdenes de dibujo** (activadas por defecto): con XCRUSH, mover ventanas pasa de 150 a 60 KB/s y la CPU de PHP del 6,5 al 2,7 %. Sin restos: tras mover ventanas y desplazar texto, a 16 y 32 bits, la pantalla no cambia ni un píxel cuando se pide al servidor que la repinte entera (`REFRESH_CHECK=1`).
- **H.264.** Con este Windows (sin tarjeta gráfica, compartiendo 2 núcleos con la propia prueba) Windows codifica H.264 por programa y da algo menos de fotogramas al mover ventanas o desplazar texto, aunque descarga al navegador. Con la animación da más fotogramas (22 frente a 17) con mucha menos CPU en el navegador. En un servidor con tarjeta gráfica o más núcleos se espera que gane en todos los casos.

## Recomendación según el servidor

| Servidor | Mejor opción | Por qué |
|---|---|---|
| Windows 8.1 / Server 2012 R2 o posterior | Canal gráfico, con H.264 si el navegador lo decodifica | Menos tráfico y casi nada de CPU en PHP; H.264 libera la CPU del navegador (clave en móviles) |
| Windows 7, 8, Server 2008 R2 / 2012 | Mapas de bits de 16 bits con XCRUSH y órdenes de dibujo | Sin canal gráfico útil (o sin H.264) |
| Windows XP, Server 2003 | Mapas de bits de 16 bits con MPPC (la única compresión que tienen) y órdenes de dibujo | Sin canal gráfico; con la *seguridad RDP estándar*, PHP además descifra todo con RC4 |
| xrdp | Mapas de bits de 16 bits con MPPC (lo único que admite) y órdenes de dibujo | xrdp 0.9 no tiene canal gráfico |

La opción **Gráficos: Automático** (la predeterminada) elige así: canal gráfico (con H.264 si el navegador lo decodifica) cuando el inicio de sesión NLA indica Windows 8.1 / Server 2012 R2 o posterior, y mapas de bits con la calidad de color de la conexión en los demás casos. Una conexión RemoteApp usa siempre mapas de bits y órdenes de dibujo (sin canal gráfico).

## Otras medidas (segunda revisión)

| Qué | Antes | Después |
|---|---:|---:|
| RemoteFX progresivo, dos rectángulos en esquinas de la pantalla: cajas decodificadas | 8,3 MB | 33 KB (una caja por rectángulo) |
| Lo mismo: worker / hilo principal en Chrome | 0,93 / 17,8 ms | 0,51 / 6,7 ms |
| Unidad compartida con 3000 ficheros: abrirla (PHP) | 205–238 ms | 0,2–1,4 ms |
| Lo mismo: consultas de espacio libre (PHP) | 213–233 ms | 0,1–0,4 ms |
| Exportar 20 000 filas de la auditoría: memoria de PHP | unos 71 MB | unos 20 MB (fila a fila) |
| Lo mismo, JSON: tiempo | 0,32–0,36 s (sin firmar) | 0,21–0,23 s (firmado) |
| Árbol de carpetas del explorador, 1.000 carpetas y 20.000 archivos (Windows) | 2,4 s (todas) | 64 ms (solo las abiertas; listar una carpeta, 33 ms, como antes) |
| Auditoría, un día de 60 MB (150.000 líneas): memoria de PHP de una búsqueda, el aviso al entrar y *Verificar integridad* | 128 MB | 3,7 MB (línea a línea) |
| Lo mismo: tiempo de una búsqueda / el aviso al entrar / *Verificar integridad* | 0,27–0,49 / 0,10–0,13 / 0,90–1,03 s | 0,31–0,41 / 0,002 / 0,95–1,47 s |

Si los rectángulos cubren al menos la mitad de su caja, el progresivo se decodifica igual que antes (una sola caja). La unidad compartida lee el contador de cuota del usuario en vez de sumar la carpeta.

## Tercera revisión: latencia de la imagen y de la entrada

Medido en este equipo (el envío del navegador, sobre dobles del navegador con un reloj virtual: cada petición a la pasarela tarda 200 ms ± 30 %, un movimiento del ratón cada 16 ms, 30 fotogramas/s).

| Qué | Antes | Después |
|---|---:|---:|
| Un lote de actualizaciones comprimidas (los vectores de FreeRDP) llegando al navegador: MPPC 40,5 KB / NCRUSH 107 KB / XCRUSH 21 KB | a los 20 / 128 / 97 ms | a los 0,11 / 0,07 / 0,39 ms (la misma descompresión, hecha después) |
| Confirmaciones de fotogramas del canal gráfico mientras se mueve el ratón: el mayor tiempo sin ninguna | 771 ms | 259–299 ms |
| Una tecla pulsada mientras se mueve el ratón: espera, mediana / máxima | 31 / 120 ms | 0 / 21 ms |
| La entrada llegando a la sesión en un servidor Windows | hasta 20 ms (fichero de cola) | al momento (puerto TCP de este equipo) |
| Listar una carpeta en el gestor de archivos, con 20 000 archivos en la carpeta del usuario | 7,5–17 s (recorrida entera) | milisegundos (el contador de la cuota; se recorre al abrirlo o actualizarlo) |
| Guardar una conexión del administrador para 100 usuarios | 1,1 s | unos 2 ms |
| Una copia de 64 × 16 píxeles dentro de una pantalla de 1920 × 1080 (una línea de consola desplazándose) | 6,7 ms (el lienzo copiado entero) | 12 µs (a través de un lienzo intermedio) |
| 600 desplazamientos de consola en una ventana de 800 × 600 en 1080p, tiempo de dibujo | 5,5–6,3 s | 1,2–1,3 s |
| Animación con RemoteFX progresivo (1366 fotogramas): el hilo de la página ocupado | 6,6–7,2 s | 2,0–2,6 s |
| La misma grabación decodificada en el worker | 2,08–2,09 s | 1,70–1,90 s (los mismos píxeles: el mismo hash) |
| Búsqueda en la auditoría en un día de 110 000 líneas (el texto se busca ahora en lo que muestra cada línea), mejor de 12: texto que no está / un usuario / un nombre de conexión / un evento / un motivo / una letra que está en todas | 130 / 158 / 156 / 199 / 162 / 229 ms | 111 / 137 / 149 / 184 / 166 / 329 ms |

Las cifras del lienzo se midieron en Edge dibujando por software (este equipo no tiene tarjeta gráfica). Guardar la caché de mapas de bits como datos de imagen en vez de un lienzo por entrada era diez veces más rápido ahí, pero no se ha adoptado: con tarjeta gráfica cada copia vuelve a subir la imagen entera, y eso no se ha podido medir aquí.

## Cuarta revisión (1.1): decodificadores, entrega a la página y equipos lentos

Medido en el equipo de pruebas: 2 CPU virtuales y sin tarjeta gráfica (Edge dibuja el lienzo por software). «Antes» y «después» son el código antes y después de esta revisión, ejecutados por turnos con los mismos datos; las ejecuciones varían un ±10–20 %, así que las diferencias menores son ruido. Todos los decodificadores dan lo mismo antes y después (los mismos bytes, los mismos píxeles).

**La pasarela (PHP)**, descomprimiendo actualizaciones grabadas de un servidor FreeRDP (una animación, texto desplazándose y una ventana arrastrada, con cada compresión) y los vectores de prueba de los compresores de FreeRDP:

| Qué | Antes → después |
|---|---|
| Descompresión MPPC | de 2,0 a 2,5 veces más rápida |
| Descompresión XCRUSH: una animación a 16 y 32 bits / texto desplazándose y una ventana arrastrada / sus vectores de prueba (su nivel 2 es MPPC) | 2,1–2,2 / 1,8 / 11,6 veces más rápida; su nivel 1 (las copias de su historial de 2 MB), un 13–19 % más rápido |
| Descompresión NCRUSH | de 1,18 a 1,37 veces más rápida |
| RC4, 1 MB en trozos de 12 bytes a 16 KB | de 1,35 a 1,86 veces más rápido |
| Seguridad RDP estándar: 1 MB de PDU del servidor abiertas (RC4 y MAC), en trozos de 20 bytes a 16 KB | de 1,26 a 1,84 veces más rápido |
| Memoria de PHP de una sesión | 0,04–0,08 MB más en su pico |
| Una vuelta del bucle de la sesión (Windows) | 15,6–17 µs → 7,3–9,3 µs (las conexiones nuevas de entrada se buscan solo cuando el socket de escucha se puede leer, o 20 ms después de la última búsqueda) |
| Una petición de entrada o de confirmación de fotograma (Windows, servidor integrado de PHP) | unos 15 ms sin OPcache, 5 ms con él ([HOSTING](HOSTING.md#requisitos)) |

**Los decodificadores del navegador** (los de la página, ejecutados por Node.js con flujos grabados, como hace `tests/bench.mjs`; el tiempo de decodificar cada grabación, el mejor de 7):

| Grabación | Antes | Después |
|---|---:|---:|
| Mapas de bits de 16 bits: un listado de consola / otra grabación / una animación | 684 / 518 / 188 ms | 510 / 342 / 130 ms |
| Una ventana arrastrada a 24 / 15 bits | 162 / 38 ms | 99 / 27 ms |
| Mapas de bits de 32 bits (planar) | 460 ms | 331 ms |
| Canal gráfico, planar: una ventana arrastrada / texto | 436 / 112 ms | 321 / 75 ms |
| Canal gráfico, sin comprimir | 72 ms | 41 ms |
| RemoteFX: decuantificación de un componente | 5,83 µs | 2,58 µs |
| RemoteFX progresivo | 4249 ms | 3317 ms (un 22 % menos) |
| El canal gráfico entero, dos animaciones en progresivo | 4014 / 1107 ms | 2912 / 838 ms |
| ZGFX | 280 ms | 130 ms |
| ClearCodec (con su NSCodec), una animación de Windows 11 | 324 ms | 294 ms |
| XCRUSH en el worker: una animación / una ventana arrastrada | 323 / 280 ms | 203 / 188 ms |
| MPPC, texto desplazándose | 16,3 ms | 12,3 ms |
| NCRUSH, sus vectores de prueba | 3,08 ms | 2,39 ms |

Su memoria: como mucho 256 KB de espacio de trabajo por búfer en `gfx.js` y unos 20 KB de tablas en `rfx.js`, con menos recogidas de basura.

**Entrega a la página** (Edge, el hilo de la propia página):

| Qué | Antes | Después |
|---|---:|---:|
| Una operación del canal gráfico de 2, 4 y 8 zonas a 1080p (un relleno, de la caché a la superficie, de superficie a superficie) | 5,5–43 ms | 0,2–0,6 ms |
| Un fotograma H.264 de 2, 4 y 8 zonas | 15–109 ms | 4,7–6,4 ms |
| Mapas de bits de cuatro grabaciones (la primera, el listado de consola a 16 bits): el hilo de la página ocupado | 371 / 463 / 407 / 233 ms | 210 / 235 / 265 / 151 ms |
| Lo mismo: la reproducción entera | 2,6 / 3,0 / 2,2 / 1,5 s | 2,0 / 2,2 / 1,7 / 1,1 s |

El canal gráfico pinta una operación de varias zonas, y un fotograma H.264, una sola vez a través de todas ellas (cada vez que se pintaba un fotograma se convertía entero); los mapas de bits de una actualización llegan juntos a la página, en mensajes de 64 mapas de bits y unos 4 millones de píxeles como mucho (ver [ARQUITECTURA](ARQUITECTURA.md#pantalla)).

**Equipos lentos** (Edge limitado al 25 % y al 16,7 % de la CPU: una cuarta y una sexta parte; las grabaciones enviadas tan deprisa como la página las toma, o a su propio ritmo):

| Qué | Antes (25 % / 16,7 %) | Después (25 % / 16,7 %) |
|---|---:|---:|
| Una animación de 1024 × 768 por el canal gráfico, 138 fotogramas: fotogramas por segundo | 15,2 / 9,6 | 18,4 / 12,3 |
| Lo mismo, 401 fotogramas | 18,4 / 9,7 | 19,9 / 12,3 |
| Un listado de consola a 16 bits (24 388 mapas de bits): tiempo en mostrarlo entero | 9,4 / 16,5 s | 5,4 / 8,8 s |
| Mapas de bits enviados a su ritmo: de su llegada a su dibujo, mediana | 1072 / 1842 ms | 617 / 683 ms |

Con un servidor que marca el ritmo de sus fotogramas por las confirmaciones (el canal gráfico), su latencia se mantiene acotada (no crece a lo largo de la sesión), y de la llegada de los bytes a los píxeles dibujados pasan 19–21 ms (mediana).

Límite conocido: una ráfaga de cientos de desplazamientos a pantalla completa de un servidor antiguo (órdenes ScrBlt) se acumula en la página hasta que se dibuja; la espera está acotada por la ráfaga, no crece más allá. Frenar al servidor mientras la página se pone al día se probó y se descartó: un 12–25 % menos de rendimiento.

## Micrófono

Medido en el equipo de pruebas (Windows 11 Pro, Edge con su micrófono simulado, 30 s de *Configuración → Sonido → Probar el micrófono*):

| Qué | Medido |
|---|---|
| Formato que pide Windows 11 | PCM de 16 bits, 44,1 kHz estéreo (el único sin comprimir que ofrece), paquetes de 441 muestras (10 ms) |
| Tráfico de subida mientras un programa graba | 176 KB/s (1,4 Mbit/s); nada mientras ninguno graba |
| Peticiones de la página | unas 10 por segundo (trozos de 100 ms), en los mismos 4 huecos de peticiones que la entrada; mediana 22 ms, el 90 % por debajo de 36 ms |
| Audio enviado por la página / entregado a Windows | 29,0 s / 29,3 s en 30 s (el primer medio segundo es el navegador abriendo el micrófono); bloques de 10 segundos de 9,9–10,1 s |
| Desde que el programa remoto lo pide hasta el primer audio | alrededor de medio segundo (el navegador abriendo el micrófono; la primera vez el navegador pregunta) |
| Nivel que mide Windows | 99 % con el tono del micrófono simulado (3 % antes, sin micrófono) |

La página remuestrea en un audio worklet (con un filtro paso bajo de 4.º orden antes cuando baja la frecuencia) y une los trozos que esperan a enviarse, como mucho un segundo: con una red lenta el equipo remoto oye un poco más tarde, nunca cada vez más tarde.

## Cámara y una carpeta de este equipo (1.1)

Medido en el equipo de pruebas (Windows 11 Pro), a través del servidor de desarrollo en el mismo equipo:

| Qué | Medido |
|---|---|
| Cámara: formato que eligió Windows | Motion JPEG 640 × 480 a 30 por segundo (el primero ofrecido); una Sample Request cada vez |
| Cámara: imágenes y tráfico de subida | Cámara simulada de Edge: 19–24 imágenes por segundo, unos 8 KB cada una, 150–185 KB/s; la de Firefox: unas 12 por segundo, unos 6 KB. Las de una cámara real son mayores (unos 30–50 KB a 640 × 480 según una estimación, unos 0,5–1,5 MB/s); con una conexión más lenta llegan menos imágenes, nunca más tarde |
| Cámara: la entrada mientras tanto | sin retrasos: las imágenes van por un carril propio, sin orden, dos peticiones como mucho |
| Cámara: la página en otra pestaña | el mismo ritmo: las imágenes las marca el temporizador de un worker (los de una página oculta van como mucho una vez por segundo) |
| Carpeta: listar 155 archivos (`Get-ChildItem` de PowerShell) | 12,96 s con un nombre por petición (cada uno un viaje al navegador, unos 84 ms aquí), 1,0 s con los nombres enviados junto al primero y respondidos por la pasarela |
| Carpeta: leer o escribir un archivo | en trozos de hasta 1 MB (lectura) o tal como escribe Windows; lo que escribe un programa se guarda en la carpeta del navegador al cerrar el archivo, o antes de leerlo (el navegador solo lee lo guardado), y la siguiente escritura copia antes el archivo: un programa que lee y escribe por turnos el mismo archivo grande va lento ahí. Un archivo vaciado al abrirlo no se copia antes |

## El proceso PHP de una sesión, AVC444 en color completo (1.3)

Medido en el equipo de pruebas (Windows 11 Pro, el servidor integrado de PHP 8.3, canal gráfico con H.264, 1024 × 768):

| Qué | Medido |
|---|---|
| El proceso PHP que mantiene una sesión | 17 MB (13 MB propios; 7–9 MB un proceso PHP sin nada abierto); nada de CPU con el escritorio quieto |
| Lo mismo, con texto desplazándose sin parar | 2,7 % de un núcleo a unos 100 KB/s |
| Las dos imágenes de AVC444 unidas en color completo (el worker del navegador) | 25–35 ms de CPU para una imagen entera de 1920 × 1080 (Node.js 24 en este equipo: 25 ms con los colores de un escritorio, 33–35 ms con colores al azar); una actualización suele ser unos pocos rectángulos pequeños |
| Una petición de entrada que el hosting rechazó (508, dos veces) | enviada de nuevo con su número, recibida a los 179 ms; no se perdió nada de lo escrito |

Medido de nuevo tras la revisión de velocidad y memoria (el mismo equipo; *Priorizar el modo de gráficos H.264/AVC 444* de Windows activado):

| Qué | Medido |
|---|---|
| El proceso PHP que mantiene una sesión por HTTP | sin cambios: 17,0 MB (12,8 MB propios), 0,1 % de un núcleo con el escritorio quieto |
| La pasarela, con texto desplazándose sin parar (`tests/bench.mjs`, AVC444) | 2,8 % de un núcleo y 4 MB a 594 KB/s, 43 imágenes por segundo |
| Una sesión del servicio WebSocket (Linux, PHP 8.3) | un PHP arrancado para ella: 15,9 MB residentes, 8,2 MB propios; una copia del servicio, que compiló el código una vez (lo que hace con `pcntl`): nada propio al crearse, su código compartido con el servicio y con las demás sesiones (unos 5 a 8 MB menos cada una, antes de lo que use la propia sesión) |
| Los acuses de recibo de imagen del navegador con 60 y 120 ms de retraso (como por HTTP) | 24 y 22 imágenes por segundo frente a 14–28 en el acto (una animación a pantalla completa, H.264 codificado por el software de Windows): Windows mantiene varias imágenes en vuelo, así que la ida y vuelta HTTP de cada acuse no fija los fps |
| Windows Server 2022 en una máquina virtual (canalización gráfica 8.1: ClearCodec y RemoteFX progresivo, sin H.264) | una animación a pantalla completa a 38–44 imágenes por segundo, 0,7–0,9 MB/s, la pasarela al 5–6 % de un núcleo y 4 MB |
