# Teclado del móvil y Windows 11

[English](../MOBILE-KEYBOARD.md)

## El problema

Al escribir desde el teclado en pantalla de un móvil o una tableta, el Bloc de notas, el Terminal y el Explorador de Windows 11 estropean el texto:

- repiten la última letra de una palabra («or not» sale como «tttttt»);
- pierden mayúsculas, acentos o letras sueltas;
- pierden un Intro o un Ctrl+V.

WordPad, Word, los navegadores y la búsqueda de Windows lo reciben bien. El teclado físico tampoco se ve afectado.

No es un fallo de MuDeskClient. Lo describen también otros programas que mandan teclado a Windows 11:

- [nemo-voice-typing #4](https://github.com/Garnet-Owl/nemo-voice-typing/issues/4) y [dictatem #278](https://github.com/JohnJohn4/dictatem/issues/278): dictado;
- [nectarlink #288](https://github.com/thtbee/nectarlink/pull/288) y [relay #20](https://github.com/mohamedmoheyeldin/relay/pull/20): escritura automática;
- [justrdp #532](https://github.com/kihyun1998/justrdp/pull/532): la consola de Windows 11 24H2 descarta las letras Unicode que llegan por RDP, con cualquier cliente.

Microsoft no lo ha reconocido ni corregido (noviembre de 2026).

## Por qué pasa

RDP solo tiene dos formas de mandar texto: **letras Unicode** o **teclas** (scancodes, según la distribución de teclado de Windows). Windows App de Microsoft en Android usa las mismas dos: Unicode por defecto, o teclas si se activa «Use scancode input when available». Con el mismo Windows debería tener el mismo problema; no se ha probado aquí porque hace falta un móvil Android.

Medido contra Windows 11 Pro con el Bloc de notas 11.2609, la revisión ortográfica y la autocorrección del Bloc de notas revisan el texto en segundo plano. Mientras trabajan, una tecla puede perderse si justo detrás llega otra tecla de control (Fin, Ctrl) o un pegado. Una letra normal detrás no la estropea.

Windows no avisa de cuándo ha terminado ese trabajo. Por eso ningún cliente puede esperarlo con exactitud; solo podría esperar un tiempo a ojo, y eso falla según lo cargado que esté el equipo.

## Pruebas

En el Bloc de notas de Windows 11 Pro, por RDP desde MuDeskClient. Cada línea era «Hola, ñandú ¿qué? ÁÉÍÓÚ áéíóú Ü ü Zorro Más Pequeño 1234567890. € @# fin», enviada palabra a palabra como la manda un teclado de móvil.

| Prueba | Resultado |
|---|---|
| Letras Unicode, una cada 100 ms, con corrector | 1 de 3 líneas mal |
| Lo mismo, sin corrector | 3 de 3 líneas bien |
| Teclas de la distribución, al ritmo de una persona | 1 de 3 líneas mal (perdió una mayúscula) |
| Teclas de la distribución, de golpe | 3 de 3 líneas mal |
| **Pegar** el texto por el portapapeles | **8 de 8 líneas bien**, en todas las rondas |
| Intro sin nada detrás, tras un pegado | 0 de 8 Intros perdidos |
| Intro con una letra justo detrás | 0 de 18 perdidos |
| Intro con Fin o Ctrl+Fin justo detrás | 2 de 16 perdidos |
| Bloc de notas en reposo, Intro y un pegado justo detrás, con corrector | 3 de 8 perdidos |
| Lo mismo, sin corrector | 0 de 8 perdidos |
| Prueba de estrés: línea pegada, Intro y la línea siguiente sin pausa | 1 a 3 de 8 perdidos (con o sin corrector) |
| **Ritmo real**: 0,3 s hasta el Intro y 0,4 s hasta la palabra siguiente, con corrector | Texto 24 de 24 bien; Intros 22 de 24 (tres rondas). En una, el Bloc de notas ignoró un Ctrl+V y el texto se escribió a los 10 s |

Se probaron y se descartaron:

- separar más las letras (100 y 150 ms);
- mandar teclas de la distribución en vez de letras;
- retrasar la suelta de cada tecla;
- un pegado vacío de comprobación antes de la tecla siguiente: siguieron perdiéndose 2 de 8 Intros y había más cambios de portapapeles.

## Qué hace MuDeskClient

**Opción «Teclado del móvil por portapapeles»**

- Está en la ficha de cada conexión: «Automático», «Siempre» o «Nunca».
- En «Automático» se marca sola en los móviles y las tabletas (pantalla táctil sin ratón) y queda desmarcada en el ordenador.
- En la ventana de conexión se puede marcar o desmarcar para esa sesión.
- Necesita el portapapeles compartido.
- El icono ⓘ junto a la opción explica el problema, las pruebas y las fuentes dentro de la propia aplicación.

**Con la opción activada**

- **Letras sueltas:** van como siempre, en Unicode y de una en una cada 100 ms.
- **Varias letras de golpe** (autocorrector, escritura deslizando, dictado): se **pegan**:
  1. Si lo último se copió en Windows, la pasarela lo lee antes (hasta 16 MB), en todos sus formatos (texto, formato, imagen…).
  2. Anuncia el texto en el portapapeles remoto, fuera del historial (Win+V) y de la nube.
  3. Espera la respuesta de Windows a ese anuncio y a que la pantalla muestre la última entrada enviada.
  4. Pulsa Ctrl+V y lo mantiene hasta que la aplicación pide el texto.
  5. Justo después de cada pegado deja el portapapeles como estaba, también fuera del historial (si no había nada, con un texto vacío).
- **Dos pegados seguidos:** Windows responde al anuncio antes de aplicarlo, no avisa cuando lo aplica (con el equipo ocupado tardó más de 1 s) y no aplicaba una lista igual a la que ya tenía. Con dos pegados seguidos, el segundo Ctrl+V pegaba otra vez el texto anterior (en el cuadro Ejecutar, «control control» en vez de «control printers»). Ahora el portapapeles se repone justo después de cada pegado y cada lista lleva un formato propio que alterna, así que dos listas seguidas nunca son iguales; lea la lista que lea la aplicación, el texto se lo pide a la pasarela y recibe el nuevo. Medido en Windows 11 Pro: 21 de 21 pegados seguidos bien en el cuadro Ejecutar y 30 de 30 palabras en el Bloc de notas (antes, el fallo salía en el primer o segundo intento).
- **Textos seguidos:** se unen en un solo pegado.
- **Lo que llegue mientras tanto:** espera su turno y sale cuando la aplicación ha dibujado lo pegado.
- **Lo que no se puede reponer** (archivos copiados en Windows, o más de 16 MB): el texto se escribe en vez de pegarse, para no perder lo copiado.

Cada paso espera una señal de Windows, no un tiempo fijo. Solo hay límites por si Windows no responde: 10 s para leer lo copiado, 2 s para la respuesta al anuncio y 10 s para que la aplicación pida el texto (pasado cualquiera, el texto se escribe); y si la pantalla no ha cambiado en los 2 s siguientes a esa respuesta, se pulsa Ctrl+V igualmente.

**Limitaciones**

- Ctrl+V no significa «pegar» en todos los programas (PuTTY, vim): en ellos conviene poner la opción en «Nunca».
- En la prueba de estrés, un Intro seguido sin pausa de más texto aún puede perderse en el Bloc de notas (1 a 3 de 8). Al ritmo real de un móvil se perdieron 2 de 24.
- El Bloc de notas ignora a veces un Ctrl+V mientras su corrector trabaja: el texto se escribe a los 10 s, bien pero tarde (1 de 30 palabras en la última prueba).
- Si Windows está tan ocupado que no ha aplicado una lista anunciada mucho antes, o si lo último copiado en Windows es una imagen, un pegado aún podría tomar el contenido anterior. No ocurrió en las pruebas.

## Recomendación

Si se escribe mucho desde el móvil en el Bloc de notas de Windows 11, desactivar en el propio Bloc de notas **Configuración → Ortografía → Revisión ortográfica** y **Autocorrección**. En las pruebas de arriba, sin corrector, las letras Unicode separadas salieron todas bien, y el Intro seguido de un pegado no se perdió ninguna vez con el Bloc de notas en reposo.
