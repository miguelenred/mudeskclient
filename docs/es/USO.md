# Guía de uso

[English](../USAGE.md)

## Primer arranque

La primera visita pide el **código de instalación** de un solo uso (en `data/setup-code.php` del servidor; impide que otro se adueñe de una instalación recién subida) y crea la cuenta de **administrador**. Las contraseñas necesitan al menos 12 caracteres y 72 bytes como mucho (72 letras sin acentos; las letras con acento o los símbolos ocupan más), al menos 4 caracteres distintos, y no pueden ser una contraseña común ni contener el nombre de usuario. El administrador da de alta al resto de usuarios con una contraseña temporal, que deben cambiar al entrar por primera vez.

El idioma (español o inglés) se cambia con el selector de la cabecera, arriba a la derecha; queda guardado en el perfil de cada usuario. Antes de entrar se elige en la pantalla de acceso. En *Perfil*, **Automático** sigue el idioma del navegador. Las páginas terminan con un enlace al sitio del proyecto.

## Conexiones

Cada usuario gestiona sus conexiones (*Conexiones → Nueva conexión*). Las marcadas **Del administrador** las ha creado el administrador para ti: te conectas con ellas y guardas tu propia contraseña y tus opciones, pero no puedes cambiarlas ni borrarlas.

**Importar .rdp** crea conexiones desde archivos de Conexión a Escritorio remoto: un archivo abre el formulario relleno para revisarlo y guardarlo; varios se guardan de una vez. Lo que MuDesk no usa se dice ahí (un archivo RemoteApp sin su programa: se abre el escritorio completo; una puerta de enlace de Escritorio remoto; un programa de inicio; la contraseña guardada, que solo puede leer el Windows que la guardó). Un archivo RemoteApp con su programa crea una conexión RemoteApp, y al exportarla se conserva el programa. Las conexiones importadas, como las nuevas, llevan la distribución de teclado española: revísala. El botón de descarga de cada conexión la exporta como **archivo .rdp** para Conexión a Escritorio remoto (sin su contraseña).

En el formulario, *Distribución de teclado* y los campos que la siguen en esta tabla (salvo los de RemoteApp) están en la sección plegada *Opciones avanzadas*.

| Campo | Notas |
|---|---|
| Nombre, grupo | Los grupos ordenan la lista |
| Equipo, puerto | IP o nombre; 3389 por defecto |
| Usuario remoto, dominio | En el usuario también vale `DOMINIO\usuario`, `EQUIPO\usuario`, `.\usuario` (cuenta local del equipo remoto) y `usuario@dominio` (UPN: se envía tal cual y el campo dominio no se usa) |
| Contraseña remota | Opcional y **no recomendable** (quien entre en la cuenta podrá conectar sin conocerla; el formulario lo avisa). Se guarda cifrada con una clave derivada de la contraseña del usuario; si se deja vacía, se pide al conectar. El administrador puede no permitirlo (*Ajustes*) |
| Distribución de teclado | Debe coincidir con el teclado **físico** del usuario (las teclas se envían como scancodes) |
| Calidad de color | Se recomienda 16 bits (la mitad de ancho de banda que 32) |
| Seguridad | Automática (NLA si está disponible), solo NLA, solo TLS |
| Al cambiar el tamaño de la ventana | Ajustar la resolución remota o escalar la imagen |
| Portapapeles, carpeta compartida, audio, micrófono, impresora MuDeskPDF | Valores por defecto de la ventana al conectar (el micrófono desmarcado salvo que se marque) |
| Teclado del móvil por portapapeles | Automático (activado en móviles y tabletas), Siempre o Nunca: ver [Móviles y tabletas](#móviles-y-tabletas) |
| Gráficos | **Automático (recomendado)**: con Windows 8.1 / Server 2012 R2 o posterior (la versión se ve al iniciar sesión con NLA) usa el canal gráfico con H.264 si el navegador lo decodifica, y con los demás servidores (xrdp, Windows antiguos, solo TLS) los mapas de bits con la calidad de color elegida. **Canal gráfico y H.264**: siempre el canal gráfico (fuerza 32 bits; menos tráfico y casi nada de CPU en el servidor web). **Solo mapas de bits**: nunca el canal gráfico. Ver [RENDIMIENTO](RENDIMIENTO.md) |
| Programa (RemoteApp), argumentos | En la sección *RemoteApp: un programa suelto en vez del escritorio*, plegada en una conexión de escritorio y desplegada en una con programa (pulsa su título para plegarla o desplegarla). Solo ese programa, en sus propias ventanas sobre la página (ver *RemoteApp*); vacío: el escritorio completo |
| Preguntar las opciones al conectar | Muestra la ventana de opciones cada vez |
| Efectos visuales | Fondo, temas, suavizado de fuentes, animaciones, contenido al arrastrar |
| Certificado del servidor | Se guarda en la primera conexión; si cambia, la ventana de la sesión avisa y ofrece **Confiar y conectar**; «Olvidar certificado» lo borra |

**Conectar** abre la sesión en una pestaña nueva, así se pueden tener varias sesiones abiertas. Si el servidor ya tiene tantas sesiones como el administrador permite entre todos los usuarios, la ventana de sesión dice que el servidor está ocupado: inténtalo más tarde.

## Ventana al conectar

Antes de cada sesión (salvo que se haya marcado «recordar») se elige: audio remoto, micrófono de este equipo en el remoto, portapapeles, teclado del móvil por portapapeles, carpeta compartida, impresora MuDeskPDF, gráficos (no en una RemoteApp, que dice qué programa abre), calidad de color y **usar todas mis pantallas** (Chrome/Edge: solo con varias pantallas; Firefox y Safari: se busca la otra, y viene marcada si ya se encontró; no en móviles ni tabletas; su **ⓘ** dice qué pide primero este navegador: el permiso para administrar las ventanas en todas las pantallas en Chrome y Edge, las ventanas emergentes permitidas en Firefox y Safari). Las conexiones sin contraseña guardada piden aquí las credenciales, con **Guardar contraseña (no recomendable)**: solo se guarda cuando la conexión funciona. Una contraseña incorrecta las vuelve a pedir. En *Opciones avanzadas*, **Modo diagnóstico** guarda una traza técnica de esa sesión para el administrador (ver Administración). La X de arriba a la derecha cierra la ventana; si el navegador no deja que la página la cierre, vuelve a las conexiones (también el **Cerrar** de la ventana que dice que la sesión se ha desconectado).

## RemoteApp: un programa suelto

Con **Programa (RemoteApp)** relleno (su ruta en el equipo remoto, como `C:\Windows\System32\notepad.exe`, o el nombre con el que se publicó, como `Excel`: MuDesk le añade las `||` que Windows espera delante), la conexión muestra solo ese programa: sus ventanas, cada una donde está, sobre la página, sin el escritorio remoto. Se mueven, cambian de tamaño y se cierran con sus propias barras de título, y el título de la pestaña dice cuál está activa.
- Una barra de tareas abajo en la página tiene un botón por ventana: pulsa uno para traer su ventana al frente (también si está minimizada) o pulsa el de la activa para minimizarla. Las ventanas maximizadas la dejan a la vista.
- El botón **Ventanas** de la barra de la sesión también las enumera: pulsa una para traerla al frente, o minimízala, maximízala o ciérrala.
- El equipo remoto tiene que permitir el programa: Windows Server con Servicios de Escritorio remoto publica programas RemoteApp; en Windows 10/11 Pro se permite en su registro (ver [HOSTING](HOSTING.md#equipos-remotos)). Si no, la sesión lo dice ("no permite este programa como RemoteApp"); también dice si no encuentra el programa o no ha podido abrirlo, si la sesión remota está bloqueada y si el equipo no admite RemoteApp (conéctate sin programa).
- Al cerrar el programa (su última ventana), la sesión termina unos 3 s después y lo dice; **Volver a conectar** vuelve a abrir el programa. Windows mantiene la sesión desconectada, como con Conexión a Escritorio remoto, hasta que sus límites de tiempo la cierran. Si el programa seguía abierto allí, **Volver a conectar** recupera sus ventanas sin abrir otro.
- Hasta que aparece la primera ventana del programa, un indicador girando en el centro de la página (y la barra de tareas) dice que se está abriendo: algunos programas tardan. A los 15 s dice que está tardando más de lo normal. Si mientras tanto el servidor cierra la sesión (lo hace a los 12 s más o menos sin programa, también mientras uno arranca), la página se vuelve a conectar sola y sigue esperando; a los 90 s sin el programa lo dice, con **Volver a conectar**.
- Con varias pantallas (ver *Varias pantallas*), cada ventana del programa se ve en la pantalla donde está; la pantalla de la sesión es la principal remota, así que el programa se abre allí, junto a su barra de tareas.
- Esta versión usa mapas de bits (no el canal gráfico ni H.264), y el escritorio remoto sigue siempre a la ventana (la opción *Al cambiar el tamaño de la ventana* no se aplica).

## Barra de la sesión

La barra se oculta arriba; acerca el ratón o toca la pestañita para verla. Arrástrala por su asa (a la izquierda) o por su título para llevarla a un lado (o usa las flechas sobre el asa); se queda ahí para las siguientes sesiones en este navegador.

Junto al nombre, el **indicador de calidad** muestra cada segundo las imágenes por segundo que se ven, la latencia de la entrada hasta la pasarela y los datos recibidos (por ejemplo `24 fps · 35 ms · 120 KB/s`). El punto es verde por debajo de 100 ms, ámbar hasta 250 ms y rojo por encima; con la barra oculta, la pestañita toma ese color cuando no es verde.

| Botón | Acción |
|---|---|
| Pantalla completa | Pantalla completa con captura de teclado (Win, Alt+Tab… cuando el navegador lo permite). Con varias pantallas, ofrece usarlas todas (ver *Varias pantallas*) |
| Ctrl+Alt+Supr, tecla Windows | Envía esas teclas |
| Teclado en pantalla | En móviles y tabletas abre el teclado del dispositivo. Con ratón abre y cierra un teclado propio, que se usa con el ratón (ver *Teclado en pantalla*) |
| Teclas especiales | Esc, Tab, Ctrl/Alt/Mayús/Win que se quedan pulsadas, flechas, Inicio/Fin, RePág/AvPág, Supr, Retroceso, Intro, F1–F12 |
| Silenciar | Activa o desactiva el audio remoto |
| Captura de pantalla | **Pantalla completa** (todo el escritorio remoto; con varias pantallas, todas; con RemoteApp, las ventanas del programa en la pantalla de la sesión) o **Recortar una zona** (arrastra un recuadro sobre la imagen remota; Esc o el botón derecho cancelan). Solo la imagen remota, sin la barra, los paneles ni el puntero, a su tamaño real aunque la ventana la muestre más pequeña. Después: **Guardar** (PNG), **Copiar** (al portapapeles de este equipo) o **Al equipo remoto** (a su portapapeles, para pegarla allí con Ctrl+V; con el portapapeles compartido activado) |
| Portapapeles | Lo copiado en el remoto aparece aquí; escribe o pega texto y envíalo al portapapeles remoto. Debajo, el **historial** (como Win+V) |
| Archivos | Envía archivos al portapapeles remoto (después Ctrl+V en el Explorador) y descarga los copiados en el remoto (ZIP si son varios) |
| Pantallas | Lista las pantallas: **Abrir** añade la ventana de una pantalla y su monitor remoto, **Cerrar** los quita; en Firefox, **Buscar otras pantallas** |
| Ventanas | RemoteApp: las ventanas del programa (ver *RemoteApp*) |
| Desconectar | Deja la sesión de Windows en marcha (desconectada) |
| Ocultar barra | Esconde la barra (vuelve al llevar el ratón arriba, o con la pestañita) |

**Silenciar** aparece cuando la sesión tiene audio remoto, **Portapapeles** y **Archivos** cuando tiene el portapapeles compartido, **Pantallas** cuando usa varias pantallas (o, en Firefox y Safari en un ordenador, para buscarlas), **Ventanas** con RemoteApp.

**Micrófono.** Con **Micrófono de este equipo en el remoto** marcado, el equipo remoto oye el micrófono de este: Windows lo muestra allí como *Audio remoto*. El micrófono solo se abre mientras un programa del equipo remoto graba (una llamada, un dictado...); la primera vez el navegador pregunta si la página puede usarlo (permítelo: el icono junto a la dirección lo cambia después), y mientras está abierto el navegador muestra su propio aviso de grabación. Si el navegador no lo permite, no hay micrófono, la página no se abre con `https://` o el Windows remoto prohíbe grabar audio, la sesión sigue sin él (los tres primeros casos se dicen en la página; para el último ver [HOSTING](HOSTING.md#equipos-remotos)). La **ⓘ** junto a la casilla en la ventana al conectar explica todo esto, con cómo permitir la grabación de audio en el Windows remoto: paso a paso en el editor de directivas (`gpedit.msc`, una consola MMC) o con un comando para el registro, y el servicio Audio de Windows en Windows Server.

## Portapapeles

- **Texto e imágenes**: Ctrl+V en la sesión envía antes el portapapeles local; lo copiado en el remoto se copia en local cuando el navegador lo permite (y siempre se ve en el panel Portapapeles).
- **Texto con formato**: el texto copiado en Word, Outlook o un navegador conserva negritas, colores, tablas y enlaces en los dos sentidos (va también como HTML, el formato «HTML Format» de Windows). Cada aplicación pega el formato que admite: el Bloc de notas y WordPad, solo el texto. El panel Portapapeles envía siempre texto sin formato.
- **Archivos**: arrástralos sobre la imagen remota y suéltalos donde quieras pegarlos: en el escritorio, en una carpeta abierta del Explorador, en un correo que estás escribiendo… Se suben, se ponen en el portapapeles remoto y se pegan con un clic en ese punto y Ctrl+V. La pasarela avisa cuando la aplicación los pide: «N archivo(s) pegados». Si en 10 s no los pide, quedan en el portapapeles remoto para pegarlos a mano con Ctrl+V.
  - En el escritorio, Windows los coloca en el primer hueco libre (no justo donde se sueltan).
  - El clic es un clic de verdad: soltarlos sobre un botón lo pulsa.
  - El panel Archivos solo los pone en el portapapeles remoto (para Ctrl+V donde quieras): elígelos o suéltalos en su zona punteada. Muestra los últimos archivos enviados y su estado: enviándose, en el portapapeles remoto, pegados, o ya no están porque después se copió otra cosa. Las carpetas no pasan por el portapapeles (usa la carpeta compartida). Los copiados en el remoto se ofrecen como descarga.
- **Historial** (panel Portapapeles), como Win+V:
  - Recoge los últimos 25 textos e imágenes que han pasado por el portapapeles compartido, en los dos sentidos, el más reciente arriba, con su origen («Del equipo remoto» o «De este equipo») y la hora. Volver a copiar lo mismo lo sube.
  - **Pegar** (solo texto) lo pega en la aplicación activa del equipo remoto: la pasarela lo pone en el portapapeles remoto, pulsa Ctrl+V y lo deja como estaba, igual que con el teclado del móvil (ver [TECLADO-MOVIL](TECLADO-MOVIL.md)).
  - **Copiar** lo deja en el portapapeles de los dos lados, para pegarlo con Ctrl+V donde quieras.
  - Solo está en la memoria de esa ventana (nunca va a la pasarela ni se guarda) y se borra al cerrarla; se pueden quitar elementos o borrarlo entero. Textos de hasta 1 MB e imágenes de hasta 4 MB.

## Carpeta compartida

*Carpeta compartida* en la web gestiona una carpeta personal en el servidor web; en la sesión es una unidad que el Explorador de Windows muestra en «Carpetas y unidades redirigidas» como **Carpeta compartida en MuDesk** (en el idioma de la página; el «en» lo pone Windows). El administrador fija la cuota; la barra de arriba muestra lo ocupado.

Funciona como un explorador de archivos:

- **Árbol de carpetas** a la izquierda (cada carpeta se lee al abrirla), el contenido de la carpeta a la derecha en **Detalles** (nombre, modificado, tamaño; un clic en el encabezado ordena) o **Iconos** (las imágenes con miniatura), barra de ruta, **Carpeta superior** y un cuadro para buscar en la carpeta. En el móvil el árbol se abre con el botón **Carpetas**.
- **Subir**: **Subir archivos**, **Subir carpeta**, o arrastra archivos y carpetas desde el equipo a la lista (la carpeta que se ve) o a cualquier carpeta de la lista, del árbol o de la barra de ruta. Las carpetas arrastradas conservan su estructura, también las vacías (el botón **Subir carpeta** no puede enviar las vacías: los navegadores no las dan). Si ya hay algo con el mismo nombre, pregunta: **Reemplazar** (las carpetas se combinan y los archivos se sustituyen), **Omitir** o **Cancelar**. La barra de estado muestra el progreso, con una X para cancelar; la subida sigue aunque cambies de carpeta, y salir de la página pide confirmación.
- **Organizar**: arrastra elementos a una carpeta (lista, árbol o barra de ruta) para moverlos; si los mantienes sobre una carpeta cerrada del árbol, se abre. **Nueva carpeta** se crea y se nombra en el momento; **Cambiar nombre** (F2) edita el nombre en su sitio; **Eliminar** (Supr) pregunta antes, las carpetas con todo su contenido.
- **Seleccionar**: clic, Ctrl+clic, Mayús+clic, las casillas, un recuadro dibujado con el ratón en una zona vacía de la lista, Ctrl+A. Teclas: flechas, Inicio/Fin, Intro abre, Retroceso o Alt+↑ sube, escribir salta al nombre. El botón derecho abre un menú.
- **Descargar**: un archivo tal cual; varios elementos o una carpeta en un solo ZIP, que el servidor escribe mientras lo envía (sin copia en el servidor; hasta 4 GB y 65.535 elementos). En Chrome y Edge, los elementos arrastrados al escritorio del equipo se descargan allí. Doble clic abre una carpeta, descarga un archivo o muestra una imagen en otra pestaña.
- Las carpetas del árbol tienen sus propias acciones: botón derecho (Abrir, Nueva carpeta dentro, Descargar en ZIP, Cambiar nombre, Eliminar), F2 cambia el nombre en su sitio, Supr elimina tras preguntar, y se pueden arrastrar a otra carpeta. Si cambias de nombre o mueves la carpeta en la que estás, la página la sigue; si la eliminas, pasa a la carpeta superior.
- En el móvil o la tableta, un toque abre; las casillas seleccionan (después, un toque añade a la selección).

## Impresora MuDeskPDF

Al imprimir en **MuDeskPDF** en la sesión remota, el documento llega al navegador como PDF y aparece un aviso con **Imprimir** (diálogo de impresión del navegador, con cualquier impresora local) o **Descargar**. El navegador no puede ver directamente las impresoras locales; por eso la impresión pasa por PDF. Los documentos que no son PDF (PostScript, XPS) solo se pueden descargar.

## Varias pantallas

> En **Chrome y Edge** (y otros navegadores basados en Chromium) la API *Window Management* da la lista de pantallas. **Firefox y Safari no la tienen**: la otra pantalla **se busca** abriendo una ventana junto a la actual (ver *Firefox y Safari*, abajo); la ventana de conexión lo avisa. **Pantalla completa** ocupa siempre la pantalla donde está la ventana.

Chrome y Edge (API Window Management): marca **Usar todas mis pantallas**, acepta el permiso y cada pantalla será un monitor independiente en la sesión remota (no un escritorio estirado). La ventana actual pasa a pantalla completa y, en el mismo clic, se abre la ventana de la otra pantalla.
- **Una pantalla solo forma parte del escritorio remoto mientras su ventana está abierta.** Al conectar, el equipo remoto tiene el monitor de esta ventana y el de cada ventana que se haya abierto; al abrir la ventana de otra pantalla, su monitor se añade, y al cerrarla se quita y Windows pasa sus programas a las pantallas que quedan. Así ningún programa se abre en una pantalla que no se ve. El cambio se hace sin reconectar (Display Control; los servidores sin él se reconectan). Mientras el servidor no acepta el monitor nuevo, su ventana se ve en negro y no envía ratón.
- Las ventanas de las otras pantallas se cierran con la de la sesión: al terminar la sesión, al cerrar o recargar su pestaña, o si pasa a mostrar otra cosa.
- **Pantalla completa** abre también la ventana de la otra pantalla si está cerrada (o ábrelas desde el panel **Pantallas**); en cada ventana nueva pulsa su botón **Pantalla completa**. Esa ventana tiene una barra arriba (vuelve al llevar el ratón al borde superior) con **Pantalla completa** / **Salir de pantalla completa** y **Cerrar esta pantalla**. Chromium solo deja hacer una cosa por clic (pantalla completa o abrir una ventana), salvo una ventana abierta justo después de pedir la pantalla completa en una pantalla concreta: por eso se pide así. Una vez conectada, el panel **Pantallas** se abre solo mientras la ventana de una pantalla esté cerrada.
- Si la sesión se conectó sin esa opción y el ordenador tiene varias pantallas, **Pantalla completa** ofrece **Usar todas** (la sesión se vuelve a conectar con un monitor remoto en cada pantalla) o **Solo esta**.
- Con **Al cambiar el tamaño de la ventana: Ajustar la resolución remota** (el valor por defecto), cada monitor remoto toma el tamaño de su ventana y lo sigue: al pasar a pantalla completa, al cambiar el tamaño de la ventana… sin márgenes negros y sin reconectar (Display Control; los servidores sin él se reconectan con el tamaño nuevo). Los monitores se quedan uno al lado del otro (o uno encima del otro), como las pantallas. Con los otros modos, cada ventana muestra su monitor entero y en proporción.
- Arrastrar de una pantalla a otra (una ventana, un icono) funciona: mientras el botón sigue pulsado, el punto remoto se calcula con la posición del ratón en la pantalla y la ventana que hay debajo.
- Con una conexión **RemoteApp**, la ventana de cada pantalla muestra la página con las ventanas del programa que están en esa pantalla. La barra de tareas sigue en la ventana de la sesión, cuya pantalla es la principal remota.

### Firefox y Safari

Sin la API, la página no puede pedir la lista de pantallas, pero sí saber en qué pantalla está cada ventana suya. Así que la busca:
1. Marca **Usar todas mis pantallas** al conectar (o pulsa **Pantallas** → **Buscar otras pantallas** en la barra, ya conectado).
2. Se abre una ventana justo en la esquina de la pantalla de al lado: a la derecha; o donde se encontró la otra pantalla la vez anterior; o, si la ventana de la sesión no está en la pantalla principal, hacia la principal. Si hay una pantalla allí, la ventana cae en ella y dice dónde está y qué tamaño tiene; Firefox la devuelve a la pantalla actual si no hay ninguna.
3. Encontrada, la sesión se conecta (o se vuelve a conectar) con un monitor remoto en cada pantalla, en la misma posición que en el escritorio, y esa ventana ocupa la otra pantalla y la muestra. Pulsa **Pantalla completa** en cada ventana. Si cierras esa ventana, su monitor sale del escritorio remoto; vuelve con **Pantallas** → **Abrir**.
4. Si no la encuentra (por ejemplo, la otra pantalla está a la izquierda de la principal), esa ventana lo dice: llévala a la otra pantalla y pulsa **Usar esta pantalla**. Se recuerda en este navegador y la próxima vez se busca ahí directamente (y **Pantalla completa** ofrecerá usar todas).

Hay que permitir las ventanas emergentes del sitio: los navegadores dejan abrir una ventana por clic, y por eso se prueba un solo sitio cada vez. Se usan dos pantallas. Probado en Firefox 160 con dos pantallas de 1920×1080; también en Edge 157, y en Chrome tras su aviso de permiso. Safari no se ha probado nunca (no hay dispositivo de Apple).

## Teclado en pantalla

En un ordenador con ratón, el botón **Teclado en pantalla** abre un teclado completo sobre la sesión (Esc, F1–F12, Supr, números, letras y signos, Tab, Bloq Mayús, Mayús, Ctrl, Win, Alt, Alt Gr, espacio, Retroceso, Intro, flechas; el Alt derecho es Alt Gr con la distribución española). Funciona con cualquier servidor, también xrdp.
- Cada tecla se envía como la tecla física: la distribución de teclado de la sesión remota decide el carácter, igual que con un teclado de verdad (las teclas muertas como ´ y Alt Gr incluidas).
- Mayús, Ctrl, Alt, Alt Gr y Win se quedan pulsadas hasta la siguiente tecla (Ctrl + C, Win + E, Mayús + 4 = $…), se ven marcadas y una línea encima del teclado lo explica. Para enviar una sola, púlsala otra vez: Windows actúa al soltarla (Win sola abre Inicio).
- Las etiquetas siguen la distribución de la conexión: español o inglés (EE. UU.). Con otras distribuciones, las del navegador si las da (Chrome, Edge) o las inglesas.
- Se mueve a cualquier sitio de la ventana arrastrándolo con el ratón desde el asa de cuatro flechas o desde su borde (las teclas no lo mueven); no sale de la ventana y, al volver a abrirlo, aparece donde se dejó. **X** lo cierra.

## Móviles y tabletas

| Gesto | Acción |
|---|---|
| Tocar | Clic izquierdo |
| Pulsación larga | Clic derecho |
| Arrastrar | Arrastrar con el botón izquierdo |
| Dos dedos arriba/abajo | Desplazar |
| Pellizcar | Ampliar la imagen; arrastra con dos dedos para moverte |
| Tocar un cuadro de texto | Se abre el teclado del dispositivo |

Con la opción «Teclado del móvil por portapapeles» (en la ficha de la conexión; en «Automático» se marca sola en móviles y tabletas, y se puede desmarcar en la ventana de conexión), el texto que el teclado del móvil manda de golpe (autocorrector, escritura deslizando, dictado) se pega a través del portapapeles remoto, porque el Bloc de notas, el Terminal y el Explorador de Windows 11 estropean las letras que les llegan seguidas. Después el portapapeles queda como estaba (también una imagen copiada en Windows) y no aparece en el historial (Win+V). Las letras sueltas van como siempre. En programas donde Ctrl+V no es pegar (PuTTY, vim), pon la opción en «Nunca». El icono ⓘ junto a la opción explica el problema, las pruebas que lo detectaron y las fuentes. Más detalle en [Teclado del móvil y Windows 11](TECLADO-MOVIL.md).

El teclado se abre cuando, tras el toque, Windows muestra el cursor de texto (la «I»). También aparece sobre texto que no se puede editar (páginas web, documentos de solo lectura); ciérralo con el botón del dispositivo. Si no se abre, usa el botón **Teclado en pantalla** de la barra.

## Instalar como app

En móviles y ordenadores, los navegadores que lo permiten pueden instalar MuDeskClient como app (por ejemplo *Instalar aplicación* en el menú del navegador, o *Añadir a pantalla de inicio* en iPhone y iPad, sin probar: no hay dispositivo de Apple). Se abre en su propia ventana y con su icono. Es la misma aplicación web: no hay service worker y no se guarda nada para usarla sin conexión.

## Tras una actualización

Cuando se actualiza MuDesk en el servidor, la página carga los archivos nuevos la próxima vez que se abre. Una página que ya estaba abierta lo dice abajo (**Recargar**, o la X para cerrar el aviso) la próxima vez que pide su estado al servidor: al entrar o salir, al caducar su sesión, o tras cambiar el idioma, la contraseña o un segundo factor. Al recargar se desconecta la sesión remota abierta en ella.

## Sesión caducada

La sesión web se cierra tras el tiempo de inactividad que fija el administrador (30 minutos por defecto) y, en todo caso, a las 12 horas. Cuando ocurre, aparece una ventana **Sesión caducada** que dice el motivo (inactividad, las 12 horas, cambio de contraseña, cuenta desactivada…). **Aceptar** lleva a la pantalla de entrada y, al volver a entrar, a donde estabas.
- La página se da cuenta sola, sin esperar a que pulses nada: cuando la sesión debería terminar, se lo pregunta a la pasarela. Esa pregunta no cuenta como actividad, así que no mantiene abierta una sesión inactiva. Si la estás usando en otra pestaña, la sesión sigue abierta y no aparece nada.
- Usar una sesión remota en esa ventana cuenta como actividad y mantiene abierta la sesión web. Si aun así termina (sin actividad durante el tiempo de inactividad, o las 12 horas), la sesión remota termina con ella y la ventana lo dice en el momento.
- Al salir, o al terminar la sesión, terminan también las sesiones remotas abiertas desde ella. Salir en una pestaña cierra también las demás pestañas del mismo navegador, y cada una lo dice.
- Cambiar tu contraseña termina las sesiones remotas de tus otros accesos; la ventana desde la que la cambiaste sigue.
- Cuando se te pide de nuevo la contraseña (para añadir una passkey o una app de autenticación, cambiarla o quitar un segundo factor), cinco errores cierran la sesión.

## Perfil

- **Passkeys**: cada una es «Sin contraseña» (entras solo con ella, con **Entrar con passkey**) o «Segundo paso» (entras con tu contraseña y después la confirmas). El tipo depende del dispositivo y del navegador: sin contraseña solo si pueden guardar con la passkey la clave que cifra tus contraseñas remotas (extensión PRF); MuDesk lo dice al añadirla y la lista lo muestra.
- **Contraseña, idioma, verificación en dos pasos (TOTP o passkeys).** Un código TOTP sirve una sola vez: uno ya usado, como el que activó la 2FA, se rechaza y el mensaje pide esperar al siguiente.
- **Tus sesiones remotas abiertas**: las sesiones de tu usuario que están en marcha; **Finalizar** cierra una.
- **Navegadores de confianza**: los navegadores desde los que has entrado, con su alta y su último uso, el navegador y la IP. Desde ellos puedes entrar aunque tu usuario esté bloqueado por intentos fallidos de otros. **Olvidar** quita uno y **Olvidar los demás**, todos menos este; sus cookies dejan de servir. Las bajas quedan en la auditoría.
- Al entrar, un aviso muestra tu último acceso (fecha e IP) y los intentos fallidos con tu usuario desde entonces, leídos de la auditoría (hasta 90 días). Si no fuiste tú, cambia tu contraseña y revisa tus navegadores de confianza. Una vez cerrado, el aviso no vuelve a salir.

Si el administrador exige 2FA y aún no la tienes, entras y solo ves tu perfil hasta que añadas TOTP o una passkey.

## Administración

- **Usuarios**: alta, activar/desactivar, borrar, **Permisos de administrador** (una casilla, desmarcada en un usuario nuevo; puede haber varios administradores, nunca ninguno: el último activo no se puede desactivar, quitar de administrador ni borrar), sesiones simultáneas, restablecer contraseña (se pierden las contraseñas remotas guardadas, y las passkeys pasan a servir solo como segundo paso tras la contraseña), quitar 2FA. Desactivar o borrar un usuario, o restablecer su contraseña, termina sus sesiones, también las remotas.
- **Conexiones**: las conexiones del administrador, cada una para los usuarios marcados o para **todos los usuarios** (también los que se creen después), que no pueden cambiarlas ni borrarlas; sin usuario de Windows, cada uno escribe el suyo al conectar, y cada uno guarda su propia contraseña. Debajo, las conexiones propias de cada usuario, que el administrador puede cambiar o borrar (su contraseña guardada solo se puede olvidar; también se olvida al cambiar el equipo, el puerto, el usuario, el dominio o la seguridad). **Importar .rdp** también funciona aquí: un archivo (o una conexión nueva) se abre con **Todos los usuarios** marcado; varios archivos se guardan sin usuarios («Todavía no las ve nadie»), para editarlos y elegirlos.
- **Ajustes**: listas blancas de IPs cliente y de equipos remotos, bloqueo de acceso, inactividad web y remota, sesiones por usuario, **Sesiones RDP a la vez (todos los usuarios)** (20 por defecto, 0 = sin límite; cada sesión ocupa un proceso PHP, así que debe quedar por debajo de los que tenga el servidor: ver [HOSTING](HOSTING.md#capacidad)), reintentos de conexión si se pierde (10 por defecto, 0 = ninguno; esperas de unos 1, 2, 3, 5, 8 y luego 10 s: tras la primera, hasta la mitad más al azar), 2FA obligatorio, conservación de auditoría, cuota de carpeta compartida (una por usuario para la unidad de todas sus sesiones, el gestor de archivos y los ficheros del portapapeles) y **Permitir guardar la contraseña de las conexiones RDP** (activado por defecto). Al desactivarlo, tras confirmarlo, se borran las contraseñas RDP guardadas de todos los usuarios (`rdp_passwords_erased` en la auditoría, con cuántas), el formulario de la conexión deja de tener el campo de contraseña y la ventana de conexión, la casilla «Guardar contraseña»: se pide al conectar.
  - Con los equipos remotos permitidos en **Todo permitido**, nunca se conecta al propio servidor (127.0.0.0/8, ::1), a direcciones de enlace local (169.254.0.0/16, donde las nubes guardan sus metadatos, y fe80::/10), a las otras direcciones de metadatos de las nubes (100.100.100.200, fd00:ec2::254) ni a 0.0.0.0/8 o ::, tampoco en sus formas ::ffff:…, ::a.b.c.d o 64:ff9b::; para llegar a ellas, usa **Solo la lista** y añádelas. Administración muestra un aviso mientras esté en «Todo permitido».
  - Para exigir 2FA a todos los usuarios, el administrador debe tenerla antes; si no, el ajuste se rechaza.
- **Diagnóstico**: trazas de las sesiones iniciadas en modo diagnóstico (o de todas con *Ajustes → Modo diagnóstico en todas las sesiones*): negociación TLS/NLA, datos y capacidades del servidor, licencias, canales y sus mensajes, formatos de audio (y los del micrófono: ofrecidos, aceptados y abiertos) y de portapapeles, redirección de dispositivos, canal gráfico (versiones, comandos y códecs contados en el navegador), número y tamaño de mensajes cada 10 s, errores de la pasarela y del navegador, motivo del fin y código de error del servidor. Nunca pantalla, teclas, contenido del portapapeles o de archivos, contraseñas ni claves. Se descargan o borran desde aquí; se borran solas a los 7 días (máximo 8 MB por sesión). Las descargas y borrados quedan en la auditoría. Desde la línea de comandos: `TRACE=1 php tests/rdp_probe.php …`. Útil para enviar cuando algo falla con un servidor.
- **Sesiones activas**: quién está conectado y a dónde; finalizar una sesión.
- **Auditoría**: todo lo que ocurre, una línea por evento con fecha (ms), usuario, IP, navegador y resultado (correcto/fallido con el motivo). Nunca contraseñas ni contenidos.
  - Acceso: cada intento con el método (contraseña, TOTP, passkey) y el motivo del fallo (`unknown_user`, `bad_password`, `disabled`, `bad_code`, `locked_ip`/`locked_user`/`locked_global`…), instalación, salida, sesiones cerradas (inactividad, tiempo máximo, otro navegador, contraseña cambiada, usuario desactivado o borrado), rechazos por CSRF o por IP y peticiones rechazadas.
  - Perfil y administración: contraseña, idioma, 2FA y passkeys, navegadores de confianza añadidos (`device_add`) y olvidados (`device_forget`); usuarios, ajustes y conexiones con valor anterior → nuevo (las contraseñas guardadas solo como "changed"); cierre de sesiones (por un administrador o por su dueño); búsquedas, exportaciones y verificaciones de la auditoría.
  - Sesiones remotas: opciones usadas (tamaño y pantallas, colores, audio, micrófono, portapapeles, unidad, impresora, H.264, compresión), certificado aceptado o cambiado, inicio, cambios de tamaño, fin con motivo, duración y bytes; rechazos (`rdp_denied`, por ejemplo `busy` con el servidor lleno).
  - Portapapeles (cada copia, en los dos sentidos: tipo y tamaño, nombres y tamaños de archivos), unidad compartida (cada archivo leído o escrito con sus bytes, carpetas creadas, renombrados, borrados; la simple navegación no se registra), gestor de archivos web (subidas, descargas y ZIP con los nombres, carpetas, movimientos y cambios de nombre como `file_move`, borrados; las miniaturas no se registran), trabajos de impresión y sus descargas.
  - Búsqueda por fechas, usuario, evento (prefijo: `login` encuentra todos los eventos de acceso), IP, texto libre y resultado; el texto se busca en lo que muestra cada línea (usuario, IP, evento, navegador y cada detalle como `clave=valor`, por ejemplo `reason=unknown_user`), en mayúsculas o minúsculas de cualquier alfabeto, nunca en los nombres de los campos, la fecha ni los hashes de la cadena; resumen por evento (clic para filtrar); detalle de cada línea; exportación de lo filtrado a CSV o JSON (hasta 20 000 filas).
  - **Exportación firmada**: la exportación JSON llega en un ZIP con `audit.json` y `audit.json.sig`, una firma hecha con la clave RSA del servidor; cada fila es la línea tal como está guardada. El CSV no se firma. Para comprobar un fichero, abre *Comprobar las exportaciones firmadas* (debajo de los botones de exportar), guarda la clave pública como `mudesk-audit-key.pem`, descomprime la exportación y ejecuta `openssl dgst -sha256 -verify mudesk-audit-key.pem -signature audit.json.sig audit.json`: responde «Verified OK», o falla si el fichero se ha cambiado. La clave se crea la primera vez (unos 2 s) y se guarda en `data/audit-key.php`.
  - Se conservan 90 días por defecto; *Ajustes → Días de auditoría a conservar* (0 = sin límite). La purga queda registrada.
  - **Verificar integridad**: cada línea va firmada y encadenada con la anterior (HMAC con la clave del servidor). No se oculta nada: las líneas modificadas o insertadas y las que faltan se listan y se marcan en rojo. Comprueba hasta el momento en que empieza; lo que se escriba mientras tanto queda para la siguiente comprobación. Quien tenga la clave del servidor (`data/secret.php`) podría rehacer toda la cadena, así que guarda copia de los registros en otro sitio si necesitas una prueba más fuerte.
