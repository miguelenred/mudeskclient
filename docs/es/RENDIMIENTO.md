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
