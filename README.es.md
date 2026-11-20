# MuDeskClient

Cliente de escritorio remoto (RDP) que funciona en el navegador. El navegador solo usa HTML, CSS y JavaScript; una pasarela escrita en PHP puro habla RDP con los equipos remotos. No hay que instalar nada en los equipos remotos ni se necesita base de datos: todo se guarda en ficheros JSON.

[Read in English](README.md)

## Funciones

- Compresión RDP (MPPC, NCRUSH, XCRUSH) de extremo a extremo y órdenes de dibujo con caché de mapas de bits: los dos tramos llevan datos comprimidos.
- RDP con TLS y NLA (CredSSP/NTLMv2), licencias RDS, color de 15/16/24/32 bits. Usuario como `usuario`, `DOMINIO\usuario`, `EQUIPO\usuario`, `.\usuario` (cuenta local del servidor) o `usuario@dominio` (UPN).
- Acceso: usuarios y administrador, TOTP opcional y **passkeys** (sin contraseña, o como segundo paso tras la contraseña: lo deciden el dispositivo y el navegador, y MuDesk dice cuál al añadirla), código de instalación de un solo uso, bloqueos por IP / usuario / sitio que nunca dejan fuera al dueño en un navegador conocido, respuestas de tiempo constante.
- Gráficos en modo **automático** (por defecto): con Windows 8.1 / Server 2012 R2 o posterior, detectado al iniciar sesión, se usa el canal gráfico; con xrdp y servidores antiguos, mapas de bits. Canal gráfico RDPGFX: vídeo AVC420 decodificado por el navegador con WebCodecs, más RemoteFX, RemoteFX progresivo, ClearCodec, planar y caché de superficie. Si el navegador no decodifica H.264, se usan los demás códecs. Con H.264 se ofrece también la versión 10.7 del canal, la única con la que Windows 10/11 usa H.264 (en las zonas animadas); los fotogramas AVC444 se muestran con su imagen 4:2:0.
- Pantalla en `<canvas>`; teclado (scancodes, cualquier distribución, teclas muertas e IME de chino, japonés o coreano, que envía el texto una vez confirmado), ratón, rueda y táctil (toque, pulsación larga = botón derecho, desplazamiento con dos dedos).
- La resolución remota sigue al tamaño de la ventana (canal Display Control; si el servidor no lo tiene, reconecta con el nuevo tamaño) o, por conexión, se escala la imagen a la ventana (*Escalar la imagen*). Pantalla completa con captura de teclado cuando el navegador lo permite.
- Portapapeles en los dos sentidos: texto, texto con formato (negrita, colores… en HTML, como lo copian Word y los navegadores), imágenes y archivos (descarga en ZIP si se copian varios en el remoto). Arrastrar y soltar archivos sobre la sesión: se pegan donde se sueltan (en el escritorio o en una carpeta abierta). Historial del portapapeles como Win+V: lo copiado en los dos sentidos, para pegarlo otra vez en el equipo remoto.
- Captura de pantalla del escritorio remoto, entera o de una zona marcada con el ratón, guardada en PNG o copiada al portapapeles local o al remoto.
- Impresora virtual **MuDeskPDF**: lo que se imprime en el remoto llega al navegador como PDF, listo para imprimir en cualquier impresora local o descargar.
- Audio del equipo remoto (IMA ADPCM, A-law, μ-law o PCM, reproducido con Web Audio) con botón de silencio.
- Micrófono (desmarcado de serie, una casilla más de la conexión): el equipo remoto oye el micrófono de este mientras un programa graba allí (el Windows remoto tiene que permitir la redirección de grabación de audio, ver [HOSTING](docs/es/HOSTING.md#equipos-remotos); la página tiene que abrirse con `https://`).
- Ventana al conectar para elegir audio, micrófono, portapapeles, teclado del móvil por portapapeles, carpeta compartida, impresora MuDeskPDF, gráficos, calidad de color y varias pantallas en cada sesión (todo salvo las pantallas se puede recordar).
- Varias pantallas: cada pantalla local con su ventana abierta es un monitor remoto independiente (no un escritorio estirado); abrir o cerrar esa ventana lo añade o lo quita sin reconectar. Chrome y Edge dan la lista de pantallas (API Window Management); Firefox busca la otra abriendo una ventana junto a esta.
- Barra de la sesión que se puede llevar a un lado (se queda así para las siguientes sesiones), con un indicador de calidad: imágenes por segundo, latencia de la entrada y KB/s recibidos. Su panel Archivos enumera los archivos enviados al portapapeles remoto con su estado y admite archivos soltados encima. Las ventanas de las otras pantallas tienen una barra (pantalla completa, cerrar) y se cierran con la de la sesión.
- Móviles y tabletas: toque, pulsación larga = botón derecho, pellizcar para ampliar, desplazamiento con dos dedos, teclado en pantalla (se abre solo al tocar un cuadro de texto; con ratón, el botón abre un teclado en pantalla propio) y panel de teclas especiales (Esc, Tab, Ctrl/Alt/Mayús/Win que se quedan pulsadas, flechas, Inicio/Fin, RePág/AvPág, Supr, Retroceso, Intro, F1–F12).
- Carpeta compartida: la carpeta de cada usuario en el servidor web aparece en la sesión como unidad **Carpeta compartida en MuDesk** (en el idioma de la página); la web la gestiona como un explorador de archivos (árbol de carpetas, detalles o iconos con miniaturas, archivos y carpetas arrastrados desde el equipo o entre carpetas, varios elementos o carpetas descargados en un solo ZIP).
- Usuarios con rol de administrador, verificación en dos pasos opcional (TOTP, código QR), cambio de contraseña obligatorio, bloqueo por intentos fallidos.
- Perfil: cada usuario ve sus sesiones remotas abiertas y puede cerrarlas, y sus navegadores de confianza, que puede olvidar. Al entrar se muestra el último acceso (fecha e IP) y los intentos fallidos con su usuario desde entonces.
- Se puede instalar como app en móviles y ordenadores (manifiesto de aplicación web e iconos, sin service worker).
- Cada usuario gestiona sus conexiones; las contraseñas remotas se cifran (AES-256-GCM) con una clave derivada de la contraseña del usuario: ni el administrador puede leerlas. Las conexiones se importan desde **archivos .rdp** de Conexión a Escritorio remoto y se exportan a ellos (nunca su contraseña), también por el administrador. Una conexión puede ser una **RemoteApp**: un programa del equipo remoto, en sus propias ventanas sobre la página (también con varias pantallas), con una barra de tareas y una lista de sus ventanas.
- Fijación del certificado del servidor en la primera conexión (aviso si cambia).
- Administración: usuarios, conexiones para algunos usuarios o para todos (que no pueden cambiarlas ni borrarlas) y las propias de cada usuario, listas blancas (IPs de acceso y equipos remotos: IPs, rangos CIDR, nombres, `*.dominio`), límites (también de sesiones RDP a la vez de todos los usuarios), permitir o no guardar la contraseña de las conexiones RDP, sesiones activas (con cierre forzado) y auditoría detallada (accesos, cambios, sesiones RDP, portapapeles, unidad compartida, impresión) con búsqueda, exportación (CSV, o JSON firmado con una clave del servidor que cualquiera puede comprobar con `openssl`), conservación configurable (90 días por defecto, o sin límite) y detección de manipulaciones; modo diagnóstico con una traza técnica de la sesión (sin contenidos ni contraseñas).
- Interfaz en español e inglés: se usa el idioma del navegador (inglés si no es ninguno de los dos) y cada usuario puede cambiarlo con el selector de la cabecera (también en la pantalla de acceso y en el perfil), que lo guarda en su perfil; adaptable a móvil, tema claro y oscuro.

## Requisitos

- PHP 8.1 a 8.5 de 64 bits (todas probadas) solo con las extensiones OpenSSL y session que trae PHP de serie (ninguna otra extensión ni paquetes de Composer).
- HTTPS (lo exigen la API de portapapeles del navegador y las cookies de sesión).
- El servidor web debe poder mantener abierta una petición durante toda la sesión y enviar la salida sin acumularla (ver *Hosting*).
- Conexión TCP saliente desde el servidor web al puerto RDP de los equipos (3389 por defecto).
- Navegador actual (Chrome, Edge, Firefox) en ordenador, tableta o móvil. Safari no se ha probado nunca, y aquí no se puede (no hay ningún dispositivo de Apple). Varias pantallas: Chrome y Edge dan la lista; Firefox busca la otra (ver *Limitaciones*).

## Compatibilidad (RDP y RDS)

**Importante:** probado contra Windows 11 Pro 23H2 (NLA), xrdp 0.9.24 y el servidor FreeRDP 3; el usuario probó además RemoteApp en un Windows Server 2016 y el sonido en Windows 10. El resto de Windows Server, el Host de sesión con licencias RDS y las versiones anteriores de Windows siguen sin probar: sus filas son lo que dicen las especificaciones del protocolo.

| Servidor | Esperado | Notas |
|---|---|---|
| Windows 11, 10, 8.1 (Pro/Enterprise) | Sí (probado Windows 11 Pro) | NLA o TLS. Probado en Windows 11: teclado, ratón, cambio de tamaño sin reconectar, compresión MPPC/NCRUSH/XCRUSH, órdenes de dibujo, canal gráfico con H.264, dos monitores, portapapeles (texto, emojis y archivos), unidad MuDesk, audio, micrófono. Los gráficos H.264 necesitan Windows 8.1 o posterior. |
| Windows Server 2025, 2022, 2019, 2016, 2012 R2 (Escritorio remoto para administración o Host de sesión de RD) | Sí | Host de sesión con licencias RDS (mejor en modo por usuario, ver abajo). Sin sonido ni micrófono hasta activar su servicio Audio de Windows (ver [HOSTING](docs/es/HOSTING.md#equipos-remotos)). |
| Windows 8, Server 2012 | Sí | Sin H.264 (canal gráfico solo 8.0); el cambio de tamaño reconecta. |
| Windows 7, Server 2008 R2 | Probablemente | Solo TLS 1.0 salvo que tengan la actualización de TLS 1.2 (la pasarela lo acepta). Sin canal gráfico ni cambio de tamaño sin reconectar. |
| Windows Vista, Server 2008 | Probablemente | Igual que el anterior, con un solo monitor. |
| Windows XP, Server 2003 y anteriores | No | Solo ofrecen la *seguridad RDP estándar* (RC4), que no está implementada. |
| xrdp 0.9 o posterior | Sí (probado) | TLS; sin NLA. |
| Servidor FreeRDP 3 | Sí (probado) | TLS/NLA, canal gráfico con H.264. |

- **Protocolo:** núcleo RDP con versión de cliente 0x00080004 (RDP 5.0–8.1) sobre TLS 1.0–1.3 o NLA (CredSSP v6, NTLMv2). No admitido: seguridad RDP estándar (RC4), Kerberos, RDSTLS, tarjetas inteligentes.
- **RDS:** se admite el intercambio de licencias (solicitud, desafío, licencia nueva o renovada); las licencias no se guardan, así que usa el modo de CAL **por usuario** (el modo por dispositivo está sin probar y puede dar una licencia temporal nueva en cada conexión). No admitido: **Puerta de enlace de RD (RD Gateway)**, **redirección del agente de conexión** (conecta directamente al host de sesión; una granja con agente que redirige termina con el error `redirect`), y el canal gráfico en los programas **RemoteApp** (usan mapas de bits y órdenes de dibujo).
- **Funciones según la versión del servidor** (según especificación): varios monitores, Windows 7/2008 R2 o posterior; portapapeles con archivos, Vista/2008 o posterior; cambio de tamaño dinámico (Display Control), 8.1/2012 R2 o posterior (en los anteriores reconecta con el nuevo tamaño); canal gráfico, 8/2012 o posterior, H.264 (AVC420) 8.1/2012 R2 o posterior; la impresora MuDeskPDF usa el controlador *Microsoft Print To PDF*, presente en Windows 10/Server 2016 o posterior; compresión MPPC en todas las versiones y XCRUSH (RDP 6.1) en Vista SP1/2008 o posterior.

## Instalación

Paso a paso para todos los niveles (un hosting, un PC con Windows y XAMPP o un equipo con Linux y Apache, cortafuegos y problemas frecuentes): **[manual de instalación](docs/es/INSTALACION.md)**. En resumen:

1. Copia el proyecto (o, con Composer, `composer create-project miguelenred/mudeskclient mudesk`: sin dependencias, solo PHP 8.1–8.5 con OpenSSL). Valen dos formas (detalle en [HOSTING](docs/es/HOSTING.md)):
   - **Todo el proyecto dentro de la raíz web** (`public_html/mudesk/`): los `.htaccess` (Apache, LiteSpeed) y `web.config` (IIS) incluidos dejan accesible solo `public/`; Nginx y OpenLiteSpeed necesitan las reglas de [HOSTING](docs/es/HOSTING.md) y niegan `data/`, `src/`, `tests/`, `tools/` y `docs/`. Comprueba que `mudesk/data/web-check.txt` responde 403 (o 404), nunca 200.
   - **Solo `public/` en la raíz web** y el resto fuera; si `public_html` no está dentro de la carpeta del proyecto, cambia la línea `require` del principio de `public_html/*.php` para que apunte a `src/bootstrap.php`.
2. Permisos de escritura: **solo `data/`** (y todo lo que hay dentro) necesita escritura para el usuario con el que corre PHP; la aplicación crea sus subcarpetas (`audit/`, `connections/`, `files/`, `run/`, `sessions/`, `tmp/`, `trace/`). El resto (`public/`, `src/`, `config.php`) basta con lectura. Si `data/` no tiene escritura, la aplicación lo explica en una página propia, sin mensajes de PHP; en Windows (XAMPP, IIS) hay que dar el permiso *Modificar* a la cuenta del servidor web (ver [HOSTING](docs/es/HOSTING.md#permisos-de-escritura)). Fuera del proyecto solo la carpeta temporal de PHP (`upload_tmp_dir` en php.ini, o la del sistema) necesita escritura, y ya la tiene en los hostings, en XAMPP y en Linux: PHP guarda allí lo que se envía al servidor de más de 16 KB (archivos que se suben, pegados largos) mientras llega.
3. Opcional: copia `config.sample.php` como `config.php` (en la carpeta del proyecto) y ajústalo.
4. Abre la web: la primera visita pide el **código de instalación**, que está en `data/setup-code.php` (ábrelo con el administrador de archivos del hosting), y crea la cuenta de administrador. El código solo sirve una vez.

### Hosting

Cada sesión abierta mantiene una petición PHP en marcha por la que se envía la pantalla. Antes de desplegar, sube `tools/check.php`, pon su token y ejecuta sus seis pruebas (entorno PHP, conexión RDP saliente, petición larga, latencia, carpeta de datos accesible desde la web y procesos PHP a la vez). Bórralo después.

- Apache: `public/.htaccess` desactiva la compresión del flujo y hace revalidar scripts y estilos en cada carga (IIS: `public/web.config`), para que una actualización llegue enseguida al navegador. Con PHP-FPM tras `mod_proxy_fcgi`, añade `flushpackets=on` a la configuración del proxy.
- Nginx: desactiva el búfer para `rdp.php` (`fastcgi_buffering off;`; la pasarela envía también `X-Accel-Buffering: no`) y añade `location ~* \.(js|css)$ { add_header Cache-Control "no-cache"; }`: la página pide su script y su hoja de estilos con una huella de sus archivos (`?v=`), pero los módulos que importan no la llevan. Una página abierta durante una actualización lo dice y ofrece recargar.
- Proxies como Cloudflare cortan las peticiones largas (≈100 s); el cliente reconecta solo y la sesión de Windows sigue viva, pero se recomienda conexión directa.
- Si el hosting no permite sockets Unix locales, pon `'ipc' => 'file'` en `config.php`.
- Cada sesión abierta cuenta como un proceso PHP, más hasta cuatro peticiones cortas a la vez mientras se escribe o se mueve el ratón (o mientras un programa remoto graba del micrófono); revisa el límite de procesos del hosting y deja *Sesiones RDP a la vez (todos los usuarios)* (`max_sessions_total`, 20 por defecto) por debajo. Al llegar a ese límite, la pasarela dice que el servidor está ocupado, sin conectar.

### Seguridad

- Abre el puerto RDP de los equipos remotos solo a la IP del servidor web.
- Usa NLA en los equipos remotos (viene activado en las versiones actuales de Windows).
- Se aceptan certificados autofirmados en los servidores: la huella se guarda en la primera conexión y se avisa si cambia después. La primera conexión a un equipo no puede comprobar quién es: hazla desde una red de confianza y usa preferiblemente la seguridad *Solo NLA* (ver *Known limits* en [SECURITY](SECURITY.md)).
- `data/` contiene los usuarios, los secretos cifrados y `secret.php` (clave del servidor); haz copias de seguridad. Puede quedarse dentro de la raíz web: los `.htaccess` niegan todas las carpetas salvo `public/` y, si el servidor no los aplica, se avisa al administrador (ver [HOSTING](docs/es/HOSTING.md)).

## Configuración (`config.php`)

| Clave | Por defecto | Significado |
|---|---|---|
| `data_dir` | `<proyecto>/data` | Carpeta de ficheros JSON, sesiones y carpetas compartidas |
| `force_https` | `true` | Rechaza HTTP sin cifrar (localhost siempre se permite, salvo que sea un proxy de confianza) |
| `trusted_proxies` | `[]` | IPs de proxies inversos de los que se aceptan `X-Forwarded-For` / `X-Forwarded-Proto` |
| `ipc` | `auto` | Cómo se llega a las sesiones en marcha: `auto` (un socket Unix; en Windows un puerto TCP de este equipo; el fichero de cola si no se puede abrir ninguno) o `file` (siempre el fichero de cola) |
| `drawing_orders` | `true` | Permite que el servidor envíe órdenes de copiar/rellenar, texto con glifos en caché y use la caché de mapas de bits en vez de reenviarlos (≈3 veces menos tráfico en la prueba con xrdp; con Windows 11, mover ventanas pasa de 150 a 60 KB/s y la CPU de PHP del 6,5 al 2,7 %). Validado con xrdp y Windows 11: imagen idéntica y sin restos al repintar |
| `bulk_compression` | `'rdp61'` | Compresión RDP más alta que se ofrece: `'rdp61'` (XCRUSH, Windows Vista SP1/2008 o posterior; los servidores que solo conocen MPPC, como xrdp, usan MPPC), `'rdp6'` (NCRUSH), `'mppc'` o `false`. Por los dos tramos viajan datos comprimidos: con MPPC ≈3,5 veces menos tráfico, con XCRUSH en torno a un tercio menos que MPPC y menos CPU en la pasarela (ver ARQUITECTURA). |

El resto de ajustes (listas blancas, bloqueo, tiempos, límites de sesiones, reintentos de conexión, cuotas, política de 2FA, conservación de la auditoría, modo diagnóstico en todas las sesiones, guardar contraseñas RDP) los cambia el administrador desde la web.

## Limitaciones

- Canal gráfico (RDPGFX): lo elige el modo automático con Windows 8.1 o posterior (también se puede forzar o desactivar por conexión; nunca se usa con RemoteApp). Probado contra FreeRDP y Windows 11 (ClearCodec, RemoteFX progresivo con pasadas de mejora y AVC420). AVC444 se muestra solo con su imagen 4:2:0 (el color a resolución completa aún no). Sin ella se usan las actualizaciones de mapa de bits clásicas, que admiten todas las versiones de Windows.
- Al escribir desde el móvil por portapapeles, lo copiado en Windows se repone salvo los formatos que Windows no entrega por RDP (objetos OLE incrustados de Office); si el texto no se puede pegar (archivos copiados en Windows, o más de 16 MB), se escriben como mucho 2000 caracteres.
- Órdenes de dibujo: Mem3Blt no se negocia (Windows manda en su lugar mapas de bits); si llega un MemBlt cuyo modo de mezcla use pincel, se toma el pincel blanco por defecto de Windows.
- El canal gráfico limita sus superficies a 4 veces la salida gráfica en total (como mínimo 1920×1080; la salida, como mucho 8192 × 8192 píxeles): un servidor RDP hostil aún puede hacer que el navegador guarde hasta 1 GB más o menos; por encima, el navegador falla con un error registrado.
- **RemoteApp**: el equipo remoto debe permitir el programa (Windows Server: publicado con Servicios de Escritorio remoto; Windows 10/11 Pro: en la clave del registro `TSAppAllowList`, ver [HOSTING](docs/es/HOSTING.md#equipos-remotos)). Se dibuja con mapas de bits y órdenes de dibujo (sin canal gráfico ni H.264; no se ofrece la opción Gráficos); sin iconos en el panel Ventanas ni área de notificación. Mientras se abre, un indicador lo dice (a los 15 s, que tarda más de lo normal); si el servidor cierra la sesión entretanto (error info 0x0B), la página se vuelve a conectar sola; sin ventana a los 90 s, la sesión termina diciéndolo. Al cerrar su última ventana, la sesión termina 3 s después.
- Teclados de móvil en Windows 11: el Bloc de notas, el Terminal y el Explorador estropean el texto que llega del teclado en pantalla (repiten letras, pierden mayúsculas o un Intro). Es un fallo de Windows, no de MuDeskClient; detalle y pruebas en [Teclado del móvil y Windows 11](docs/es/TECLADO-MOVIL.md). La opción «Teclado del móvil por portapapeles» (por conexión; en «Automático» se activa sola en móviles y tabletas) pega ese texto a través del portapapeles remoto y después lo deja como estaba, también una imagen copiada en Windows; si en Windows se habían copiado archivos, el texto se escribe en vez de pegarse. Un Intro seguido sin pausa de más texto aún puede perderse en el Bloc de notas; al ritmo real de un móvil pasó en 2 de 24 líneas. El teclado físico no se ve afectado.
- Las impresoras locales se usan a través de la impresora MuDeskPDF y el diálogo de impresión del navegador (el navegador no puede ver las impresoras directamente). Sin tarjetas inteligentes, RD Gateway ni redirección del agente de conexión. El micrófono va en PCM de 16 bits (el único formato que ofrece Windows 11 aparte de los comprimidos: unos 176 KB/s de subida mientras un programa graba); sin cámara.
- Solo NTLM (sin Kerberos).
- El navegador no puede poner archivos en el portapapeles local: los archivos copiados en el remoto se ofrecen como descarga.
- Leer el portapapeles local de forma automática solo es posible en navegadores basados en Chromium tras dar permiso; en el resto se usa Ctrl+V o el panel Portapapeles.
- Varias pantallas: Chrome y Edge dan la lista de pantallas (API Window Management). Firefox y Safari (nunca probado) no: la otra pantalla se busca abriendo una ventana junto a la actual (hay que permitir las ventanas emergentes), y se usan dos; si está a la izquierda de la principal, la primera vez hay que llevar esa ventana a ella (ver [USO](docs/es/USO.md#firefox-y-safari)).

## Estructura

```
public/            raíz web: index.php (interfaz), api.php, rdp.php (pasarela), files.php, assets/
src/               clases PHP (sin dependencias)
src/Rdp/           protocolo RDP: Client, Transport, CredSsp, Ntlm, Licensing, Cliprdr, Rdpdr, Rdpsnd, Drdynvc, Audin (micrófono), Rail (RemoteApp),
                   Bulk/Mppc/Ncrush/Xcrush (compresión), Trace (diagnóstico)
public/assets/     navegador: rdpgfx.js (canal gráfico), rfx.js (RemoteFX), gfxsurfaces.js, gfx.js, session.js, rdpfile.js (archivos .rdp),
                   mic.js y mic-worklet.js (micrófono), screen-watch.js (las ventanas de las otras pantallas)…
data/              almacenamiento JSON (se crea al usarse)
tools/check.php    diagnóstico del hosting
tests/             pruebas unitarias y de extremo a extremo
```

## Rendimiento

La pantalla se lee y decodifica en un Web Worker, así la entrada nunca espera al dibujado. Teclado y ratón se envían al momento (hasta cuatro peticiones en paralelo que el servidor reordena por número de secuencia) por un punto de entrada ligero que no abre la sesión PHP, y el socket RDP usa `TCP_NODELAY`. Medido: la pasarela usó 0,19 s de CPU y 4 MB de memoria para pasar 34 MB de cambios a pantalla completa en 14 s; con 60 ms de latencia de red, la última tecla de una escritura rápida llega una ida y vuelta (≈65 ms) después de pulsarla.

Compresión y caché de mapas de bits, contra xrdp con los mismos 20 s de texto desplazándose e imágenes cambiando (16 bits): 10,9 MB con mapas de bits, 3,2 MB comprimido, 3,9 MB con órdenes de dibujo y caché y **1,6 MB con las dos cosas** (6,8 veces menos); CPU de la pasarela 0,07 s, 0,50 s, 0,04 s y 0,30 s. La imagen sigue idéntica píxel a píxel en Chrome y Firefox.

Contra Windows 11 (detalle por códec y calidad, separando PHP y navegador, en [RENDIMIENTO](docs/es/RENDIMIENTO.md)): con el canal gráfico PHP gasta un 0,4–2 % de un núcleo por conexión, frente al 6–52 % con mapas de bits comprimidos; mover una ventana cuesta 35 KB/s con el canal gráfico, 60 KB/s con mapas de bits y órdenes de dibujo y 150 KB/s solo con mapas de bits.

Con gráficos H.264, contra un servidor FreeRDP y la misma pantalla animada: 21 KB/s frente a 4,2 MB/s con mapas de bits a 32 bits (≈200 veces menos tráfico); calidad 33 dB PSNR en Chrome y Firefox. El navegador confirma cada fotograma decodificado (solo el último pendiente), así el servidor adapta los fotogramas por segundo a lo que el equipo puede dibujar.

## Pruebas

- `php tests/run.php`: vectores de prueba de protocolo (NTLMv2, RC4, DER, TOTP, CIDR, UTF-16), auditoría, portapapeles, páginas de error, gestor de archivos (mover, árbol, ZIP), caducidad de la sesión web, RemoteApp (RAIL), el canal del micrófono, conexiones del administrador, la huella de los archivos de la página.
- `php tests/auth_e2e.php base carpetaDatos`: seguridad del inicio de sesión contra una copia en marcha con la carpeta de datos vacía.
- `php tests/rdp_probe.php equipo puerto usuario contraseña`: conecta desde la línea de órdenes e informa de lo que recibiría el navegador.
- `node tests/bench.mjs equipo puerto usuario contraseña [carga] [segundos]`: mide una conexión con una carga (reposo, texto, mover ventana, animación, sonido): tráfico, CPU y memoria de PHP, CPU y memoria de los decodificadores del navegador y fotogramas por segundo (ver [Rendimiento](docs/es/RENDIMIENTO.md)).
- `node tests/input_unit.mjs`: texto tecleado (de carácter en carácter) y cursor de texto que abre el teclado del móvil.
- `node tests/clipboard_unit.mjs`: texto con formato (CF_HTML) para el portapapeles.
- `node tests/rdpfile_unit.mjs`: archivos .rdp de Conexión a Escritorio remoto: leídos como conexión y escritos desde una.
- `node tests/mic_unit.mjs`: el micrófono en el navegador: remuestreo y PCM de 16 bits, su captura y cuándo se suelta.
- `tests/gfx_unit.mjs`, `tests/rdpgfx_unit.mjs` (ZGFX, ClearCodec, NSCodec, RemoteFX, progresivo, SPS H.264), `tests/bulk_unit.mjs` (MPPC, NCRUSH, XCRUSH, fragmentos), `tests/orders_unit.mjs` (también órdenes de ventana), `tests/audio_codecs.mjs` (requiere sox): pruebas unitarias de decodificadores (Node.js).
- `node tests/session_unit.mjs` (entrada, reconexión, órdenes de dibujo, worker del flujo, varias pantallas, RemoteApp), `node tests/ui_unit.mjs` (perfil, auditoría, ventana de sesión, descargas ZIP, captura, explorador de archivos, sesión caducada, archivos .rdp, conexiones del administrador, RemoteApp, panel Archivos, barra movible, aviso de actualización, las ventanas de las otras pantallas): código del navegador sobre dobles mínimos.
- `php tests/fuzz.php`, `node tests/fuzz.mjs`: datos aleatorios y mutados contra la pasarela (núcleo RDP y canales, también RemoteApp y el micrófono) y los decodificadores del navegador; semilla fija, opciones `--seed`, `--iterations`, `--target`, `--case` y `--strict`.
- `tests/gfx_render.mjs`, `tests/audio_check.mjs`: decodifican los volcados de `rdp_probe.php` (`DUMP=fichero`, `AUDIO=1`).
- `tests/e2e.mjs`, `tests/e2e_clip.mjs`, `tests/e2e_gfx.mjs` (H.264: compara el canvas con una captura del remoto), `tests/e2e_passkey.mjs`, `tests/e2e_lang.mjs`, `tests/e2e_glyphs.mjs`: pruebas en Chrome (Node.js + Playwright); `tests/e2e_firefox.py`: pruebas en Firefox (Selenium). Solo desarrollo.

En GitHub, cada envío pasa `tests/run.php` y `tests/fuzz.php` en Linux y Windows con PHP 8.1, 8.2, 8.3, 8.4 y 8.5 (cualquier aviso, notice o deprecación hace fallar), y todas las `tests/*_unit.mjs` y `tests/fuzz.mjs` con la LTS actual y la anterior de Node.js.

## Documentación

- [Manual de instalación (todos los niveles)](docs/es/INSTALACION.md)
- [Guía de uso](docs/es/USO.md)
- [Hosting y despliegue](docs/es/HOSTING.md)
- [Arquitectura y modelo de seguridad](docs/es/ARQUITECTURA.md)
- [Pruebas y resultados](docs/es/PRUEBAS.md)
- [Rendimiento: ancho de banda, CPU y memoria por códec](docs/es/RENDIMIENTO.md)
- [Teclado del móvil y Windows 11](docs/es/TECLADO-MOVIL.md)
- [Hoja de ruta y decisiones](docs/es/HOJA-DE-RUTA.md)
- [Historial de cambios](CHANGELOG.md) · [Política de seguridad](SECURITY.md) · [Cómo contribuir](CONTRIBUTING.md)

## Autoría y forma de trabajo

**Miguel Sánchez** ([miguelenred.es/mudeskclient](https://miguelenred.es/mudeskclient)) es el autor de MuDeskClient:

- suya es la idea del proyecto;
- él organiza el trabajo: qué se hace y en qué orden;
- él dicta la forma de trabajar: primero la seguridad, el rendimiento medido antes y después de cada cambio, una prueba que falla sin cada cambio crítico, la documentación al día en español e inglés, y honestidad sobre lo que está probado y lo que no.

Está asistido por inteligencia artificial, que escribe código, pruebas y documentación siguiendo sus indicaciones; él revisa el resultado, lo prueba en equipos reales y decide qué entra.

Por transparencia, lo que está probado, cómo se probó y lo que **todavía no** se ha probado está en [Pruebas](docs/es/PRUEBAS.md); las limitaciones conocidas, arriba y en la [hoja de ruta](docs/es/HOJA-DE-RUTA.md).

## Licencia

MuDeskClient es software libre con la **Apache License 2.0**: se puede usar, modificar y redistribuir, también con fines comerciales, cumpliendo sus condiciones (conservar la licencia y el archivo NOTICE, e indicar lo que se haya cambiado). Ver [LICENSE](LICENSE) y [NOTICE](NOTICE).

No es obligatorio, pero si lo usas se agradece la mención **«Powered by MuDeskClient»** (con un enlace a https://miguelenred.es/mudeskclient).
