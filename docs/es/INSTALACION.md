# Manual de instalación

[English](../INSTALL.md)

Este manual explica cómo poner MuDeskClient en marcha **sin conocimientos de informática**, paso a paso, en un hosting o en un equipo de tu red local con Apache y PHP. Al final hay una guía de problemas frecuentes.

MuDeskClient es de **copiar y pegar**: no hay que compilar nada, no usa base de datos ni instala programas. Se copia la carpeta en el servidor y se abre en el navegador.

## Resumen para quien ya sabe

1. PHP 8.1–8.5 con `openssl` y `session`, en Apache (o LiteSpeed) con `AllowOverride All` y HTTPS.
2. Copia la carpeta entera en el sitio (por ejemplo, `/mudesk/`) y dale a PHP permiso de escritura en `data/`.
3. Abre `https://tu-sitio/mudesk/`, escribe el código de `data/setup-code.php` y crea el administrador.
4. Comprueba que `https://tu-sitio/mudesk/data/web-check.txt` responde 403 o 404.
5. El servidor web tiene que poder conectar con el puerto RDP de cada equipo remoto (3389 por defecto): cortafuegos de salida del hosting, del router y de Windows.

Más detalles técnicos (Nginx, IIS, PHP-FPM, capacidad): [HOSTING](HOSTING.md).

## 1. Qué necesitas

**Un sitio donde instalarlo**, una de estas tres opciones:

| Opción | Para quién | Apartado |
|---|---|---|
| **A. Un hosting** con PHP (cPanel, Plesk, DirectAdmin…) | Para entrar desde cualquier sitio de Internet | [Apartado 3](#3-opción-a-en-un-hosting) |
| **B. Un PC con Windows** de tu red local, con XAMPP | Para usarlo dentro de la oficina o de casa, sin hosting | [Apartado 4](#4-opción-b-en-un-pc-con-windows-de-la-red-local-xampp) |
| **C. Un equipo con Linux** (Ubuntu o Debian) con Apache | Para un servidor propio de la red local | [Apartado 5](#5-opción-c-en-un-equipo-con-linux-ubuntu-o-debian) |

**Y los equipos a los que conectarte**: ordenadores con Windows 10 u 11 **Pro**, Enterprise o Education, o Windows Server, con el Escritorio remoto activado (ver el [apartado 6](#6-preparar-los-equipos-windows-a-los-que-te-vas-a-conectar)). Windows **Home** no admite conexiones de Escritorio remoto.

Requisitos del servidor:

| Elemento | Necesario |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 u 8.5 de 64 bits |
| Extensiones de PHP | `openssl` y `session` (vienen activadas en casi todos los PHP) |
| Servidor web | Apache o LiteSpeed (con ellos todo funciona solo); Nginx e IIS necesitan unos ajustes: ver [HOSTING](HOSTING.md) |
| HTTPS | Obligatorio. En un hosting suele ser gratis (Let's Encrypt); en la red local sirve un certificado propio |
| Conexión de salida | Desde el servidor web hasta el puerto RDP de cada equipo remoto |

## 2. Descargar MuDeskClient

1. Descarga el ZIP de MuDeskClient desde [miguelenred.es/mudeskclient](https://miguelenred.es/mudeskclient) o desde su página de GitHub (botón **Code → Download ZIP**).
2. Descomprímelo. Obtendrás una carpeta llamada `MuDeskClient` (o `mudeskclient-main` si viene de GitHub).
3. Cámbiale el nombre a **`mudesk`**. Es el nombre que se usa en este manual; puedes usar otro, y entonces cambia `mudesk` por tu nombre en todas las direcciones.

Dentro verás las carpetas `public`, `src`, `data`, `docs`, `tests`, `tools` y varios archivos. **Hay que copiar la carpeta entera**: solo `public` se ve desde Internet, pero las demás son necesarias.

**Con Composer** (para quien lo use; no hace falta): `composer create-project miguelenred/mudeskclient mudesk` descarga la última versión en la carpeta `mudesk`, lista para usarse igual. MuDeskClient no tiene dependencias: Composer solo comprueba la versión de PHP (8.1 a 8.5) y la extensión OpenSSL, y añade una carpeta `vendor` que la aplicación no usa (las reglas incluidas la dejan fuera de alcance, como las demás carpetas privadas). Para actualizar, sigue el apartado 9 con los archivos de la versión nueva.

## 3. Opción A: en un hosting

### 3.1 Comprobar el hosting

En el panel de control del hosting:

1. **Versión de PHP**: busca *Seleccionar versión de PHP*, *PHP Selector* o *Configuración de PHP* y elige **8.1 a 8.5** (8.3 recomendado).
2. **HTTPS**: busca *SSL/TLS*, *Let's Encrypt* o *AutoSSL* y activa el certificado gratuito para tu dominio. Al abrir `https://tu-dominio` debe verse el candado.

### 3.2 Subir los archivos

Con el **Administrador de archivos** del panel:

1. Entra en la carpeta pública del sitio (normalmente `public_html`, `httpdocs` o `www`).
2. Sube el ZIP de MuDeskClient y usa la opción **Extraer**. Cambia el nombre de la carpeta resultante a `mudesk`.

O con un programa de FTP (por ejemplo FileZilla): copia la carpeta `mudesk` entera dentro de `public_html`.

Al terminar debe quedar así: `public_html/mudesk/public`, `public_html/mudesk/src`, `public_html/mudesk/data`…

### 3.3 Permisos de la carpeta `data`

PHP necesita **escribir** en `mudesk/data`. En la mayoría de hostings ya puede. Si al abrir la aplicación ves «No se puede guardar en la carpeta de datos», en el Administrador de archivos selecciona `data`, pulsa **Permisos** y pon `755` (o `775` si con `755` sigue fallando). Nunca `777`.

### 3.4 Primer arranque

1. Abre **`https://tu-dominio/mudesk/`** en el navegador.
2. La pantalla pide un **código de instalación**. Está en el archivo `mudesk/data/setup-code.php`, que se crea en ese momento. Ábrelo con el Administrador de archivos (**Ver** o **Editar**, no lo abras en el navegador: no mostraría nada). La segunda línea es el código, con este aspecto: `1A2B3-C4D5E-F6A7B-8C9D0`. Cópialo.
3. Escribe el código, el nombre del administrador y una contraseña de **al menos 12 caracteres** (con 4 distintos como mínimo, que no sea una contraseña común ni contenga el nombre). El código solo sirve una vez y el archivo se borra al terminar.

Este código existe para que nadie más pueda adueñarse de una instalación recién copiada antes que tú.

### 3.5 Comprobar que los datos están protegidos

Abre **`https://tu-dominio/mudesk/data/web-check.txt`**. Debe salir un error **403 (Prohibido)** o **404 (No encontrado)**. Si en cambio ves un texto que empieza por «MuDeskClient data folder», tu hosting no aplica los archivos `.htaccess` y la carpeta de datos se puede descargar. La aplicación también lo avisa en rojo al administrador. En ese caso, pide al proveedor que active `AllowOverride` para tu sitio, o usa la distribución B de [HOSTING](HOSTING.md) (solo `public` dentro de la carpeta web).

### 3.6 Comprobar que el hosting llega a tus equipos

Algunos hostings **no dejan salir conexiones** a otros puertos, entre ellos el de Escritorio remoto. Para comprobarlo antes de nada:

1. Abre `mudesk/tools/check.php` con el editor del Administrador de archivos y cambia `CAMBIAR` en la línea `const TOKEN = 'CAMBIAR';` por una palabra secreta tuya.
2. Copia ese archivo a la carpeta `mudesk/public/`.
3. Abre `https://tu-dominio/mudesk/public/check.php`, escribe tu palabra secreta en *Token* y, en *RDP saliente*, la dirección de tu equipo remoto y **su puerto** (3389 si no se cambió; si tu equipo usa otro, escribe ese). Pulsa el botón de la prueba.
4. Si dice que no puede conectar, revisa el [apartado 7, cortafuegos](#7-cortafuegos-que-hay-que-dejar-pasar).
5. **Borra `public/check.php` al terminar.**

## 4. Opción B: en un PC con Windows de la red local (XAMPP)

XAMPP es un paquete gratuito que instala Apache y PHP en Windows con un solo instalador.

### 4.1 Instalar XAMPP

1. Descarga XAMPP para Windows desde [apachefriends.org](https://www.apachefriends.org/) (una versión con PHP 8.1 a 8.5).
2. Instálalo con las opciones por defecto (carpeta `C:\xampp`). Solo hacen falta **Apache** y **PHP**.
3. Abre el **XAMPP Control Panel** y pulsa **Start** junto a **Apache**.
4. Si Windows pregunta por el cortafuegos, permite el acceso en **redes privadas**.
5. Para que Apache arranque solo con Windows, en el Control Panel ejecutado como administrador marca la casilla **Service** de Apache.

### 4.2 Copiar MuDeskClient

Copia la carpeta `mudesk` dentro de **`C:\xampp\htdocs`**. Debe quedar `C:\xampp\htdocs\mudesk\public`, `C:\xampp\htdocs\mudesk\data`…

XAMPP ya trae lo que MuDeskClient necesita: `.htaccess` activados, `mod_rewrite` y HTTPS con un certificado propio.

### 4.3 Primer arranque

1. En ese mismo PC, abre **`https://localhost/mudesk/`**.
2. El navegador avisará de que el certificado no es de confianza (es el certificado propio de XAMPP). Pulsa **Avanzado** (o *Configuración avanzada*) y después **Continuar** o **Acceder** al sitio. Solo hay que hacerlo una vez en cada navegador.
3. Sigue los pasos del [apartado 3.4](#34-primer-arranque): el código de instalación está en `C:\xampp\htdocs\mudesk\data\setup-code.php`; ábrelo con el Bloc de notas.

Desde **otros ordenadores de la red**, abre `https://IP-DEL-PC/mudesk/` (la IP del PC con XAMPP se ve con el comando `ipconfig`, por ejemplo `192.168.1.20`) y acepta también el aviso del certificado.

Si `data` no tiene permiso de escritura (aparece «No se puede guardar en la carpeta de datos»), ver *Permisos de escritura* en [HOSTING](HOSTING.md): con Apache como servicio, la cuenta del servicio necesita el permiso *Modificar* sobre `data`.

## 5. Opción C: en un equipo con Linux (Ubuntu o Debian)

Abre una terminal y escribe estos comandos (pedirá tu contraseña).

### 5.1 Instalar Apache y PHP

```bash
sudo apt update
sudo apt install apache2 php libapache2-mod-php unzip
sudo a2enmod rewrite headers ssl
sudo a2ensite default-ssl
```

`php -v` muestra la versión de PHP: Ubuntu 22.04 trae 8.1, Ubuntu 24.04 trae 8.3 y Debian 12 trae 8.2; todas sirven.

### 5.2 Activar los archivos `.htaccess`

Ubuntu y Debian los traen desactivados. Abre el archivo de configuración:

```bash
sudo nano /etc/apache2/apache2.conf
```

Busca el bloque `<Directory /var/www/>` y cambia `AllowOverride None` por **`AllowOverride All`**. Guarda (Ctrl+O, Intro) y sal (Ctrl+X). Después:

```bash
sudo systemctl restart apache2
```

### 5.3 Copiar MuDeskClient

Con el ZIP en tu carpeta personal:

```bash
sudo unzip MuDeskClient.zip -d /var/www/html/
sudo mv /var/www/html/MuDeskClient /var/www/html/mudesk
sudo chown -R www-data:www-data /var/www/html/mudesk/data
```

Si el cortafuegos `ufw` está activado, deja entrar HTTPS: `sudo ufw allow 443/tcp`.

### 5.4 Primer arranque

Abre `https://IP-DEL-EQUIPO/mudesk/` (la IP se ve con `hostname -I`), acepta el aviso del certificado propio y sigue el [apartado 3.4](#34-primer-arranque). El código está en `/var/www/html/mudesk/data/setup-code.php`:

```bash
sudo cat /var/www/html/mudesk/data/setup-code.php
```

## 6. Preparar los equipos Windows a los que te vas a conectar

En cada ordenador al que quieras conectarte:

1. **Activa el Escritorio remoto**: *Configuración → Sistema → Escritorio remoto → Activar*. Windows abre a la vez su cortafuegos para el Escritorio remoto.
2. **La cuenta de Windows debe tener contraseña**: el Escritorio remoto no admite cuentas sin ella.
3. **Anota la dirección** del equipo: su IP (comando `ipconfig`, por ejemplo `192.168.1.30`) o su nombre.
4. **Anota el puerto**: es **3389** salvo que alguien lo haya cambiado. Si se cambió, usa el puerto que tenga ese equipo en la conexión de MuDeskClient y en la prueba de `check.php`.
5. **No lo apagues ni lo suspendas**: un equipo apagado o en suspensión no responde.
6. **Solo si vas a usar el micrófono** (la casilla *Micrófono de este equipo en el remoto*, desmarcada de serie): Windows 10 y 11 lo permiten tal como vienen; en Windows Server arranca su servicio *Audio de Windows*, y si una directiva lo prohíbe, permítelo. Paso a paso en [HOSTING › Equipos remotos](HOSTING.md#equipos-remotos). El navegador solo da el micrófono a páginas abiertas con `https://`.

Para conectarte desde un **hosting de Internet** a un equipo de tu oficina o de tu casa, el router de ese sitio tiene que dejar pasar la conexión: ver el apartado siguiente.

## 7. Cortafuegos que hay que dejar pasar

Entre el servidor de MuDeskClient y cada equipo remoto puede haber varios cortafuegos. **Todos** tienen que dejar pasar la conexión al puerto RDP (3389, o el que use ese equipo):

| Cortafuegos | Qué hacer |
|---|---|
| **El de Windows**, en el equipo remoto | Al activar el Escritorio remoto se abre solo. Si usas otro antivirus o cortafuegos, permite el Escritorio remoto en él |
| **El del router** de la oficina o de casa (si MuDeskClient está en un hosting de Internet) | Crea una regla de *reenvío de puertos* (NAT) del puerto 3389 hacia la IP del equipo. Por seguridad, permite solo la **IP del hosting** como origen; nunca dejes el puerto abierto a todo Internet |
| **El de la red de la empresa** | Pide al responsable de la red que permita al servidor de MuDeskClient llegar al puerto RDP de los equipos |
| **El de salida del hosting** | Algunos proveedores bloquean las conexiones salientes a otros puertos. Compruébalo con `check.php` ([apartado 3.6](#36-comprobar-que-el-hosting-llega-a-tus-equipos)) y, si falla, pide al proveedor que abra la salida a ese puerto |
| **El del propio servidor** de MuDeskClient (red local) | Tiene que dejar **entrar** HTTPS (puerto 443) desde los ordenadores que lo usan: XAMPP lo pregunta al arrancar Apache; en Linux, `sudo ufw allow 443/tcp` |

Si una conexión falla con «No se puede conectar con el equipo remoto», casi siempre es uno de estos cortafuegos, la dirección o el puerto.

## 8. Primera conexión

1. Entra en MuDeskClient con el administrador.
2. **Conexiones → Nueva conexión**: pon un nombre, la dirección del equipo, su **puerto** y el usuario de Windows. Guarda.
3. Pulsa **Conectar**, escribe la contraseña de Windows y listo.

Para más usuarios: *Administración → Usuarios → Nuevo usuario*. La casilla **Permisos de administrador** convierte al usuario en administrador; puede haber varios. La guía completa está en [USO](USO.md).

## 9. Actualizar a una versión nueva

1. Haz una copia de seguridad de la carpeta `data` (ver abajo).
2. Copia los archivos de la versión nueva encima de los antiguos, **sin borrar la carpeta `data`** ni tu `config.php` si lo tienes.
3. Las páginas abiertas avisan de que hay una versión nueva y ofrecen recargar.

## 10. Copias de seguridad

Todo lo tuyo está en la carpeta **`data`**: usuarios, conexiones, auditoría, archivos compartidos y la clave del servidor (`data/secret.php`). Cópiala entera, con regularidad, a un lugar seguro. **Sin `secret.php`** los códigos de las aplicaciones de autenticación dejan de servir y la auditoría no se puede verificar. Guarda esa copia como algo confidencial.

## 11. Problemas frecuentes

| Lo que ves | Qué hacer |
|---|---|
| «No se puede guardar en la carpeta de datos» | Da permiso de escritura a `data` ([3.3](#33-permisos-de-la-carpeta-data), o [HOSTING](HOSTING.md) en Windows) |
| «Hace falta una conexión segura» | Abre la dirección con `https://`. En un hosting, activa el certificado gratuito; en la red local, XAMPP y Linux ya lo traen |
| «Versión de PHP no admitida» | Elige PHP 8.1 a 8.5 en el panel del hosting |
| Aviso rojo: la carpeta de datos se puede descargar | Tu servidor no aplica `.htaccess`: [3.5](#35-comprobar-que-los-datos-están-protegidos) |
| «Código de instalación incorrecto» | Copia la segunda línea de `data/setup-code.php` entera, con los guiones |
| «No se puede conectar con el equipo remoto» | Equipo encendido, dirección, **puerto** y [cortafuegos](#7-cortafuegos-que-hay-que-dejar-pasar) |
| «Usuario o contraseña remotos incorrectos» | Usuario y contraseña de **Windows** del equipo remoto (no los de MuDeskClient). Para una cuenta local, escribe `.\usuario` |
| «El servidor está ocupado: hay demasiadas sesiones abiertas» | Se ha llegado al máximo de sesiones a la vez: inténtalo más tarde, o súbelo en *Administración → Ajustes* si el hosting lo permite ([HOSTING](HOSTING.md), *Capacidad*) |
| La sesión se corta cada minuto y medio y vuelve a conectar | Hay un proxy delante (por ejemplo Cloudflare) que corta las conexiones largas: usa una conexión directa |
| Página en blanco o error 500 | Mira el registro de errores de PHP del hosting (en XAMPP, `C:\xampp\php\logs\php_error_log`) |

Si nada de esto lo resuelve, el *Modo diagnóstico* de la ventana al conectar guarda una traza técnica de la sesión que el administrador puede descargar ([USO](USO.md)).
