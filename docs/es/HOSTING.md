# Hosting y despliegue

[English](../HOSTING.md)

Esta es la referencia técnica. Para una guía paso a paso para todos los niveles (un hosting, Windows con XAMPP, Linux con Apache, cortafuegos, problemas frecuentes), ver el [manual de instalación](INSTALACION.md).

## Antes de desplegar: `tools/check.php`

La primera ejecución hace por sí sola la mayoría de estas comprobaciones, antes de crear el administrador ([INSTALACION](INSTALACION.md), primera ejecución). `tools/check.php` sirve para comprobar un hosting antes de instalar, o de nuevo más adelante:

1. Cambia `TOKEN` al principio de `tools/check.php`: 16 caracteres o más (con `CAMBIAR`, como viene, o con uno más corto, no hace nada: no tiene límite de intentos).
2. Cópialo dentro de `public/` en el hosting (la carpeta `tools/` está cerrada a la web) y ábrelo en el navegador. Funciona durante un día desde que se copió (o se cambió): después se niega, por si se olvidó allí; cópialo de nuevo para volver a usarlo.
3. Ejecuta las seis pruebas:
   - **Entorno PHP**: versión, OpenSSL, funciones desactivadas, límites, sockets Unix. Avisa si PHP no es 8.1–8.5, si `display_errors` está activo y si `memory_limit` es menor de 128M.
   - **RDP saliente**: conecta con un equipo remoto real e informa de TLS/NLA, versión de TLS y certificado. No con este mismo servidor, direcciones de enlace local ni metadatos de la nube, a los que tampoco se conecta la aplicación; cada prueba queda en el registro de errores de PHP con la dirección que la pidió.
   - **Petición larga**: déjala 600 s; debe llegar al final y el retraso por búfer debe ser bajo.
   - **Latencia**: ida y vuelta al hosting.
   - **Carpeta de datos accesible desde la web**: escribe un fichero de prueba en la carpeta de datos e intenta descargarlo por la web; no debe poder.
   - **Procesos PHP a la vez**: lanza peticiones de 1 s a la vez (20 por defecto) y cuenta cuántas atendió el servidor juntas (N); `max_sessions_total` debe ser como mucho N − 2 (ver *Capacidad*); donde el servidor rechaza las que pasan de su límite (508: un límite de "entry processes"; 503, 429), como mucho una quinta parte de N, porque cada sesión necesita hasta 5 a la vez. Con HTTP/1.1 el navegador abre como mucho 6 conexiones, así que no puede medir más.
4. **Borra el fichero** después. Mientras `public/check.php` esté allí, la administración lo dice en rojo.

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

Los `.htaccess` del proyecto (y los `web.config` para IIS) dejan fuera de alcance todo salvo `public/`: el de la raíz manda cada petición a `public/`, y `data/`, `src/`, `tests/`, `tools/` y `docs/` niegan cualquier acceso y el listado (Apache 2.2 y 2.4). Si se borra el `.htaccess` de `data/`, la aplicación lo vuelve a escribir. Tras subirlo comprueba (cada uno debe responder **403**): `/mudesk/data/web-check.txt`, `/mudesk/config.php`, `/mudesk/src/bootstrap.php`. Si el servidor no aplica `.htaccess` (`AllowOverride None`), la carpeta de datos se puede descargar, y la aplicación se para: cada página lo dice («La carpeta de datos se puede descargar») hasta que se protege.

**B. Solo `public/` en la raíz web** (lo más seguro si el hosting lo permite):

```
/home/cuenta/mudesk/src
/home/cuenta/mudesk/data          con escritura para PHP
/home/cuenta/mudesk/config.php    opcional
/home/cuenta/public_html          <- contenido de public/
```

Si `public_html` no está junto a `src/`, como aquí, cambia el primer `require` de `index.php`, `api.php`, `rdp.php`, `files.php`, `oidc.php`, `backup.php` y `health.php` (aquí por `require __DIR__ . '/../mudesk/src/bootstrap.php';`).

### Proteger las carpetas privadas según el servidor web

Solo `public/` se puede servir. Compruébalo tras desplegar: `data/web-check.txt`, `config.php` y `src/bootstrap.php` deben responder **403** (o 404), nunca 200. La aplicación comprueba la carpeta de datos por su cuenta: mientras se puede leer desde la web, nada funciona y cada página lo dice («La carpeta de datos se puede descargar»), hasta que se protege (mientras tanto se comprueba de nuevo cada 30 s, o 5 minutos después de que la encontrara un navegador). El servidor se la pide a sí mismo en la dirección y el puerto por los que llegó la petición, y también donde la encontró legible (una petición con el nombre de otro sitio u otra ruta nunca la da por protegida ella sola); donde no puede (el servidor integrado de PHP, un hosting que no le deja llegar a sí mismo) la comprueban los navegadores, que lo demuestran con un código aleatorio del archivo, nuevo cada vez que se mira (cada 10 minutos en el servidor integrado de PHP).

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
- **IIS**: el `web.config` de la raíz oculta `data`, `src`, `tests`, `tools`, `docs`, `config.php`, `config.sample.php`, `LICENSE`, `NOTICE` y los ficheros `.md`/`.json`/`.lock`/`.log` con el *filtrado de solicitudes*, y cada carpeta privada tiene un `web.config` que rechaza cualquier fichero (la aplicación lo vuelve a escribir en `data/` si falta). Solo usa funciones básicas de IIS, sin el módulo URL Rewrite. Abre la aplicación en `.../public/` o apunta el sitio a `public/`. Probado en el IIS de Windows Server 2022 (su manejador de PHP no debe usar búfer: ver *Servidor web* más abajo).

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

La aplicación nunca muestra al navegador los mensajes de PHP, ni rutas del servidor ni trazas, aunque `display_errors` esté activado (XAMPP lo trae activado). Los avisos, las excepciones y los errores fatales van al registro de errores del servidor: el `error_log` de php.ini (en XAMPP, `php\logs\php_error_log`) o, si no hay, el de Apache o IIS. La única excepción es lo que PHP muestra al empezar una petición, antes de que se ejecute la aplicación, si `display_startup_errors` está activado (XAMPP lo trae activado, y PHP 8 lo activa cuando php.ini no lo indica): por ejemplo, que no pudo guardar el cuerpo de una petición porque su carpeta temporal no tiene escritura, o que una petición pasa de `post_max_size` (la página envía los archivos, los del portapapeles y las copias de seguridad en trozos de 2 MB). Desactívalo en un servidor que usen otras personas.

Cuando no puede seguir, quien abre la dirección ve una página con el aspecto de la pantalla de acceso, en el idioma del navegador (español o inglés). La página dice qué pasa y qué debe hacer el administrador, y lleva el código del error. Los casos son:

- carpeta de datos sin escritura;
- carpeta temporal de PHP sin escritura;
- PHP no admitido o sin OpenSSL;
- HTTPS obligatorio;
- IP no permitida;
- la carpeta de datos se puede descargar (503, hasta que se protege);
- una copia de seguridad restaurándose (503, unos segundos);
- `trusted_proxies` con una entrada que no es una dirección IP (un rango o un nombre);
- error interno, incluido quedarse sin memoria.

Las peticiones de la propia interfaz reciben `{"error": clave}` y muestran el mismo motivo con «Volver a intentarlo».

Aunque la carpeta de datos llegara a servirse, la clave del servidor se guarda en `data/secret.php`, que empieza por `<?php exit; ?>`: PHP no muestra nada. Las contraseñas RDP van cifradas con una clave que solo se abre con la contraseña de cada usuario, y las de acceso son hashes bcrypt.

La clave del servidor se crea la primera vez que hace falta, entera o nada (se escribe aparte y después toma su nombre). Un `secret.php` vacío o cortado (una versión anterior podía dejarlo así si PHP se paraba mientras lo escribía o el disco estaba lleno) se aparta como `secret.php.damaged-<fecha>` y se crea de nuevo, avisado en el registro de errores de PHP, siempre que nada guardado lo necesite: ni líneas de auditoría, ni grabaciones, ni códigos de dos pasos, ni secreto OIDC. Si algo lo necesita, todas las páginas muestran «Algo ha fallado» y el registro de errores dice `Data file secret.php damaged (needed by …): restore it from a backup`: hay que reponer `secret.php` de una copia de esta instalación (`data/` tal como estaba, o la de la propia copia de seguridad). Sin copia, apartar el fichero dañado crea una clave nueva, y entonces hay que volver a configurar los códigos de dos pasos, las grabaciones anteriores no se pueden reproducir, hay que volver a escribir el secreto OIDC y las líneas anteriores de la auditoría salen como modificadas. `audit-key.php` (la clave de las exportaciones firmadas) en ese estado se crea siempre de nuevo.

### Archivos temporales

La aplicación borra sus propios archivos temporales: los de una sesión al terminar, los ficheros del portapapeles y los trabajos de impresión con su sesión. Lo que deja una escritura cortada o un proceso PHP que el hosting mata (una escritura sin terminar, `*.tmp` de más de una hora; los ficheros, la carpeta y el socket de una sesión que terminó sin su limpieza; las sesiones web caducadas, también donde el PHP del hosting nunca las recoge; los bloqueos de usuarios borrados; pasado un día, la parte de un archivo que una subida estaba sustituyendo, dejada a medias) se borra como mucho una vez por hora, con las peticiones (no hay ningún proceso en segundo plano). Los ficheros `*.lock` se quedan: son los propios bloqueos (vacíos, uno por fichero de datos y por usuario).

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
- **IIS** (probado en Windows Server 2022 con PHP por FastCGI): el manejador FastCGI de PHP no debe acumular el flujo (`responseBufferLimit="0"` en el manejador: con el búfer propio de IIS toda sesión se atasca, y la comprobación de la petición larga del primer arranque lo dice) y `requestTimeout` y `activityTimeout` de la aplicación FastCGI deben permitir peticiones largas. Los `web.config` del proyecto dejan sus carpetas privadas fuera de alcance.
- **Proxy inverso / CDN** (Cloudflare y similares): cortan las peticiones largas (≈100 s en Cloudflare). El cliente reconecta solo, pero es mejor conexión directa. Detrás de un proxy propio, pon su IP en `trusted_proxies`: solo las direcciones exactas de tus proxies, nunca un rango ni una dirección compartida con clientes que conectan directamente (quien envíe desde allí podría poner cualquier dirección en `X-Forwarded-For` y saltarse la lista de clientes permitidos y los bloqueos de acceso). Un proxy que no pasa el nombre público del sitio (su `Host`): pon la dirección del sitio en `public_url` de `config.php` (por ejemplo `https://rdp.ejemplo.com/mudesk`), que usan entonces el inicio de sesión con OpenID Connect y las llaves de acceso. Un proxy en la misma máquina (127.0.0.1) debe enviar además `X-Forwarded-Proto: https`: al ponerlo en la lista deja de tomarse como petición local, así que a través de él se exige HTTPS como desde cualquier otro sitio (antes, las peticiones a través de él no pasaban la comprobación de HTTPS). Mientras lleguen peticiones desde esta misma máquina con un `X-Forwarded-For` y no esté en la lista, la administración lo dice en un aviso.
- **Límites de PHP**: el flujo llama a `set_time_limit(0)`; comprueba que el servidor web o FPM (`request_terminate_timeout`) no corten peticiones largas. Las descargas y los ZIP de la carpeta compartida (`files.php`, hasta 4 GB) también son peticiones largas.
- **App instalable**: el manifiesto (`manifest.webmanifest`) debe servirse como `application/manifest+json`. Apache y LiteSpeed: lo hace `public/.htaccess`; IIS: `public/web.config`. Nginx: si su `mime.types` no conoce `.webmanifest`, añade en él `application/manifest+json webmanifest;`.
- **Scripts y estilos tras una actualización**: la página pide `app.js` y `app.css` con una huella de sus archivos en la dirección (cambia con cualquiera de ellos), pero los módulos que importan van sin ella, así que el servidor web debe hacer que se revaliden en cada carga (un 304 si no han cambiado). Apache y LiteSpeed: lo hace `public/.htaccess` (con `mod_headers`; sin él, la regla se salta); IIS: `public/web.config` y `public/assets/web.config` (probado en Windows Server 2022); Nginx: añade al bloque `server` `location ~* \.(js|css)$ { add_header Cache-Control "no-cache"; }`. Una página abierta mientras se actualiza MuDesk lo dice la próxima vez que se entra en ella y ofrece recargar.

## WebSocket (opcional)

En un servidor propio (Linux) el flujo de una sesión y su teclado y ratón pueden ir por un único WebSocket, a un pequeño servicio propio de MuDeskClient, en vez de por peticiones HTTP. Es opcional: sin él todo funciona igual (hostings compartidos, Windows, IIS: nada que montar). La página lo prueba una vez al abrirse y solo lo usa si responde; si no, HTTP como siempre; `'websocket' => false` en `config.php` lo desactiva (la página no lo prueba y el servicio termina en el acto). Las comprobaciones del primer arranque dicen si respondió.

Lo que aporta cuando está:

- **Menos retraso en el teclado y el ratón**: cada mensaje va por la conexión abierta de la sesión, en orden, sin una petición propia (sin ida y vuelta HTTP, sin un proceso PHP arrancado para él).
- **Menos procesos del servidor web**: una sesión ocupa su conexión (con Apache, el proceso que la pasa) y su propio proceso PHP, y nada más mientras se escribe; por HTTP, hasta cuatro peticiones más a la vez mientras se escribe o se mueve el ratón (*Procesos y límites*, más abajo). Importa sobre todo donde lo que se agota son los procesos a la vez.
- **Menos CPU y tráfico** por tecla y movimiento del ratón: unos pocos bytes de trama WebSocket en vez de las cabeceras de una petición y el arranque de PHP con sus comprobaciones (el token de la sesión se comprueba una vez por conexión).
- **El final se nota en el acto** cuando el navegador se va (su socket se cierra), sin esperar a la siguiente escritura.

Lo que necesita:

- Linux y la línea de órdenes de PHP (la misma versión y los mismos ajustes de `php.ini` que la web; sus sesiones reciben el `memory_limit` del servicio, o 256M si no tiene ninguno, lo normal en la línea de órdenes: `php -d memory_limit=512M tools/ws-server.php` les da más). Con `pcntl` y `posix` de PHP (la línea de órdenes de Debian y Ubuntu las tiene, y también la imagen de Docker) cada sesión es una copia del servicio, que compiló el código una sola vez: unos 5 a 8 MB menos por sesión que un PHP arrancado para ella, que es lo que hace sin ellas. El servicio se ejecuta con el usuario del servidor web (lee y escribe la misma carpeta de datos) y escucha solo en 127.0.0.1 (`websocket_listen` en `config.php`, `127.0.0.1:8091` por defecto).
- **Docker** y **`deploy/lxc/install.sh`** lo dejan montado: la imagen lo arranca junto a Apache (también en el chart de Kubernetes, cuyo ingress-nginx pasa los WebSocket tal cual); el script lo hace el servicio de systemd `mudesk-ws`.
- En otro servidor, arranca `php tools/ws-server.php` como servicio, con el usuario del servidor web; con systemd, por ejemplo:

  ```ini
  [Unit]
  Description=MuDeskClient WebSocket service
  After=network.target apache2.service
  JoinsNamespaceOf=apache2.service

  [Service]
  User=www-data
  Group=www-data
  ExecStart=/usr/bin/php /var/www/mudesk/tools/ws-server.php
  Restart=on-failure
  RestartSec=5
  KillMode=mixed
  TimeoutStopSec=30
  SuccessExitStatus=143
  PrivateTmp=true
  NoNewPrivileges=true

  [Install]
  WantedBy=multi-user.target
  ```

  `KillMode=mixed`: al pararlo se para solo el servicio, y sus sesiones terminan entonces como cuando su navegador se va (auditadas, sus archivos borrados). `JoinsNamespaceOf` y `PrivateTmp` le dan la carpeta temporal de Apache, que el Apache de Debian y Ubuntu tiene para sí: allí va el socket de una sesión cuando la ruta de la carpeta de datos es demasiado larga para uno. Con otro servidor web, pon ahí su servicio de PHP, o quita las dos líneas si ese servicio no tiene `PrivateTmp`. Después pásale `<public>/ws` (la dirección de la propia página más `ws`: `/ws` cuando `public/` es la raíz del sitio, `/mudesk/public/ws` con todo el proyecto bajo `/mudesk/`). Apache, con `proxy`, `proxy_http` y `proxy_wstunnel` (`a2enmod proxy proxy_http proxy_wstunnel`):

  ```apache
  <Location /ws>
      ProxyPass ws://127.0.0.1:8091/ws
      ProxyPreserveHost On
      RequestHeader set X-Forwarded-Proto https
  </Location>
  ```

  Nginx:

  ```nginx
  location = /ws {
      proxy_pass http://127.0.0.1:8091;
      proxy_http_version 1.1;
      proxy_set_header Upgrade $http_upgrade;
      proxy_set_header Connection "upgrade";
      proxy_set_header Host $http_host;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
      proxy_set_header X-Forwarded-Proto $scheme;
      proxy_read_timeout 24h;
      proxy_send_timeout 24h;
  }
  ```

- El `Host` del sitio debe llegar sin cambios al servicio (`ProxyPreserveHost On`, `$http_host`): solo acepta conexiones de las propias páginas del sitio (su `Origin`, contra ese esquema y `Host`). Toma la dirección del cliente del último salto de `X-Forwarded-For`, el que añadió el proxy que tiene delante en esta máquina (y los saltos anteriores según diga `trusted_proxies`, como por HTTP), así que la lista de clientes permitidos y los bloqueos de acceso ven la misma dirección.
- Una conexión ocupa la conexión del servidor web durante toda la sesión, como el flujo por HTTP: los tiempos de espera del proxy deben permitir horas (cada 10 s sale una trama para mantenerla viva, que reinicia la mayoría de los contadores de inactividad).
- Acepta 240 conexiones a la vez, 60 de ellas desde una misma dirección de cliente (una oficina detrás de una dirección: pasadas esas, sus sesiones van por HTTP), y solo arranca una sesión para un navegador cuya cookie de MuDeskClient nombra una sesión web que el servidor tiene: otra conexión se cierra antes de arrancar nada para ella.

## Capacidad

### Sesiones según el hardware

Medido en el equipo Windows de pruebas (el servidor integrado de PHP 8.3, una sesión por el canal gráfico con H.264, 1024 × 768): el proceso PHP que mantiene la sesión ocupó 17 MB (13 MB propios, frente a 7–9 MB de un proceso PHP sin nada abierto) y nada de CPU con el escritorio quieto; con texto desplazándose sin parar, el 2,7 % de un núcleo a unos 100 KB/s. Pasar 34 MB de cambios a pantalla completa costó 0,19 s de CPU en 14 s. Una sesión por el canal gráfico (Windows 8.1 y posteriores, en modo *Automático*) gasta poca CPU: lo que fija cuántas caben es la memoria (un proceso cada una, más grande dentro de un proceso de Apache), los procesos que el servidor permite a la vez y el ancho de banda. Con mapas de bits (xrdp, Windows antiguos) la pasarela gasta más: del 6 al 16 % de un núcleo con texto desplazándose y XCRUSH, el 30 % con MPPC ([RENDIMIENTO](RENDIMIENTO.md)), así que allí cuenta de 3 a 10 veces menos sesiones por núcleo.

Una estimación para uso de oficina a partir de esas cifras (no una prueba de carga): unos 50 MB y el 3 % de un núcleo por sesión, más 300 MB para el sistema y el servidor web.

| Servidor | Sesiones a la vez | Lo que se acaba antes |
|---|---|---|
| Hosting compartido | entry processes ÷ 5, o procesos PHP − 2 | los procesos del hosting (`tools/check.php`) |
| 1 vCPU, 1 GB | 10–15 | la memoria |
| 2 vCPU, 2 GB | 25–35 | la memoria |
| 4 vCPU, 4 GB | 60–70 | la memoria; desde unas 50, el ancho de banda (100 KB/s cada una: 40 Mbit/s) |
| 8 vCPU, 8 GB | 120–140 | el ancho de banda (100 Mbit/s para unas 120) |

Un vídeo o animaciones a pantalla completa ocupan 2–3 MB/s cada uno, lo que llena un enlace de 100 Mbit/s con 4 o 5 sesiones. Pon `max_sessions_total` (y, en Docker, `MUDESK_WORKERS`: unos 7 por sesión) según la fila que corresponda, y comprueba *Administración → Estado* (la memoria de la sesión más grande, los procesos PHP) con tu propio uso.

### Calcular lo que necesitas

Lo que ocupa cada sesión abierta a la vez, como punto de partida (trabajo de oficina; mide el tuyo en *Administración → Estado*):

| Por sesión | Canal gráfico (Windows 8.1, Server 2012 R2 y posteriores) | Mapas de bits (xrdp, Windows antiguos) |
|---|---|---|
| Memoria | unos 50 MB | unos 50 MB |
| CPU | un 3 % de un núcleo | un 10–30 % de un núcleo mientras cambia su pantalla |
| Ancho de banda hacia el navegador | unos 100 KB/s (0,8 Mbit/s); 2–3 MB/s con vídeo | lo mismo o más |
| Procesos PHP | 1 durante toda la sesión, hasta 4 más mientras se escribe por HTTP (ninguno con el servicio WebSocket) | lo mismo |

Más, una sola vez, unos 300 MB para el sistema y el servidor web. Para **N sesiones a la vez**:

- **Memoria** ≈ 300 MB + N × 50 MB, y una cuarta parte más de margen (copias grandes del portapapeles, grabaciones, picos): la tabla de arriba, leída por sus cifras más bajas.
- **CPU** ≈ N × 0,03 núcleos por el canal gráfico, o N × 0,2 con mapas de bits (una estimación por arriba: no cambian todas las pantallas a la vez); 1 vCPU como mínimo.
- **Ancho de banda** ≈ N × 0,8 Mbit/s para trabajo de oficina; cada vídeo a pantalla completa suma 16–24 Mbit/s.
- **Procesos PHP a la vez** ≥ N + 10 (`pm.max_children` de PHP-FPM, el `maxInstances` de la aplicación FastCGI de PHP en IIS); sin el servicio WebSocket y con mucha gente escribiendo a la vez, hasta 5 × N. Con PHP dentro de Apache (la imagen de Docker, `deploy/lxc/install.sh`), unos 7 × N procesos de Apache.

Por ejemplo, 40 sesiones de trabajo de oficina por el canal gráfico: 300 + 40 × 50 = 2.300 MB, unos 2,9 GB con el margen, así que 3 GB; 40 × 0,03 = 1,2 núcleos, así que 2 vCPU; 32 Mbit/s; *Sesiones RDP a la vez* 40, `pm.max_children` 50 (más sin el servicio WebSocket); en Docker, `MUDESK_WORKERS` 280 y un límite de 3 GB y 2 CPU.

### Aumentar la memoria y las sesiones

- **Los límites de MuDeskClient**: *Administración → Ajustes → Sesiones RDP a la vez (todos los usuarios)* (`max_sessions_total`, 20 de serie) y *Sesiones remotas simultáneas por usuario*; para un usuario, *Sesiones simultáneas* en su editor. Súbelos solo hasta donde permitan los procesos y la memoria del servidor (arriba).
- **`memory_limit` de PHP** (`php.ini`; en un hosting, su configuración de PHP): lo máximo que puede ocupar cada proceso PHP, no lo que ocupa; 256M (el de la imagen de Docker) deja pasar copias grandes del portapapeles. Reinicia PHP-FPM o el servidor web después de cambiarlo. Con `deploy/lxc/install.sh` está en `/etc/php/<versión>/apache2/conf.d/99-mudesk.ini` (volver a ejecutar el script pone otra vez sus 256M).
- **PHP-FPM**: en su pool (`/etc/php/<versión>/fpm/pool.d/www.conf` en Debian y Ubuntu, donde viene con 5: unas 3 sesiones) pon `pm.max_children` en las sesiones + 10 o más, y después `systemctl restart php<versión>-fpm`. Un proceso hijo solo ocupa memoria mientras funciona.
- **Apache con PHP dentro (mod_php)**: `MaxRequestWorkers` y `ServerLimit`, el mismo número, unos 7 por sesión (el `mpm_prefork.conf` de Debian viene con 150); reinicia Apache. Con `deploy/lxc/install.sh`: vuelve a ejecutarlo con `--workers N`.
- **IIS**: en la configuración de FastCGI, el máximo de instancias (`maxInstances`) de la aplicación de PHP en las sesiones + 10 o más, con su `requestTimeout` y `activityTimeout` largos (arriba).
- **Docker**: `MUDESK_WORKERS` (en `compose.yaml` bajo `environment:`, o `docker run -e MUDESK_WORKERS=280`) y la memoria y las CPU del contenedor (en `compose.yaml`, descomenta `deploy: resources: limits:`; o `docker run --memory 4g --cpus 2`), y después `docker compose up -d`.
- **Kubernetes (el chart de Helm)**: `workers` y `resources` en los valores (`--set workers=280 --set resources.limits.memory=4Gi`), y después `helm upgrade`.
- **Proxmox VE (LXC)**: `create-lxc.sh --memory 4096 --cores 2` para un contenedor nuevo; para uno ya creado, en el anfitrión: `pct set <ID> --memory 4096 --cores 2` (en el momento, sin reiniciarlo).
- **Hosting compartido**: los procesos PHP o entry processes del plan, y su memoria, son el techo (`tools/check.php` los mide); más sesiones necesitan un plan mayor.
- **Servicio WebSocket**: 240 conexiones a la vez, 60 de ellas desde una misma dirección de cliente; pasadas esas, las sesiones van por HTTP y necesitan los procesos de arriba. Sus sesiones reciben el `memory_limit` del servicio: arráncalo como `php -d memory_limit=512M tools/ws-server.php` para darles más.

### Procesos y límites

- Cada intento de inicio de sesión tarda 1 s, sea cual sea la respuesta (así su tiempo no dice nada de la cuenta; uno que entra, algo más en un servidor lento, donde abrir la clave de datos del usuario tarda más): muchos a la vez ocupan otros tantos procesos PHP ese segundo. Los bloqueos por dirección, por usuario y en total cuentan los fallos; una dirección bloqueada se rechaza al momento, sin escribir nada más (su bloqueo no dice nada de los usuarios).
- Cada sesión abierta ocupa **un proceso PHP** todo el tiempo (un hijo de php-fpm o un proceso de Apache). Un equipo remoto que no responde lo retiene hasta 8 s por cada paso del saludo.
- Mientras se escribe o se mueve el ratón, una sesión añade hasta **cuatro peticiones cortas a la vez** (ninguna con el servicio WebSocket, más arriba); mientras un programa remoto graba del micrófono, unas diez por segundo (trozos de 100 ms, dentro de esas cuatro); mientras un programa de allí usa la cámara o la carpeta de este equipo, o la sesión se graba en el navegador, hasta dos más a la vez por un carril propio.
- Cada invitado que ve una sesión compartida ocupa **un proceso PHP más** mientras mira (como mucho 5 por sesión).
- `memory_limit`: un mensaje de un canal mayor que una sexta parte de lo que el límite deja libre (copiar una captura 4K son 33 MB) se deja fuera, sin pegarse, y queda en el registro de errores, en vez de terminar la sesión con un error fatal de PHP; con 256M o más pasan esas copias.
- Las sesiones grabadas escriben en `data/recordings/` mientras se graban (unas decenas de MB por hora en un escritorio de oficina, de 35 a 110 MB la empezada en la sesión; mucho más con vídeo), guardadas hasta que se eliminan a mano (o los días de los ajustes): deja sitio en el disco. La empezada en la sesión ocupa además la subida del usuario mientras se graba (de 10 a 30 KB/s en un escritorio de oficina, hasta 0,5–6 Mbit/s con vídeo).
- Pon *Administración → Ajustes → Sesiones RDP a la vez (todos los usuarios)* (`max_sessions_total`, 20 por defecto, 0 = sin límite) por debajo de los procesos PHP disponibles: con PHP-FPM, `pm.max_children` ≥ `max_sessions_total` + los que necesita la web (2 como mínimo, como cuenta `tools/check.php`; unos 10 para ir holgado). Al llegar al límite, una sesión nueva recibe al momento «servidor ocupado», sin conectar. `tools/check.php` mide los procesos a la vez: `max_sessions_total` como mucho N − 2.
- Si es posible, sirve `rdp.php` desde un pool de PHP-FPM propio (su propio socket en el bloque `location = /rdp.php` de arriba), así las sesiones largas nunca ocupan los procesos de la web.
- El servidor integrado de PHP (`php -S`) atiende de una en una: una sesión bloquea todo. Solo para pruebas; `PHP_CLI_SERVER_WORKERS` (varios procesos) no funciona en Windows.
- En hostings con límite de "entry processes" (por ejemplo CloudLinux) hace falta al menos `5 × sesiones simultáneas` (`tools/check.php` encuentra ese límite por las peticiones rechazadas). Una petición del teclado o del ratón que el hosting rechaza (429, 503, 508) se envía de nuevo, hasta 5 veces en unos 1,5 s, una cada vez mientras tanto: no se pierde nada de lo escrito, pero la sesión va a tirones mientras dura (antes, lo que llevaba se perdía, y una tecla soltada en ella se quedaba pulsada en el equipo remoto).
- Los usuarios se guardan en un archivo (`data/users.json`), que se lee entero al entrar y en cada petición: hasta unos mil usuarios tarda pocos milisegundos (7 ms con 1.000 en un ordenador de sobremesa); 49 ms con 5.000 y 243 ms con 20.000.
- Ancho de banda del servidor web al navegador: desde pocos KB/s (trabajo de oficina) hasta 2–3 MB/s (cambios a pantalla completa a 16 bits). Del navegador al servidor web, la entrada son pocos KB/s; el micrófono añade unos 176 KB/s mientras un programa remoto graba, y la cámara unos 150–185 KB/s con las cámaras simuladas de las pruebas (unos 0,5–1,5 MB/s una real, según una estimación) mientras un programa la usa.

## Supervisión

`public/health.php` responde `{"status":"ok"}` (200) mientras la pasarela funciona, o 503 con lo que no: `{"status":"error","problem":...}` (`data`: no se puede escribir en su carpeta de datos; `store`: no se pueden leer sus usuarios o ajustes), o, como todas las entradas, `{"error":"data_exposed"}` (su carpeta de datos se puede leer desde la web) y `{"error":"restoring"}` (una copia de seguridad restaurándose). No dice nada más de la instalación. Se aplican los clientes permitidos: pon la dirección del monitor cuando son una lista. *Administración → Estado* muestra las sesiones, los procesos, la memoria, el disco y los problemas recientes, y el uso de los últimos 30 días.

## Nombres de equipo (DNS)

Los nombres de los equipos remotos se resuelven con el resolutor del sistema, así siguen funcionando el fichero `hosts`, los dominios de búsqueda y la resolución de nombres de Windows; MuDeskClient no tiene resolutor propio. Un servidor DNS que no responde retiene el proceso PHP hasta que el resolutor del sistema se rinde (su propio tiempo límite). La sesión se admite antes de resolver el nombre, así que `max_sessions_total` también acota cuántos procesos pueden quedarse esperando al DNS a la vez. Un nombre que no resuelve se rechaza como destino no permitido.

## Hostings restringidos

- En Windows la entrada de la sesión va por un puerto TCP solo de este equipo (127.0.0.1, con un secreto de la sesión). Con `stream_socket_server` desactivada, la pasarela usa sola un fichero de cola revisado cada 20 ms (cada tecla puede esperar hasta 20 ms); `'ipc' => 'file'` en `config.php` lo fuerza.
- Puerto 3389 saliente bloqueado: pídelo al proveedor o usa otro puerto en el equipo remoto (se configura en cada conexión).

## Equipos remotos

- Abre el puerto RDP solo a la IP del servidor web (por ejemplo en el cortafuegos: NAT 3389 → equipo, origen = IP del hosting).
- Mantén NLA activado (viene así en las versiones actuales de Windows).
- Mantén actualizados los equipos remotos. En uno que usan varias personas a la vez, sin las actualizaciones de enero y abril de 2022 (CVE-2022-21893, CVE-2022-24533), otro usuario conectado allí podía quedarse con lo que redirige una sesión (portapapeles, unidades): ningún cliente puede impedirlo, así que desactiva las redirecciones en los equipos que no mantengas al día o en los que no confíes. Las demás vulnerabilidades conocidas de los clientes RDP, revisadas: [VULNERABILIDADES](VULNERABILIDADES.md).
- **Puerta de enlace de Escritorio remoto** (probada contra la propia de Windows Server 2022 con cada uno de sus tres transportes, a través de una puerta de enlace de código abierto, y su transporte RPC también contra una escrita según las especificaciones, por la que pasó también un cliente independiente): MuDeskClient le habla por HTTPS con el transporte HTTP sobre WebSocket (Windows Server 2016 o posterior) o con el transporte antiguo de dos conexiones HTTPS (2012, 2012 R2) o, si no tiene ninguno de los dos (2008, 2008 R2), con RPC sobre HTTP: dos conexiones HTTPS a `/rpc/rpcproxy.dll`, una que lleva lo que envía este servidor en el cuerpo de una petición de 1 GiB (que se rehace al enviar la mitad) y otra que trae lo que envía la puerta de enlace (que se rehace cuando ella lo pide), con entrada solo por NTLM (sin Kerberos en ese transporte). Un error HTTP que esa puerta de enlace conteste en la conexión por la que envía este servidor no se lee: se ve como una puerta de enlace que no contesta (a los 8 s). En los otros dos transportes entra con Kerberos si este servidor puede pedir un ticket para ella (abajo); si no, con NTLM, con el esquema NTLM o con Negotiate si es el único que ofrece (una puerta de enlace que solo acepte tarjetas inteligentes no sirve); con la *Protección extendida* activada, va el enlace de canal de su certificado. La lista de destinos RDP permitidos se aplica a la puerta de enlace: los equipos que hay detrás son los que sus directivas de autorización de recursos (RD RAP) permiten al usuario, con el nombre con que ella los conoce. El proceso de la sesión pasa los bytes del túnel por un puerto TCP de 127.0.0.1 que abre él mismo, así que `stream_socket_server` tiene que estar permitida.
- **Kerberos** (Active Directory; probado contra un dominio de Windows Server 2022, un reino de prueba y servidores simulados): para NLA, la puerta de enlace de Escritorio remoto y la entrada con cuenta de Windows, antes que NTLM cuando el dominio se conoce por su nombre DNS. Este servidor necesita el puerto TCP 88 hacia un controlador de dominio, que encuentra en el DNS (registros SRV `_kerberos._tcp`; solo el puerto 88, nunca este equipo ni direcciones de enlace local) o que se indica en `config.php` (`kerberos_kdc`); si no, se usa NTLM. El inicio de sesión con el KDC tiene 8 segundos en total (un KDC que no contesta: NTLM después); las consultas al DNS usan el resolvedor del sistema, con sus propias esperas (`'kerberos_dns' => false` deja solo `kerberos_kdc`). Claves AES, y RC4 solo si se permite en *Administración → Ajustes* (desactivado de serie; un dominio Windows Server 2003 solo tiene RC4; ver *Known limits* en [SECURITY](../../SECURITY.md)). Un usuario@dominio cuyo sufijo no es el de su dominio (un sufijo UPN alternativo) se busca en el KDC del dominio de la conexión o del nombre DNS del equipo; una cuenta local (sin dominio, `.` o el nombre del propio equipo) nunca usa Kerberos. Si el servidor rechaza el ticket, o está en otro dominio del bosque, se entra con NTLM, y también en un equipo al que se llega por su dirección (como aquel al que un agente de conexión envía la sesión sin puerta de enlace). `'kerberos' => false` lo desactiva.
- **Agente de conexión** (probado contra el de Windows Server 2022 con un host de sesión, y sus tokens de enrutamiento contra Windows 11; aún no enviando la sesión a otro host de sesión): el equipo de la conexión es el agente, con la `loadbalanceinfo` de la granja; los servidores a los que envía la sesión también tienen que ser destinos permitidos (por su dirección o por su nombre), salvo que la conexión pase por una puerta de enlace; si este servidor no llega a una de las direcciones que nombra, se prueba la siguiente que nombró. Sin puerta de enlace se llega a ellos primero por su dirección: NLA usa allí Kerberos si el agente da también el nombre del servidor, y si no, NTLM. Una contraseña que el agente cifró para su servidor se le entrega allí con RDSTLS.
- **Windows XP y Server 2003** (y los Windows posteriores configurados con la *capa de seguridad RDP*: probado en Server 2008 y 2008 R2, donde un equipo visto con TLS se rechaza al principio, porque podría ser alguien en medio bajándola; olvida su certificado en la conexión si se cambió a propósito): sin TLS (el de Server 2003 no comparte ningún cifrado con OpenSSL 3; los posteriores están configurados para no usarlo), MuDeskClient usa con ellos la *seguridad RDP estándar*, solo si la seguridad de la conexión es *Automática* (sus riesgos, en [SECURITY](../../SECURITY.md)). El nivel de cifrado es el del equipo: en *Bajo* solo se cifra lo que envía MuDeskClient, así que mejor *Alto* (directiva *Establecer el nivel de cifrado de conexión de cliente*); un equipo que no cifra nada se rechaza.
- **Windows 7 y Server 2008 R2** sin su actualización de TLS 1.2 solo hablan TLS 1.0, y tal como salieron sin la renegociación segura que exige OpenSSL 3: MuDeskClient hace el TLS 1.0 él mismo para ellos (solo con un equipo nunca visto con TLS 1.2). Sin la actualización de CredSSP de mayo de 2018 su NLA se rechaza salvo con `credssp_unpatched`: actualízalos donde puedas.
- Para la impresora **MuDeskPDF**, el Windows remoto necesita el controlador "Microsoft Print To PDF" (Windows 10 / Server 2016 o posterior) y que la directiva permita redirigir impresoras.
- Para varias pantallas en Windows de escritorio, el RDP multimonitor debe estar permitido (ediciones Pro/Enterprise; lo está por defecto).
- Para una conexión **RemoteApp** (un programa suelto), el equipo remoto tiene que permitir ese programa: en Windows Server, publícalo como programa RemoteApp de Servicios de Escritorio remoto (su alias va en la conexión tal cual, `Alias`: MuDesk le añade las `||` delante); en Windows 10/11 Pro, regístralo en `HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications`, o permite cualquier programa a quien ya puede entrar por Escritorio remoto con el valor `fDisabledAllowList` (DWORD) = 1 en `TSAppAllowList`. Windows Home no acepta conexiones de Escritorio remoto.
- **Sonido en Windows Server**: su servicio *Audio de Windows* viene apagado, así que la sesión no tiene dispositivo de audio y no envía sonido, tenga lo que tenga el navegador (la traza de la pasarela muestra entonces el canal `rdpsnd` abierto y nada por él). En el servidor, como administrador: `Set-Service Audiosrv -StartupType Automatic; Start-Service Audiosrv` (y lo mismo con `AudioEndpointBuilder` si está parado); la directiva *Permitir la redirección de reproducción de audio y vídeo* (Host de sesión de Escritorio remoto → Redirección de dispositivos y recursos) no debe estar deshabilitada. Después cierra la sesión remota (no basta con desconectar) y vuelve a conectar con **Audio del equipo remoto** marcado. En Windows Server 2008 R2 y 2003 el sonido está además desactivado en los ajustes de la propia conexión (*Reproducción de audio y vídeo*; *Asignación de audio* en 2003): permítelo allí, o pon `fDisableCam` a 0 en `HKLM\SYSTEM\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp`, para las conexiones hechas después.
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
  3. Abre **Establecer prioridades en el modo gráfico H.264/AVC 444 para las conexiones de Escritorio remoto**, elige *Habilitada* y pulsa Aceptar. En la misma página, **Configurar codificación de hardware H.264/AVC para las conexiones de Escritorio remoto** en *Habilitada* deja que lo codifique la tarjeta gráfica del servidor, cuando puede.
  4. Ejecuta `gpupdate /force`, cierra la sesión remota (no basta con desconectar) y vuelve a conectar.

  Sin el editor de directivas, lo mismo como valor del registro: `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v AVC444ModePreferred /t REG_DWORD /d 1 /f`. MuDeskClient vuelve a unir las dos imágenes de AVC444 (v1 y v2) en color completo donde el navegador da los planos de una imagen decodificada (Chrome y Edge lo hacen; Firefox aún sin probar); donde no, muestra la primera (4:2:0), como antes. Windows envía la segunda imagen (el color que la primera deja fuera) solo cuando lo cree necesario: en el equipo Windows 11 de pruebas, con la directiva activada y codificando su tarjeta gráfica, solo envió la primera. Los gráficos de la conexión deben estar en *Automático* o *Canal gráfico y H.264*.
