# Hoja de ruta y decisiones

[English](../ROADMAP.md)

## 1. Validación con Windows

Hecho con Windows 11 Pro (ver [PRUEBAS.md › Windows 11 Pro](PRUEBAS.md#windows-11-pro-23h2-nla)): NLA, Display Control, compresión, órdenes de dibujo, canal gráfico con H.264, dos monitores, portapapeles, unidad, audio, micrófono, cámara y una carpeta de este equipo, con cinco fallos corregidos. Falta: Windows Server con licencias RDS, MuDeskPDF y versiones anteriores: desde la 1.1 se admiten Windows XP y Server 2003 (seguridad RDP estándar) y Windows 7 y Server 2008 R2 (TLS 1.0, CredSSP 3), probados contra servidores que hablan sus protocolos, aún no contra esos Windows.

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
- La revisión de velocidad de la 1.1: la descompresión de la pasarela de 1,2 a 2,5 veces más rápida y su RC4 de 1,35 a 1,86 veces, los decodificadores del navegador de un 9 a un 54 % menos de tiempo, los mapas de bits de una actualización entregados juntos a la página y una operación de varias zonas pintada una sola vez; en un equipo limitado a la sexta parte de la CPU una animación pasa de 9,7 a 12,3 fotogramas por segundo ([RENDIMIENTO](RENDIMIENTO.md#cuarta-revisión-11-decodificadores-entrega-a-la-página-y-equipos-lentos)). Queda ahí: una ráfaga de cientos de desplazamientos a pantalla completa de un servidor antiguo se acumula en la página (frenar al servidor costaba un 12–25 % de rendimiento).

- Modo de gráficos automático (por defecto): la versión de Windows que llega en el desafío NTLM decide (con Kerberos, que no la da: un Windows de dominio, el canal gráfico); Windows 8.1 o posterior → canal gráfico con H.264 si el navegador lo admite; xrdp, solo TLS y Windows antiguos → mapas de bits. Una conexión RemoteApp usa siempre mapas de bits y órdenes de dibujo (sin canal gráfico).

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

- **Hecho: el micrófono** en las sesiones RDP (entrada de audio, el canal dinámico AUDIO_INPUT): una casilla más en las opciones de la conexión, desmarcada de serie; el Windows remoto tiene que permitir la redirección de grabación de audio ([HOSTING](HOSTING.md#equipos-remotos)). Queda: un formato comprimido (IMA ADPCM enviaría una cuarta parte de los 176 KB/s del PCM).
- **Hecho: la cámara** (1.1), con el micrófono en una sola casilla (MS-RDPECAM, Motion JPEG desde la cámara de la propia página, en Chrome, Edge y Firefox), y **una carpeta de este equipo** como unidad de la sesión (lectura y escritura en Chrome y Edge, solo lectura en los demás; ver [USO](USO.md#una-carpeta-de-este-equipo)). Decidido: la cámara va con el micrófono (una sola decisión sobre los dispositivos de este equipo, desmarcada de serie); la carpeta necesita la carpeta compartida de la conexión, así que un administrador que la desactive desactiva las dos. Queda fuera: imágenes H.264 de la cámara (menos ancho de banda; Motion JPEG funciona en todos los navegadores) y avisar a Windows de los cambios de la carpeta según ocurren (el navegador no puede vigilar una carpeta).
- **Hecho: la grabación de sesiones** (1.1), reproducida en el navegador con los mismos decodificadores, abierta desde la auditoría. Decidido: desactivada salvo que el administrador grabe una conexión; al usuario se le avisa antes de mostrar nada y mientras dura la sesión; solo la imagen (ni el teclado, ni el portapapeles, ni el sonido); cifrada con una clave del servidor; se guarda 30 días (un ajuste) y como mucho 2 GB cada una (`record_max_mb`); solo la ven administradores y auditores, y cada visionado queda en la auditoría. Queda: el sonido, y saltarse los tramos sin cambios.
- **Hecho: compartir una sesión** (1.1) con un enlace, solo ver o con control (ver [USO](USO.md#compartir-una-sesión)).
- **Hecho: puerta de enlace de Escritorio remoto y agente de conexión** (1.1): una conexión a través de una RD Gateway (MS-TSGU sobre WebSocket, sobre dos conexiones HTTPS o RPC sobre HTTP para Windows Server 2008 y 2008 R2; en PHP puro) y con la información de equilibrio de carga de una granja, siguiendo las redirecciones del agente (su contraseña para el equipo siguiente, entregada con RDSTLS); probado a través de una puerta de enlace de código abierto delante de Windows 11 (RPC sobre HTTP, contra una puerta de enlace escrita según las especificaciones), aún no contra la puerta de enlace de Windows Server ni contra un agente real.
- **Hecho: Windows antiguos** (1.1): la seguridad RDP estándar para Windows XP y Server 2003 (solo con la seguridad *Automática*), TLS 1.0 y CredSSP 3 para Windows 7 y Server 2008 R2, Kerberos con RC4 para los dominios de Windows Server 2003; sus límites, en [SECURITY](../../SECURITY.md).

Otras ideas: caché persistente de mapas de bits, copia de la auditoría a un servidor syslog externo (prueba más fuerte que la cadena HMAC, cuya clave está en el mismo servidor). Las tarjetas inteligentes no son posibles: un navegador no puede dejar que la pasarela use la clave privada de la tarjeta.

## Decisiones tomadas (y por qué)

| Decisión | Motivo |
|---|---|
| Una petición HTTP larga por sesión | Hosting compartido: sin demonios ni WebSocket |
| Mapas de bits reenviados y decodificados en JavaScript | Poco consumo de CPU y memoria en el hosting |
| Extensión OpenSSL obligatoria | RDP exige TLS; TLS en PHP puro sería lento e inseguro. OpenSSL viene de serie con PHP |
| Kerberos si se llega a un controlador de dominio; si no, NTLM | Desde un hosting los controladores de dominio no suelen estar a mano: entonces NTLM, como Windows sin KDC (no donde el dominio lo bloquea). Kerberos con AES y, para los dominios de Windows Server 2003 y las contraseñas anteriores a las claves AES de su dominio, RC4 (ofrecido el último), solo si el administrador lo permite (desactivado de serie: quien se ponga en medio podría forzarlo, y la contraseña se rompe entonces mucho antes); solo contraseñas (sin tarjetas inteligentes) |
| La seguridad RDP estándar solo con la seguridad *Automática* | Windows XP y Server 2003 no tienen otra, pero no demuestra nada del servidor y su RC4 es débil: nunca la usa una conexión en *Solo NLA* o *Solo TLS*, la clave del servidor se fija como un certificado (un equipo con TLS que cae a ella es un certificado cambiado) y un servidor que no cifra nada se rechaza |
| Contraseñas remotas cifradas con la contraseña del usuario | Ni el administrador puede leerlas |
| Confianza en el primer uso para los certificados | Los servidores RDP usan certificados autofirmados |
| Listas blancas de IPs cliente y destinos | Pedido: «IPs y dominios en lista blanca o todo permitido» |
| Varias pantallas como ventanas separadas | El navegador permite una ventana por clic y solo Chromium expone las pantallas |
| Un monitor remoto solo mientras su ventana está abierta | Si no, los programas se abren en una pantalla que nadie ve |
| Órdenes de dibujo activadas por defecto | Validadas con xrdp y Windows 11: imagen idéntica, sin restos al repintar, 2,5 veces menos tráfico al mover ventanas |
| Compresión descomprimida dos veces (pasarela y navegador) | La pasarela necesita el historial común para leer las PDU lentas; reenviar los bytes comprimidos mantiene pequeño también el tramo del navegador. Coste medido con Windows 11: 6–15 % de un núcleo con texto o ventanas y 35–52 % con animación a pantalla completa (con el canal gráfico, 0,4–2 %) |
| Toda respuesta de acceso tarda 1 s (la de una IP bloqueada, al momento) | Oculta qué paso falló y si el usuario existe (bcrypt 12 + PBKDF2 tardan ≈0,78 s en la máquina de pruebas) |
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
