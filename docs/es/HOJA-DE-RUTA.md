# Hoja de ruta y decisiones

[English](../ROADMAP.md)

## 1. Validación con Windows

Hecho con Windows 11 Pro (ver [PRUEBAS.md › Windows 11 Pro](PRUEBAS.md#windows-11-pro-23h2-nla)): NLA, Display Control, compresión, órdenes de dibujo, canal gráfico con H.264, dos monitores, portapapeles, unidad, audio y micrófono, con cinco fallos corregidos. Falta: Windows Server con licencias RDS, MuDeskPDF y versiones anteriores.

## 2. Velocidad de pantalla

Ya hecho:

- Flujo de pantalla decodificado en un Web Worker; tablas precalculadas y rutas críticas optimizadas en el RLE.
- Entrada sin sesión PHP, hasta 4 peticiones en paralelo con número de secuencia, `TCP_NODELAY`.
- La pasarela no decodifica mapas de bits (0,19 s de CPU para 34 MB).
- Compresión RDP en los dos tramos: MPPC ≈3,5 veces menos tráfico, XCRUSH (RDP 6.1, ofrecida por defecto) en torno a un tercio menos que MPPC y con menos CPU en la pasarela; caché de mapas de bits + MemBlt (con `drawing_orders`): 1,6 MB en vez de 10,9 MB juntas en la prueba con xrdp.
- Canal gráfico con H.264 (lo usa el modo automático con Windows 8.1 o posterior): 21 KB/s en vez de 4,2 MB/s con la misma pantalla animada (FreeRDP). Con Windows 11 se ofrece la versión 10.7 (con 8.1 Windows no usa H.264) y la animación pasa de 18 a unos 45 fotogramas/s.
- Tipo de conexión LAN: Windows envía un 20 % más de fotogramas.
- RemoteFX progresivo 5,6 veces más rápido de decodificar (79 → 14 ms por fotograma a pantalla completa) y bloques opacos del canal gráfico pintados sin mezclar (2,6 veces más rápido).
- Medidas por códec y calidad separando PHP y navegador: [RENDIMIENTO](RENDIMIENTO.md).

- Modo de gráficos automático (por defecto): la versión de Windows que llega en el desafío NTLM decide; Windows 8.1 o posterior → canal gráfico con H.264 si el navegador lo admite; xrdp, solo TLS y Windows antiguos → mapas de bits. Una conexión RemoteApp usa siempre mapas de bits y órdenes de dibujo (sin canal gráfico).

Siguiente: AVC444 con el color a resolución completa.

Pendiente (ganancias aún por medir):

| Opción | Ganancia | Notas |
|---|---|---|
| HiDPI (DesktopScaleFactor) | Imagen más nítida en pantallas de alta densidad | Consume más ancho de banda |

## 3. Impresión

MuDeskPDF usa en el remoto el controlador "Microsoft Print To PDF". Si Windows usa Easy Print (directiva), el cliente necesitaría el canal Easy Print (XPS). Un controlador PostScript exigiría convertir PostScript a PDF, inviable en PHP puro.

## 4. Seguridad y robustez

Hecho (segunda revisión, ver el [historial de cambios](../../CHANGELOG.md)):

- Una sola cuota de carpeta compartida por usuario para la unidad de todas sus sesiones, el gestor de archivos y los ficheros del portapapeles.
- La carpeta compartida como explorador de archivos: árbol de carpetas, arrastrar y soltar archivos y carpetas (subidas y movimientos), descargas en ZIP hechas mientras se envían.
- IME de chino, japonés o coreano en el ordenador: el texto se envía una vez, al confirmarlo; las teclas muertas no se duplican.
- Órdenes de dibujo con cualquier ROP (MemBlt y PatBlt).
- Panel del usuario en el perfil: sesiones remotas propias y navegadores de confianza; último acceso e intentos fallidos al entrar.
- Exportación firmada de la auditoría (RSA 3072, se comprueba con `openssl`), enviada fila a fila.
- App instalable en móviles y ordenadores.
- Límite de sesiones RDP a la vez de todos los usuarios, que `tools/check.php` ayuda a fijar.
- Pruebas fuzz de la pasarela y de los decodificadores del navegador; CI con PHP 8.1, 8.2, 8.3, 8.4 y 8.5 en Linux y Windows y la LTS actual y la anterior de Node.js.
- Páginas de error propias, sin mensajes de PHP; historial del portapapeles como Win+V; teclado en pantalla propio cuando hay ratón; archivos pegados donde se sueltan; pantalla completa en todas las pantallas; opción del administrador para permitir o no guardar contraseñas RDP; la carpeta compartida como explorador de archivos; captura de la sesión; un monitor remoto solo mientras su ventana está abierta; ventana *Sesión caducada* con el motivo.
- Órdenes de dibujo activadas por defecto: mover ventanas 150 → 60 KB/s.
- RemoteApp (MS-RDPERP): un programa del equipo remoto, sus ventanas sobre la página con panel Ventanas y barra de tareas, también con varias pantallas; la sesión termina al cerrarse el programa.
- Archivos .rdp importados (uno al editor, varios a la vez) y exportados.
- Conexiones del administrador para usuarios elegidos o para todos, cada usuario con su contraseña, certificado y opciones.
- Superficies gráficas limitadas a 4 veces la salida (como mucho 8192 × 8192) y la caché de mapas de bits a 100 MB.

Pendiente:

- Probar un IME real de chino, japonés o coreano en el ordenador.
- Un servidor hostil aún puede hacer que el navegador guarde superficies gráficas de hasta 4 veces la salida, en el peor caso 1 GB.
- RemoteApp con el canal gráfico o H.264 (cada ventana en su propia superficie), iconos en el panel Ventanas y la barra de tareas, el área de notificación del programa.

## 5. Lo siguiente y otras ideas

- **Hecho: el micrófono** en las sesiones RDP (entrada de audio, el canal dinámico AUDIO_INPUT): una casilla más en las opciones de la conexión, desmarcada de serie; el Windows remoto tiene que permitir la redirección de grabación de audio ([HOSTING](HOSTING.md#equipos-remotos)). Queda: un formato comprimido (IMA ADPCM enviaría una cuarta parte de los 176 KB/s del PCM) y la cámara.
- **Más adelante: la grabación de sesiones**, reproducida en el navegador con los mismos decodificadores (lo que envía la pasarela: dibujo, vídeo y sonido), abierta desde la auditoría. Antes hay que decidir: espacio en disco, aviso al usuario, cuánto tiempo se guarda, cifrado y quién puede verla.

Otras ideas: tarjetas inteligentes, RD Gateway, redirección del agente de conexión, Kerberos, caché persistente de mapas de bits, copia de la auditoría a un servidor syslog externo (prueba más fuerte que la cadena HMAC, cuya clave está en el mismo servidor).

## Decisiones tomadas (y por qué)

| Decisión | Motivo |
|---|---|
| Una petición HTTP larga por sesión | Hosting compartido: sin demonios ni WebSocket |
| Mapas de bits reenviados y decodificados en JavaScript | Poco consumo de CPU y memoria en el hosting |
| Extensión OpenSSL obligatoria | RDP exige TLS; TLS en PHP puro sería lento e inseguro. OpenSSL viene de serie con PHP |
| Solo NTLM | Kerberos necesita un KDC y no es posible desde un hosting |
| Contraseñas remotas cifradas con la contraseña del usuario | Ni el administrador puede leerlas |
| Confianza en el primer uso para los certificados | Los servidores RDP usan certificados autofirmados |
| Listas blancas de IPs cliente y destinos | Pedido: «IPs y dominios en lista blanca o todo permitido» |
| Varias pantallas como ventanas separadas | El navegador permite una ventana por clic y solo Chromium expone las pantallas |
| Un monitor remoto solo mientras su ventana está abierta | Si no, los programas se abren en una pantalla que nadie ve |
| Órdenes de dibujo activadas por defecto | Validadas con xrdp y Windows 11: imagen idéntica, sin restos al repintar, 2,5 veces menos tráfico al mover ventanas |
| Compresión descomprimida dos veces (pasarela y navegador) | La pasarela necesita el historial común para leer las PDU lentas; reenviar los bytes comprimidos mantiene pequeño también el tramo del navegador. Coste medido con Windows 11: 6–15 % de un núcleo con texto o ventanas y 35–52 % con animación a pantalla completa (con el canal gráfico, 0,4–2 %) |
| Toda respuesta de acceso tarda 1 s (la de una IP bloqueada, al momento) | Oculta qué paso falló y si el usuario existe (bcrypt 12 + PBKDF2 tardan ≈0,55 s en la máquina de pruebas) |
| Las passkeys sustituyen a la contraseña solo con PRF | Las contraseñas RDP guardadas van cifradas con una clave que necesita un secreto del usuario: la contraseña o la salida PRF de la passkey |
| Los navegadores conocidos se libran de los bloqueos por usuario y global, no del de IP | Un atacante no puede dejar fuera al dueño, y una sola IP sigue frenada |
| XCRUSH ofrecido primero, MPPC se mantiene | XCRUSH da el menor tráfico con menos CPU en la pasarela que MPPC; los servidores que solo conocen MPPC (xrdp) lo siguen usando |
| Gráficos en modo automático por defecto | El canal gráfico exige 32 bits: con servidores sin él (xrdp) se enviarían mapas de bits de 32 bits. Por eso solo se usa cuando NLA muestra Windows 8.1 o posterior; sin NLA no hay versión y se queda en mapas de bits |
| El texto de los teclados de móvil que llega de golpe se pega por el portapapeles remoto, con una opción por conexión (en «Automático», activada en móviles y tabletas) | El Bloc de notas, el Terminal y el Explorador de Windows 11 estropean la entrada inyectada: repiten la última letra y pierden mayúsculas, Intros y hasta el Ctrl+V. Ni las pausas (100 o 150 ms) ni las teclas de la distribución lo evitan; pegar sí (8 de 8 líneas), como hacen otros programas con el mismo fallo. Medido: la revisión ortográfica y la autocorrección del Bloc de notas tiran una tecla si justo detrás llega una tecla de control o un pegado, y Windows no avisa de cuándo terminan; un pegado vacío de comprobación no lo mejoró y se descartó. Cada paso espera una señal de Windows (respuesta al anuncio, cambio de pantalla, petición del texto). Lo copiado en Windows se lee entero antes y se repone, fuera del historial; con archivos, o más de 16 MB, se escribe para no perderlos. Desactivada por defecto en el ordenador porque Ctrl+V no es pegar en todos los programas. Detalle en [TECLADO-MOVIL](TECLADO-MOVIL.md) |
| Tipo de conexión LAN, sin detección automática de red | Sin él Windows da menos fotogramas. La detección automática (MS-RDPBCGR *auto-detect*) solo mediría el tramo pasarela–servidor, normalmente la misma red; el del navegador ya cuenta: con el canal gráfico las confirmaciones de fotograma salen del navegador tras decodificar (si va lento, Windows envía menos) y con mapas de bits la contrapresión de TCP frena al servidor |
| Dibujo en el hilo principal (sin OffscreenCanvas) | Con animación a pantalla completa (Windows 11, canal gráfico) el hilo principal dedica 84 ms por segundo a pintar (8 %), sin tareas largas, y la entrada sigue en unos 20 ms. El color de H.264 ya lo convierte la GPU al pintar los fotogramas decodificados; llevar el dibujo al worker no ahorraría CPU, solo la cambiaría de hilo |
| Sin cookie de reconexión automática | La reconexión automática ya reutiliza la contraseña (la escrita sigue en la memoria de la pestaña, la guardada la descifra la pasarela) y Windows vuelve a la sesión desconectada del usuario; con NLA la cookie no evita presentar credenciales |
| SPS de H.264 reescrito en el navegador | Los navegadores no hacen caso del espacio de color pedido al decodificador (Firefox) y suponen rango limitado; RDP usa BT.709 de rango completo |
| Límite de sesiones RDP a la vez de todos los usuarios (20 por defecto) | Cada sesión ocupa un proceso PHP mientras dura; si se pasa de los que tiene el servidor, la web y el teclado también esperarían. Una pasarela llena responde «ocupado» al momento |
| Nombres resueltos con el resolutor del sistema, sin uno propio | Un resolutor propio rompería el fichero `hosts`, los dominios de búsqueda y la resolución de nombres de Windows. Se aplica el tiempo límite del resolutor del sistema, y el límite de sesiones acota cuántos procesos pueden esperar |
| Exportación de la auditoría firmada con una clave RSA | RSA puede firmar un SHA-256 calculado por partes mientras salen las filas, así la exportación no necesita copia en memoria (unos 20 MB para 20 000 filas) y la comprueba `openssl dgst -verify` |
| Cada navegador conocido tiene su propio bloqueo | Una cookie de dispositivo robada no da más intentos que el bloqueo por usuario, y los demás siguen sin poder dejar fuera al dueño |
