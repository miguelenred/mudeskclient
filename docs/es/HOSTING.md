# Hosting y despliegue

[English](../HOSTING.md)

Esta es la referencia técnica. Para una guía paso a paso para todos los niveles (un hosting, Windows con XAMPP, Linux con Apache, cortafuegos, problemas frecuentes), ver el [manual de instalación](INSTALACION.md).

## Antes de desplegar: `tools/check.php`

1. Cambia `TOKEN` al principio de `tools/check.php`.
2. Cópialo dentro de `public/` en el hosting (la carpeta `tools/` está cerrada a la web) y ábrelo en el navegador.
3. Ejecuta las seis pruebas:
   - **Entorno PHP**: versión, OpenSSL, funciones desactivadas, límites, sockets Unix. Avisa si PHP no es 8.1–8.5, si `display_errors` está activo y si `memory_limit` es menor de 128M.
   - **RDP saliente**: conecta con un equipo remoto real e informa de TLS/NLA, versión de TLS y certificado.
   - **Petición larga**: déjala 600 s; debe llegar al final y el retraso por búfer debe ser bajo.
   - **Latencia**: ida y vuelta al hosting.
   - **Carpeta de datos accesible desde la web**: escribe un fichero de prueba en la carpeta de datos e intenta descargarlo por la web; no debe poder.
   - **Procesos PHP a la vez**: lanza peticiones de 1 s a la vez (20 por defecto) y cuenta cuántas atendió el servidor juntas (N); `max_sessions_total` debe ser como mucho N − 2 (ver *Capacidad*). Con HTTP/1.1 el navegador abre como mucho 6 conexiones, así que no puede medir más.
4. **Borra el fichero** después.

## Requisitos

| Elemento | Necesario |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 u 8.5 |
| Extensiones | `openssl`, `session` (ambas de serie y activas en prácticamente cualquier PHP) |
| Funciones | `stream_socket_client`, `stream_socket_enable_crypto`, `stream_select`, `set_time_limit`, `ignore_user_abort`; recomendable `stream_socket_server` |
| OPcache | recomendable (la caché de código de PHP, `opcache.enable=1`): cada petición corta de entrada y de confirmación de fotogramas le cuesta entonces a PHP unos 5 ms en vez de 15 (medido en Windows con el servidor integrado de PHP), y cada tecla y cada movimiento del ratón la esperan |
| HTTPS | obligatorio (un certificado autofirmado sirve tras aceptarlo una vez en el navegador) |
| TCP saliente | del servidor web al puerto RDP de cada equipo remoto |
| Opcional | HTTPS saliente hacia el proveedor de OpenID Connect, con su certificado comprobado (`openssl.cafile` en Windows); el puerto RDP del equipo que comprueba las cuentas de Windows; el puerto HTTPS de una puerta de enlace de Escritorio remoto (443 de serie) para las conexiones que pasan por ella, en vez del puerto RDP de sus equipos |

## Estructura de ficheros

Valen dos disposiciones. Solo `public/` tiene que ser accesible desde la web.

**A. Todo el proyecto dentro de la raíz web** (lo normal en un hosting compartido):

```
/home/cuenta/public_html/mudesk/            todo el proyecto; se abre en https://tu-dominio/mudesk/
/home/cuenta/public_html/mudesk/data        con escritura para PHP
```

Los `.htaccess` del proyecto (y los `web.config` para IIS) dejan fuera de alcance todo salvo `public/`: el de la raíz manda cada petición a `public/`, y `data/`, `src/`, `tests/`, `tools/` y `docs/` niegan cualquier acceso y el listado (Apache 2.2 y 2.4). Si se borra el `.htaccess` de `data/`, la aplicación lo vuelve a escribir. Tras subirlo comprueba (cada uno debe responder **403**): `/mudesk/data/web-check.txt`, `/mudesk/config.php`, `/mudesk/src/bootstrap.php`. Si el servidor no aplica `.htaccess` (`AllowOverride None`), la aplicación muestra un aviso en rojo en la pantalla de instalación y en la sección de administración, porque la carpeta de datos se podría descargar.

**B. Solo `public/` en la raíz web** (lo más seguro si el hosting lo permite):

```
/home/cuenta/mudesk/src
/home/cuenta/mudesk/data          con escritura para PHP
/home/cuenta/mudesk/config.php    opcional
/home/cuenta/public_html          <- contenido de public/
```

Si `public_html` no está junto a `src/`, como aquí, cambia el primer `require` de `index.php`, `api.php`, `rdp.php` y `files.php` (aquí por `require __DIR__ . '/../mudesk/src/bootstrap.php';`).

### Proteger las carpetas privadas según el servidor web

Solo `public/` se puede servir. Compruébalo tras desplegar: `data/web-check.txt`, `config.php` y `src/bootstrap.php` deben responder **403** (o 404), nunca 200; además la aplicación avisa al administrador si la carpeta de datos se puede leer.

- **Apache** (2.2 y 2.4): lo hacen los `.htaccess` incluidos; necesitan `AllowOverride All` (o al menos `AuthConfig FileInfo Indexes Limit Options`; el de la raíz usa `DirectoryIndex`). Probado.
- **LiteSpeed Enterprise** (la mayoría de hostings compartidos con cPanel/LiteSpeed): lee los `.htaccess` como Apache; no hay que hacer nada más.
- **OpenLiteSpeed**: no aplica las directivas de acceso de `.htaccess`, solo las reglas de reescritura. Las carpetas privadas llevan además `RewriteRule ^ - [F]`, que OpenLiteSpeed aplica si la reescritura está activada en el host virtual (*Rewrite → Enable Rewrite: Yes* y *Auto Load from .htaccess: Yes*); reinícialo tras subir los ficheros. Más seguro: un *Context* por carpeta (`/mudesk/data/`, `/mudesk/src/`, `/mudesk/tests/`, `/mudesk/tools/`, `/mudesk/docs/`) con *Accessible: No*, o que la raíz del documento sea `public/`.
- **Nginx**: no lee `.htaccess`. Apunta `root` a `public/` (lo mejor) o, con todo el proyecto bajo la raíz web (aquí en `/mudesk/`), añade al bloque `server` (probado):

  ```nginx
  location ~ ^/mudesk/(data|src|tests|tools|docs|vendor)(/|$) { deny all; }
  location ~ ^/mudesk/(config(\.sample)?\.php|web\.config|\.htaccess|[^/]+\.md|composer\.(json|lock)|LICENSE|NOTICE)$ { deny all; }
  location = /mudesk/ { return 302 /mudesk/public/; }
  ```

  Sin el prefijo `/mudesk` si el proyecto está en la raíz del sitio.
- **IIS**: el `web.config` de la raíz oculta `data`, `src`, `tests`, `tools`, `docs`, `config.php`, `config.sample.php`, `LICENSE`, `NOTICE` y los ficheros `.md`/`.json`/`.lock`/`.log` con el *filtrado de solicitudes*, y cada carpeta privada tiene un `web.config` que rechaza cualquier fichero (la aplicación lo vuelve a escribir en `data/` si falta). Solo usa funciones básicas de IIS, sin el módulo URL Rewrite. Abre la aplicación en `.../public/` o apunta el sitio a `public/`. Aún no probado en un IIS real.

### Permisos de escritura

| Carpeta / fichero | PHP necesita | Para qué |
|---|---|---|
| `data/` y todo su contenido | lectura + escritura | usuarios, ajustes, conexiones, auditoría, sesiones, archivos compartidos y ficheros de trabajo; PHP crea `audit/`, `connections/`, `files/`, `hosts/`, `recordings/`, `run/`, `sessions/`, `tmp/`, `trace/`, `.htaccess`, `web.config`, `index.html`, `web-check.txt`, `secret.php`, `audit-key.php` (clave de las exportaciones firmadas de la auditoría) y, en la primera visita, `setup-code.php` |
| Carpeta temporal de PHP (`upload_tmp_dir` en php.ini o, si no está puesta, la del sistema: `sys_temp_dir`, `TMPDIR`/`TMP`, `/tmp`) | escritura | fuera del proyecto, y ya la tiene en los hostings, en XAMPP (`C:\xampp\tmp`) y en Linux. PHP guarda allí lo que se envía al servidor de más de 16 KB (archivos que se suben, pegados largos) mientras llega, y lo descarta si no puede escribir. En Linux y macOS también van allí los sockets de las sesiones cuando la ruta de `data/run/` llega a 100 caracteres |
| `public/`, `src/`, `config.php`, ficheros `.htaccess` | solo lectura | código y configuración: ahí no se escribe nada |

Las carpetas se crean con permisos `0700` y los ficheros con `0600` cuando el hosting lo permite. Si el hosting ejecuta PHP con un usuario distinto del de FTP, dale escritura solo sobre `data/`.

En **Windows** (XAMPP, IIS) la cuenta con la que se ejecuta el servidor web necesita el permiso *Modificar* sobre `data` (y sobre la carpeta temporal de PHP, si se lo han quitado). Esa cuenta es la del servicio de Apache o del grupo de aplicaciones de IIS (Servicios → el servicio → *Iniciar sesión*), o tu propia cuenta si arrancas Apache desde el panel de XAMPP. En el Administrador de tareas → Detalles se ve en la columna *Nombre de usuario* de `httpd.exe` o `w3wp.exe`. Desde una consola como administrador:

```
icacls "C:\xampp\htdocs\mudesk\data" /grant "CUENTA:(OI)(CI)M"
```

Si `data/` no tiene escritura, la aplicación muestra la página «No se puede guardar en la carpeta de datos». El registro de errores del servidor dice qué carpeta no pudo crear. Si la carpeta temporal de PHP no tiene escritura, una petición de más de 16 KB recibe «PHP no puede escribir en su carpeta temporal» (una subida se detiene con ese mensaje) en lugar de tomarse por vacía, y el registro de errores dice qué carpeta es.

### Errores

La aplicación nunca muestra al navegador los mensajes de PHP, ni rutas del servidor ni trazas, aunque `display_errors` esté activado (XAMPP lo trae activado). Los avisos, las excepciones y los errores fatales van al registro de errores del servidor: el `error_log` de php.ini (en XAMPP, `php\logs\php_error_log`) o, si no hay, el de Apache o IIS. La única excepción es lo que PHP muestra al empezar una petición, antes de que se ejecute la aplicación, si `display_startup_errors` está activado (XAMPP lo trae activado, y PHP 8 lo activa cuando php.ini no lo indica): por ejemplo, que no pudo guardar el cuerpo de una petición porque su carpeta temporal no tiene escritura. Desactívalo en un servidor que usen otras personas.

Cuando no puede seguir, quien abre la dirección ve una página con el aspecto de la pantalla de acceso, en el idioma del navegador (español o inglés). La página dice qué pasa y qué debe hacer el administrador, y lleva el código del error. Los casos son:

- carpeta de datos sin escritura;
- carpeta temporal de PHP sin escritura;
- PHP no admitido o sin OpenSSL;
- HTTPS obligatorio;
- IP no permitida;
- error interno, incluido quedarse sin memoria.

Las peticiones de la propia interfaz reciben `{"error": clave}` y muestran el mismo motivo con «Volver a intentarlo».

Aunque la carpeta de datos llegara a servirse, la clave del servidor se guarda en `data/secret.php`, que empieza por `<?php exit; ?>`: PHP no muestra nada. Las contraseñas RDP van cifradas con una clave que solo se abre con la contraseña de cada usuario, y las de acceso son hashes bcrypt.

## Servidor web

El flujo de la sesión no puede acumularse ni comprimirse.

- **Apache + mod_php / LiteSpeed**: `public/.htaccess` desactiva la compresión de `rdp.php`. En el host virtual de Apache, `LimitRequestBody 8388608` rechaza un cuerpo de más de 8 MB antes de dárselo a PHP (ninguna petición de MuDeskClient envía más de 4 MB; la imagen Docker lo lleva), como hace `client_max_body_size` en Nginx, más abajo.
- **Apache + PHP-FPM (`mod_proxy_fcgi`)**: añade `flushpackets=on` al `ProxyPass` o al manejador.
- **Nginx + PHP-FPM**:

  ```nginx
  location = /rdp.php {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/rdp.php;
      fastcgi_pass unix:/run/php/php-fpm.sock;
      fastcgi_buffering off;
      fastcgi_read_timeout 24h;
      gzip off;
  }
  client_max_body_size 8m;
  ```

  Esto supone que `root` apunta a `public/`; con todo el proyecto bajo `/mudesk/`, usa `location = /mudesk/public/rdp.php` y `$document_root/mudesk/public/rdp.php`, y deniega las carpetas privadas como en *Proteger las carpetas privadas*, más arriba. `rdp.php` envía además `X-Accel-Buffering: no`.
- **IIS** (aún sin probar): el manejador FastCGI de PHP no debe acumular el flujo (`responseBufferLimit="0"`) y `requestTimeout` y `activityTimeout` de la aplicación FastCGI deben permitir peticiones largas.
- **Proxy inverso / CDN** (Cloudflare y similares): cortan las peticiones largas (≈100 s en Cloudflare). El cliente reconecta solo, pero es mejor conexión directa. Detrás de un proxy propio, pon su IP en `trusted_proxies`. Un proxy en la misma máquina (127.0.0.1) debe enviar además `X-Forwarded-Proto: https`: al ponerlo en la lista deja de tomarse como petición local, así que a través de él se exige HTTPS como desde cualquier otro sitio (antes, las peticiones a través de él no pasaban la comprobación de HTTPS).
- **Límites de PHP**: el flujo llama a `set_time_limit(0)`; comprueba que el servidor web o FPM (`request_terminate_timeout`) no corten peticiones largas. Las descargas y los ZIP de la carpeta compartida (`files.php`, hasta 4 GB) también son peticiones largas.
- **App instalable**: el manifiesto (`manifest.webmanifest`) debe servirse como `application/manifest+json`. Apache y LiteSpeed: lo hace `public/.htaccess`; IIS: `public/web.config`. Nginx: si su `mime.types` no conoce `.webmanifest`, añade en él `application/manifest+json webmanifest;`.
- **Scripts y estilos tras una actualización**: la página pide `app.js` y `app.css` con una huella de sus archivos en la dirección (cambia con cualquiera de ellos), pero los módulos que importan van sin ella, así que el servidor web debe hacer que se revaliden en cada carga (un 304 si no han cambiado). Apache y LiteSpeed: lo hace `public/.htaccess` (con `mod_headers`; sin él, la regla se salta); IIS: `public/web.config` y `public/assets/web.config` (aún sin probar en un IIS real); Nginx: añade al bloque `server` `location ~* \.(js|css)$ { add_header Cache-Control "no-cache"; }`. Una página abierta mientras se actualiza MuDesk lo dice la próxima vez que se entra en ella y ofrece recargar.

## Capacidad

- Cada sesión abierta ocupa **un proceso PHP** todo el tiempo (un hijo de php-fpm o un proceso de Apache). Un equipo remoto que no responde lo retiene hasta 8 s por cada paso del saludo.
- Mientras se escribe o se mueve el ratón, una sesión añade hasta **cuatro peticiones cortas a la vez**; mientras un programa remoto graba del micrófono, unas diez por segundo (trozos de 100 ms, dentro de esas cuatro); mientras un programa de allí usa la cámara o la carpeta de este equipo, hasta dos más a la vez por un carril propio.
- Cada invitado que ve una sesión compartida ocupa **un proceso PHP más** mientras mira (como mucho 5 por sesión).
- `memory_limit`: un mensaje de un canal mayor que una sexta parte de lo que el límite deja libre (copiar una captura 4K son 33 MB) se deja fuera, sin pegarse, y queda en el registro de errores, en vez de terminar la sesión con un error fatal de PHP; con 256M o más pasan esas copias.
- Las conexiones grabadas escriben en `data/recordings/` mientras duran sus sesiones (unas decenas de MB por hora en un escritorio de oficina; mucho más con vídeo), guardadas los días de los ajustes: deja sitio en el disco.
- Pon *Administración → Ajustes → Sesiones RDP a la vez (todos los usuarios)* (`max_sessions_total`, 20 por defecto, 0 = sin límite) por debajo de los procesos PHP disponibles: con PHP-FPM, `pm.max_children` ≥ `max_sessions_total` + los que necesita la web (2 como mínimo, como cuenta `tools/check.php`; unos 10 para ir holgado). Al llegar al límite, una sesión nueva recibe al momento «servidor ocupado», sin conectar. `tools/check.php` mide los procesos a la vez: `max_sessions_total` como mucho N − 2.
- Si es posible, sirve `rdp.php` desde un pool de PHP-FPM propio (su propio socket en el bloque `location = /rdp.php` de arriba), así las sesiones largas nunca ocupan los procesos de la web.
- El servidor integrado de PHP (`php -S`) atiende de una en una: una sesión bloquea todo. Solo para pruebas; `PHP_CLI_SERVER_WORKERS` (varios procesos) no funciona en Windows.
- En hostings con límite de "entry processes" (por ejemplo CloudLinux) hace falta al menos `5 × sesiones simultáneas`.
- Ancho de banda del servidor web al navegador: desde pocos KB/s (trabajo de oficina) hasta 2–3 MB/s (cambios a pantalla completa a 16 bits). Del navegador al servidor web, la entrada son pocos KB/s; el micrófono añade unos 176 KB/s mientras un programa remoto graba, y la cámara unos 150–185 KB/s con las cámaras simuladas de las pruebas (unos 0,5–1,5 MB/s una real, según una estimación) mientras un programa la usa.

## Nombres de equipo (DNS)

Los nombres de los equipos remotos se resuelven con el resolutor del sistema, así siguen funcionando el fichero `hosts`, los dominios de búsqueda y la resolución de nombres de Windows; MuDeskClient no tiene resolutor propio. Un servidor DNS que no responde retiene el proceso PHP hasta que el resolutor del sistema se rinde (su propio tiempo límite). La sesión se admite antes de resolver el nombre, así que `max_sessions_total` también acota cuántos procesos pueden quedarse esperando al DNS a la vez. Un nombre que no resuelve se rechaza como destino no permitido.

## Hostings restringidos

- En Windows la entrada de la sesión va por un puerto TCP solo de este equipo (127.0.0.1, con un secreto de la sesión). Con `stream_socket_server` desactivada, la pasarela usa sola un fichero de cola revisado cada 20 ms (cada tecla puede esperar hasta 20 ms); `'ipc' => 'file'` en `config.php` lo fuerza.
- Puerto 3389 saliente bloqueado: pídelo al proveedor o usa otro puerto en el equipo remoto (se configura en cada conexión).

## Equipos remotos

- Abre el puerto RDP solo a la IP del servidor web (por ejemplo en el cortafuegos: NAT 3389 → equipo, origen = IP del hosting).
- Mantén NLA activado (viene así en las versiones actuales de Windows).
- **Puerta de enlace de Escritorio remoto** (probada a través de una puerta de enlace de código abierto, y su transporte RPC contra una puerta de enlace escrita según las especificaciones, por la que pasó también un cliente independiente; aún no contra la de Windows Server): MuDeskClient le habla por HTTPS con el transporte HTTP sobre WebSocket (Windows Server 2016 o posterior) o con el transporte antiguo de dos conexiones HTTPS (2012, 2012 R2) o, si no tiene ninguno de los dos (2008, 2008 R2), con RPC sobre HTTP: dos conexiones HTTPS a `/rpc/rpcproxy.dll`, una que lleva lo que envía este servidor en el cuerpo de una petición de 1 GiB (que se rehace al enviar la mitad) y otra que trae lo que envía la puerta de enlace (que se rehace cuando ella lo pide), con entrada solo por NTLM (sin Kerberos en ese transporte). Un error HTTP que esa puerta de enlace conteste en la conexión por la que envía este servidor no se lee: se ve como una puerta de enlace que no contesta (a los 8 s). En los otros dos transportes entra con Kerberos si este servidor puede pedir un ticket para ella (abajo); si no, con NTLM, con el esquema NTLM o con Negotiate si es el único que ofrece (una puerta de enlace que solo acepte tarjetas inteligentes no sirve); con la *Protección extendida* activada, va el enlace de canal de su certificado. La lista de destinos RDP permitidos se aplica a la puerta de enlace: los equipos que hay detrás son los que sus directivas de autorización de recursos (RD RAP) permiten al usuario, con el nombre con que ella los conoce. El proceso de la sesión pasa los bytes del túnel por un puerto TCP de 127.0.0.1 que abre él mismo, así que `stream_socket_server` tiene que estar permitida.
- **Kerberos** (Active Directory; probado contra un reino de prueba y servidores simulados, aún no contra un dominio real): para NLA, la puerta de enlace de Escritorio remoto y la entrada con cuenta de Windows, antes que NTLM cuando el dominio se conoce por su nombre DNS. Este servidor necesita el puerto TCP 88 hacia un controlador de dominio, que encuentra en el DNS (registros SRV `_kerberos._tcp`; solo el puerto 88, nunca este equipo ni direcciones de enlace local) o que se indica en `config.php` (`kerberos_kdc`); si no, se usa NTLM. El inicio de sesión con el KDC tiene 8 segundos en total (un KDC que no contesta: NTLM después); las consultas al DNS usan el resolvedor del sistema, con sus propias esperas (`'kerberos_dns' => false` deja solo `kerberos_kdc`). Claves AES, y RC4 solo si se permite en *Administración → Ajustes* (desactivado de serie; un dominio Windows Server 2003 solo tiene RC4; ver *Known limits* en [SECURITY](../../SECURITY.md)). Un usuario@dominio cuyo sufijo no es el de su dominio (un sufijo UPN alternativo) se busca en el KDC del dominio de la conexión o del nombre DNS del equipo; una cuenta local (sin dominio, `.` o el nombre del propio equipo) nunca usa Kerberos. Si el servidor rechaza el ticket, o está en otro dominio del bosque, se entra con NTLM, y también en un equipo al que se llega por su dirección (como aquel al que un agente de conexión envía la sesión sin puerta de enlace). `'kerberos' => false` lo desactiva.
- **Agente de conexión** (sus tokens de enrutamiento probados contra Windows 11; aún no contra un agente real): el equipo de la conexión es el agente, con la `loadbalanceinfo` de la granja; los servidores a los que envía la sesión también tienen que ser destinos permitidos (por su dirección o por su nombre), salvo que la conexión pase por una puerta de enlace; si este servidor no llega a una de las direcciones que nombra, se prueba la siguiente que nombró. Sin puerta de enlace se llega a ellos primero por su dirección: NLA usa allí Kerberos si el agente da también el nombre del servidor, y si no, NTLM. Una contraseña que el agente cifró para su servidor se le entrega allí con RDSTLS.
- **Windows XP y Server 2003** (y los Windows posteriores configurados con la *capa de seguridad RDP*): sin TLS (el de Server 2003 no comparte ningún cifrado con OpenSSL 3; los posteriores están configurados para no usarlo), MuDeskClient usa con ellos la *seguridad RDP estándar*, solo si la seguridad de la conexión es *Automática* (sus riesgos, en [SECURITY](../../SECURITY.md)). El nivel de cifrado es el del equipo: en *Bajo* solo se cifra lo que envía MuDeskClient, así que mejor *Alto* (directiva *Establecer el nivel de cifrado de conexión de cliente*); un equipo que no cifra nada se rechaza.
- Para la impresora **MuDeskPDF**, el Windows remoto necesita el controlador "Microsoft Print To PDF" (Windows 10 / Server 2016 o posterior) y que la directiva permita redirigir impresoras.
- Para varias pantallas en Windows de escritorio, el RDP multimonitor debe estar permitido (ediciones Pro/Enterprise; lo está por defecto).
- Para una conexión **RemoteApp** (un programa suelto), el equipo remoto tiene que permitir ese programa: en Windows Server, publícalo como programa RemoteApp de Servicios de Escritorio remoto (su alias va en la conexión tal cual, `Alias`: MuDesk le añade las `||` delante); en Windows 10/11 Pro, regístralo en `HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications`, o permite cualquier programa a quien ya puede entrar por Escritorio remoto con el valor `fDisabledAllowList` (DWORD) = 1 en `TSAppAllowList`. Windows Home no acepta conexiones de Escritorio remoto.
- **Sonido en Windows Server**: su servicio *Audio de Windows* viene apagado, así que la sesión no tiene dispositivo de audio y no envía sonido, tenga lo que tenga el navegador (la traza de la pasarela muestra entonces el canal `rdpsnd` abierto y nada por él). En el servidor, como administrador: `Set-Service Audiosrv -StartupType Automatic; Start-Service Audiosrv` (y lo mismo con `AudioEndpointBuilder` si está parado); la directiva *Permitir la redirección de reproducción de audio y vídeo* (Host de sesión de Escritorio remoto → Redirección de dispositivos y recursos) no debe estar deshabilitada. Después cierra la sesión remota (no basta con desconectar) y vuelve a conectar con **Audio del equipo remoto** marcado.
- **Micrófono** (la casilla **Micrófono y cámara de este equipo en el remoto**, desmarcada de serie): el Windows remoto tiene que permitir la redirección de grabación de audio. Windows 7 y posteriores y Windows Server 2012 y posteriores la permiten salvo que una directiva lo prohíba; para comprobarlo, o activarla, como administrador en el equipo remoto:
  1. Ejecuta `gpedit.msc` y ve a *Configuración del equipo → Plantillas administrativas → Componentes de Windows → Servicios de Escritorio remoto → Host de sesión de Escritorio remoto → Redirección de dispositivos y recursos*.
  2. Abre **Permitir redirección de grabación de audio** (*Allow audio recording redirection* en un Windows en inglés), elige *Habilitada* y pulsa Aceptar (*Deshabilitada* la prohíbe; *No configurada* deja lo que Windows trae de serie).
  3. Ejecuta `gpupdate /force`, cierra la sesión remota (no basta con desconectar) y vuelve a conectar con la casilla marcada.

  Sin el editor de directivas (Windows Home tampoco tiene servidor de Escritorio remoto), lo mismo como valor del registro: `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v fDisableAudioCapture /t REG_DWORD /d 0 /f` (0 la permite, 1 la prohíbe). En **Windows Server** además tiene que estar en marcha el servicio *Audio de Windows* (ver el punto del sonido): sin él la sesión tampoco tiene micrófono. Con eso, la sesión muestra un dispositivo de grabación llamado *Audio remoto*; Windows abre el micrófono solo mientras un programa graba allí (una llamada, un dictado, *Configuración → Sonido → Audio remoto → Probar el micrófono*), y el navegador pregunta una vez si esta página puede usarlo. Los navegadores solo dejan usar el micrófono en páginas abiertas con `https://` (o en el propio equipo del servidor, `http://localhost`). El audio llega a Windows según se graba, en PCM de 16 bits en el formato que Windows pide (44,1 kHz estéreo en Windows 11: unos 176 KB/s, 1,4 Mbit/s, de subida mientras un programa graba; nada el resto del tiempo).
- **Cámara** (la misma casilla, **Micrófono y cámara de este equipo en el remoto**): Windows 10 1803 y posteriores y Windows Server 2019 y posteriores; Windows permite la redirección de captura de vídeo tal como viene. Si la cámara («Cámara de MuDesk (redirigido)») no aparece en la sesión, está habilitada la directiva **No permitir el redireccionamiento de la captura de vídeo** (*Do not allow video capture redirection* en un Windows en inglés), en la misma carpeta del editor de directivas: déjala en *No configurada* o *Deshabilitada*, o con el registro, `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v fDisableCameraRedir /t REG_DWORD /d 0 /f` (`/d 1` la prohíbe). Los programas de la sesión llegan a ella a través del servicio de cámara de Windows (*Windows Camera Frame Server*): si se quedan esperando sin fin, ese servicio puede estar atascado; reiniciarlo (o el equipo) lo arregla.
- **Una carpeta de este equipo** (el panel *Archivos* de la sesión) es una unidad redirigida más: necesita la carpeta compartida de la conexión y la redirección de unidades permitida por directiva (*No permitir redirección de unidad* sin habilitar), igual que la carpeta compartida.
- **H.264 con el color completo (AVC444)**: Windows envía H.264 en su forma 4:2:0 (AVC420: el color a media resolución, que emborrona un poco los bordes de color del texto pequeño) salvo que una directiva le pida preferir AVC444. Para activarla, en el equipo remoto, como administrador:
  1. Ejecuta `gpedit.msc` (Windows Pro, Enterprise, Education y Server).
  2. Ve a *Configuración del equipo → Plantillas administrativas → Componentes de Windows → Servicios de Escritorio remoto → Host de sesión de Escritorio remoto → Entorno de sesión remota*.
  3. Abre **Dar prioridad al modo de gráficos H.264/AVC 444 en las conexiones a Escritorio remoto**, elige *Habilitada* y pulsa Aceptar. En la misma página, **Configurar la codificación por hardware de H.264/AVC para las conexiones a Escritorio remoto** en *Habilitada* deja que lo codifique la tarjeta gráfica del servidor, cuando puede.
  4. Ejecuta `gpupdate /force`, cierra la sesión remota (no basta con desconectar) y vuelve a conectar.

  Sin el editor de directivas, lo mismo como valor del registro: `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v AVC444ModePreferred /t REG_DWORD /d 1 /f`. **Hoy MuDeskClient muestra AVC444 a partir de su parte 4:2:0**, con la misma calidad que AVC420: el color completo es el siguiente paso de la [hoja de ruta](HOJA-DE-RUTA.md), y esta directiva es la que necesitará. Los gráficos de la conexión deben estar en *Automático* o *Canal gráfico y H.264*.
