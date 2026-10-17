# Alertas sonoras de la agenda de valoraciones: mejora

**Fecha:** 17-10-2026
**Estado:** **DESPLEGADO EN PRODUCCIÓN el 17-10-2026 a las 20:56 UTC** (15:56 en Bogotá), por orden expresa. Commit del CRM `3694ef6`; **no está subido a GitHub** (ver §8).
**Alcance:** solo el CRM (Angular). No toca el backend, la base de datos, Firebase, Meta, las citas, las fechas ni los recordatorios.

| | |
|---|---|
| Repositorio | `Iron-Body_Front` |
| Rama | `feat/valoraciones-sonidos`, creada desde `origin/main` (`d0f3f32`) |
| Commit | `3694ef6`, sobre `d0f3f32` |
| Producción antes | `d0f3f32` (`main-EZOBUSJ6.js`), publicado por un compañero a las 20:32 UTC |
| Producción ahora | `3694ef6` (`main-LEOX5IHK.js`) |

---

## 1. Qué cambia para la persona

En **Entrenadores › Calendario de citas › Alertas**, una vez activada la alerta, cada persona puede:

- **Elegir el sonido** entre cuatro: Normal, Importante, Voz y Urgente.
- **Probar cada uno** con su botón ▶. Mientras suena, el botón se anima.
- **Ajustar el volumen** de 0 a 100 %. Al soltar el control suena una muestra con el volumen nuevo.
- **Silenciar o reactivar**, como antes. Silenciar ahora también corta en seco el sonido que esté sonando.
- **Desactivar la alerta.**

**Todo se guarda para su usuario.** Si dos recepcionistas comparten el PC, cada una tiene su sonido, su volumen y su activación.

Lo que no cambia:

- **Qué avisos suenan:** solo los dos urgentes del sistema, «valoración en 15 min» y «valoración sin iniciar».
- **Cuántas veces:** una vez por aviso.
- **La tarjeta flotante:** sigue con «Ver cita», «Marcar como atendida» y «Silenciar».

---

## 2. Los cuatro sonidos

Se generan en el navegador con WebAudio. No hay archivos de audio que descargar ni licencias.

| Sonido | Cómo suena | Duración | Nivel medido al 80 % |
|---|---|---|---|
| **Normal** | «Pling-plong» de kalimba en quinta (la–mi), a media voz | ~1 s | RMS −25,1 dBFS, pico −8,8 |
| **Importante** (por defecto) | Arpegio de campanas en ascenso (sol–do–mi–sol), doblado a la octava grave, con el golpe del macillo y un brillo desafinado como en una campana real | ~1,8 s | RMS −20,8 dBFS, pico −4,0 |
| **Voz** | Campanilla de megafonía de tres notas (fa–la–do, timbre de vibráfono) y después, con voz del sistema: **«Atención, tienes una valoración próxima.»** | ~1,7 s de campanilla + frase | Campanilla: RMS −23,3 dBFS, pico −8,7 |
| **Urgente** | Tres ráfagas «bi-bi-bip», con la última nota de cada ráfaga una cuarta más alta, y después la voz: **«¡Urgente! ¡Meidei!»**, más lenta y más aguda (añadida después del despliegue, ver §9) | ~2 s de ráfagas + frase | Ráfagas: RMS −21,2 dBFS, pico −6,1 |

**Cómo están hechos:**
- **Una pizca de sala:** reverberación generada en el propio navegador. Le da cuerpo al sonido sin enturbiarlo.
- **Un compresor a la salida:** iguala el nivel de los cuatro y evita la saturación. Al 100 %, el pico más alto es −2,6 dBFS.
- **El «Urgente» concentra su energía entre 1 y 3 kHz,** que es donde rinden los altavoces pequeños de un PC de recepción.

**La voz:**
- **Frases fijas.** Solo dice dos, escritas en el código:
  - «Atención, tienes una valoración próxima.» para el aviso de 15 minutos;
  - «Atención, hay una valoración sin iniciar.» para el aviso de una valoración que ya debía haber empezado. Decir «próxima» ahí sería falso.
- **Nunca lee el aviso.** Ni el título ni el mensaje, así que no puede pronunciar el nombre del socio ni ningún otro dato suyo.
- **Elige la mejor voz en español del equipo:**
  - primero es-CO;
  - luego las variantes latinoamericanas (es-MX, es-US, es-419);
  - y, por encima del acento, las voces neuronales: las «Natural» de Edge y las «Google» de Chrome, que suenan mucho más humanas.
- **Si el equipo no tiene ninguna voz en español, no lee la frase con una voz inglesa.** Suena «Importante» en su lugar, y el panel lo avisa.

**Escúchalos ya.** Los cuatro sonidos están renderizados en WAV, con el mismo código y la misma salida, en la carpeta de la sesión: `scratchpad/sonidos-render/`. La voz no se puede grabar: depende de la voz instalada en cada PC.

---

## 3. Requisitos del encargo

| Requisito | Cómo se cumple |
|---|---|
| Cuatro sonidos: normal, importante, voz y urgente | `valuation-sounds.ts` |
| Selector, volumen, probar cada uno y silenciar | Panel «Alertas» (`VaAlertSettingsComponent`) |
| Preferencias por usuario | Clave `ironbody.valuationAlerts.prefs.<id del usuario>`. Ver §4 |
| Reutilizar el sistema de notificaciones | Sin cambios: la misma bandeja, el mismo canal y la misma relectura de 120 s |
| Restricciones de audio del navegador | El audio solo arranca tras un gesto: «Activar y probar», «Probar» o, tras recargar, el primer clic o tecla en el CRM. Si el navegador no deja, el aviso queda visual y el panel lo dice. Nunca se intenta saltar la regla |
| Evitar sonidos duplicados | Una vez por aviso, como antes, también tras recargar. **Nuevo:** si llegan varios avisos juntos suena uno solo y la tarjeta dice «y N más»; un sonido nuevo apaga el anterior en lugar de superponerse |
| No reproducir alertas continuamente | Cada sonido es una secuencia cerrada: todo oscilador tiene su fin programado, no hay `loop` ni repetición |
| Solo avisos autorizados | Sin cambios: hace falta `valuations.view_all` y un aviso del sistema (`type=valuation`, `action_type=valuation_detail`, prioridad alta, sin leer). Un aviso fabricado a mano no suena |
| La voz no menciona datos del socio | Frases fijas. Hay una prueba que manda un aviso con nombre y documento y comprueba que la voz no dice nada de eso |
| Diseño negro y amarillo | Tokens del CRM (`--ib-gold`, `--ib-panel`…), Lexend e Inter. La selección no se indica solo por color: lleva botón de radio y borde |
| No alterar fechas, citas ni recordatorios | No se tocó el backend ni nada del calendario |
| No modificar Firebase ni Meta | No se tocaron |
| Pruebas automatizadas | §6 |
| No desplegar sin autorización | Se desplegó solo tras la orden «haz commit y despliega el CRM a producción» |

---

## 4. Decisiones que conviene conocer

1. **Preferencias por usuario, guardadas en ese navegador; no en el servidor.**
   - **Lo que cubre:** cada persona tiene las suyas en el PC donde trabaja, que es el caso de recepción.
   - **Lo que no:** si la misma persona cambia de PC, allí empieza con los valores por defecto (Importante, 80 %).
   - **Por qué así:** guardarlas en el servidor exigía un endpoint y una migración nuevos, y desplegar también el backend. Si hiciera falta, es una ampliación pequeña y aislada.
2. **La activación pasa a ser de cada persona.**
   - Antes, quien activaba la alerta la activaba para todo el navegador. Ahora cada persona la activa una vez.
   - Quien ya la tenía activada antes de este cambio la conserva: sus preferencias heredan la activación y el silencio guardados en el navegador.
3. **Lo ya sonado sigue siendo del navegador.** Un aviso que ya sonó en ese PC no vuelve a sonar porque cambie la persona sentada delante. Así era antes y se mantiene.
4. **Volumen en curva cuadrática:** al 50 %, la ganancia es un cuarto. El oído percibe el volumen en escala logarítmica, y así el control se siente uniforme.
5. **Probar y silenciar son independientes.** Con la alerta silenciada se puede seguir probando sonidos; silenciar solo afecta a los avisos.
6. **Primer gesto tras recargar.**
   - Con la alerta activada, el servicio escucha el primer clic o tecla en cualquier pantalla del CRM y desbloquea el audio con él. Después deja de escuchar.
   - Es la vía que el navegador permite. Sin esto, tras recargar, el primer aviso podía quedar mudo hasta que alguien pulsara «Probar».
   - Sin la alerta activada no escucha nada ni crea audio.

---

## 5. Archivos

| Archivo | Qué es |
|---|---|
| `src/app/modules/valuations/valuation-sounds.ts` | **Nuevo.** Catálogo, síntesis de los cuatro sonidos, frases fijas de la voz, curva de volumen y elección de la voz |
| `src/app/modules/valuations/valuation-sound-player.ts` | **Nuevo.** Reproductor: desbloqueo del audio, un sonido a la vez, compresor de salida, voz y respaldo sin voz en español |
| `src/app/modules/valuations/valuation-alert.service.ts` | Preferencias por usuario (sonido, volumen, activación, silencio), un solo sonido cuando llegan varios avisos juntos, primer gesto. El sintetizador antiguo de tres notas se retiró |
| `src/app/modules/valuations/valuation-alert.ts` | Panel «Alertas»: selector, ▶ por sonido, volumen, silenciar y aviso sin voz. La tarjeta flotante no cambia |
| `src/app/modules/valuations/valuation-audio.testing.ts` | **Nuevo.** AudioContext y voz de mentira para las pruebas (jsdom no trae WebAudio) |
| `src/app/modules/valuations/valuation-sounds.spec.ts` | **Nuevo.** 22 pruebas del motor y del reproductor |
| `src/app/modules/valuations/valuation-alert.service.spec.ts` | Adaptada al reproductor y 11 pruebas nuevas |

**Tamaño:** el código nuevo va en un chunk diferido, `chunk-BKKYUPEK.js`, el mismo que ya cargaba la alerta. El bundle inicial no crece.

---

## 6. Pruebas

| Prueba | Resultado |
|---|---|
| Módulo de valoraciones (9 archivos) | **171 de 171** |
| Motor y reproductor (`valuation-sounds.spec.ts`) | **22 de 22** |
| Alerta y panel (`valuation-alert.service.spec.ts`) | **25 de 25**, 11 nuevas |
| Suite completa del CRM | 1960 pasan y 16 fallan: **los mismos 16 de la línea base** (moderación y tiempo real), cero fallos nuevos |
| `test:agenda-tz`, con el reloj en Tokio | **394 de 394** |
| `check:literals` | Correcto |
| Build de producción | Correcto. Los avisos de presupuesto ya existían |

**Qué protegen las pruebas nuevas:**
- Cada sonido termina solo, dentro de su duración: todo oscilador tiene su fin y nada se repite.
- Ninguna ganancia del camino de audio supera la unidad.
- Los cuatro son reconocibles entre sí.
- «Normal» es más discreto que «Importante».
- La curva de volumen hace lo que dice.
- La elección de voz prefiere el español latinoamericano y neuronal, y no elige ninguna si no hay español.
- La voz:
  - dice solo su frase fija, y la de «sin iniciar» cuando toca;
  - usa el volumen elegido;
  - entra después de la campanilla;
  - sin español, suena «Importante»;
  - si las voces llegan tarde, se esperan un momento, no indefinidamente.
- Un sonido nuevo apaga el anterior con un fundido y cancela la voz pendiente.
- Audio bloqueado: no suena y lo dice; un gesto posterior lo desbloquea.
- Preferencias:
  - van por persona y se recuperan al volver;
  - se heredan de la clave antigua;
  - las corruptas o fuera de rango se corrigen.
- El aviso suena con el sonido y el volumen de la persona.
- Varios avisos juntos suenan una sola vez, y el siguiente, pasado ese sonido, sí suena.
- Silenciar corta el sonido en curso.
- El primer clic desbloquea el audio, una sola vez. Sin la alerta activada no se escucha el clic ni se crea audio.
- El panel completo funciona: activar, cuatro botones ▶, elegir, volumen con muestra, silenciar y aviso sin voz.

**Mutaciones: 7 de 7 detectadas.** Se rompió el código a propósito siete veces y en cada caso falló al menos una prueba:
- la voz dice «próxima» también en «sin iniciar»;
- sin el control de varios avisos a la vez;
- un sonido nuevo no apaga el anterior;
- preferencias comunes a todas las personas;
- sin el respaldo cuando no hay voz en español;
- la voz añade texto a su frase;
- el clic no se desengancha.

Los archivos se restauraron y se verificó byte a byte.

**Un fallo real encontrado por las pruebas y ya corregido.** Si un aviso no podía sonar porque el navegador aún tenía el audio bloqueado, quedaba marcado como «sonando». El aviso siguiente, aunque el audio ya estuviera desbloqueado, se callaba. Ahora solo cuenta como sonando lo que de verdad llegó a sonar.

**Renderizado real en Chrome:** los cuatro sonidos se generaron con `OfflineAudioContext`, con el mismo código y la misma salida que el reproductor:
- **Nivel:** sin saturación; las medidas están en §2.
- **Duración:** de ahí salió alargar la ventana de «Urgente» a 2,1 s, porque su cola se oye hasta 2,07 s.

**Capturas:**
- **Escritorio:** el panel muestra el selector, los ▶, el volumen y el aviso «sin voz en español». Chrome headless no trae voces, así que el aviso aparece como debe.
- **Móvil, a 390 px:** el panel mide 352 px y no hay desplazamiento horizontal. La captura en imagen quedó tapada por el chat de IRON, que se abrió con el clic simulado; las medidas sí se tomaron.

---

## 7. Lo que no se pudo verificar aquí

- **Cómo suenan en los altavoces del PC de recepción.** Las medidas dicen que no saturan y que están equilibrados, pero el volumen real depende del equipo.
- **La voz en Windows.** Depende de las voces instaladas:
  - Windows 10/11 en español trae «Sabina» (es-MX) y «Helena» (es-ES);
  - Edge añade voces «Natural» que suenan mucho mejor.
  - Si el panel dice «no tiene voz en español», se puede añadir en Configuración › Hora e idioma › Voz.
- **Que el primer clic desbloquee el audio en el Chrome real del PC.** Está probado con un AudioContext simulado; es el comportamiento documentado de Chrome.

---

## 8. Despliegue (17-10-2026, 20:56 UTC)

Solo el CRM: sin backend, migraciones ni reinicios.

1. **Commit** `3694ef6` en `feat/valoraciones-sonidos`. Su base, `d0f3f32`, era exactamente lo que servía producción, así que no se publicó trabajo ajeno pendiente.
2. **`npm run build:prod`**, con el sello `{"commit":"3694ef6","dirty":false}`. El código nuevo va en `chunk-BKKYUPEK.js`.
3. **Respaldo** de `index.html` y `version.json` publicados en `/var/backups/ironbody/crm-pre-sonidos-20261017T205633Z`.
4. **Publicación** con `rsync -a --no-owner --no-group` hacia `/var/www/app`, **sin `--delete`**, y después `chown -R root:root /var/www/app`.
5. **Verificación por la URL pública** `https://ironbodyneiva.cloud`:

| Comprobación | Resultado |
|---|---|
| `index.html` | 200, `no-cache`, apunta a `main-LEOX5IHK.js` |
| `version.json` | `3694ef6`, limpio |
| `main-LEOX5IHK.js` y `chunk-BKKYUPEK.js` | 200 |
| `main-EZOBUSJ6.js` (el anterior) | Sigue sirviéndose, 200: las pestañas abiertas no se rompen |
| `/trainers` | 200 |
| El chunk servido | Contiene las dos frases fijas de la voz y el panel nuevo |

**Pendiente: subirlo a GitHub.** `3694ef6` no está en `origin/main`. Si alguien publica el CRM desde `origin/main` sin este commit, los sonidos nuevos desaparecen sin aviso. Se sube con `git push origin feat/valoraciones-sonidos:main`, un avance rápido sobre `d0f3f32`, cuando se ordene.

**Reversión:**
- copiar los dos archivos del respaldo sobre `/var/www/app`;
- o republicar el build de `d0f3f32`. `main-EZOBUSJ6.js` y sus chunks siguen en disco.

No hay datos que deshacer: las preferencias nuevas viven en el navegador y el código anterior las ignora.

**Sigue sin verificar:**
- cómo suena en el PC de recepción;
- qué voz en español tiene ese Windows.

**Prueba en recepción:**
1. Recargar el CRM.
2. Alertas → Activar → probar los cuatro ▶.
3. Elegir sonido y volumen.
4. Recargar y hacer clic en cualquier sitio.
5. Esperar el próximo aviso de «valoración en 15 min». Se puede provocar con una cita de prueba, solo con autorización.

---

## 9. Cambio posterior: «Urgente» también habla

**Desplegado el 17-10-2026 a las 21:10 UTC** (16:10 en Bogotá), por orden expresa. Se añadió a petición del usuario, después del despliegue del §8.

| | |
|---|---|
| Commit del CRM | `d0bcc24`, sobre `3694ef6` |
| Publicado | `main-EVIMKLF3.js`; la frase vive en `chunk-5RGYTBBS.js` |
| Respaldo previo | `/var/backups/ironbody/crm-pre-voz-urgente-20261017T211033Z` |

Verificado en `https://ironbodyneiva.cloud`:
- `version.json` dice `d0bcc24`, limpio;
- el chunk servido contiene la frase;
- la versión anterior (`main-LEOX5IHK.js`) sigue disponible;
- `/trainers` responde 200.

Antes de publicar se probó en un Chrome real del Mac de desarrollo, con sus voces: sonaron las ráfagas y, a los 1,8 s, «Google español de Estados Unidos» dijo «¡Urgente! ¡Meidei!» a velocidad 0,8 y tono 1,25. Chrome publica las voces tarde y el reproductor esperó a que estuvieran.

**Reversión:** copiar los dos archivos del respaldo sobre `/var/www/app`. Así vuelve `3694ef6`.

- **La frase.** Tras las ráfagas (a 1,8 s), «Urgente» dice **«¡Urgente! ¡Meidei!»** para cualquier aviso. Es fija, como las demás: nunca lee el aviso.
  - Se escribe «Meidei» y no «Mayday» para que una voz en español lo pronuncie como *mayday*.
- **El alargamiento.** El efecto de «URGENTEEEEE MEIDEEEEEEIIII» se consigue hablando más lento (0,8) y más agudo (1,25), no repitiendo vocales: cada motor de voz lee las vocales repetidas a su manera, y algunos las deletrean.
- **Sin voz en español,** «Urgente» se queda en las ráfagas, y el panel lo avisa.
- **La ventana anti-solape** de «Urgente» sube a 4,3 s, para que otro aviso no corte la frase.
- **Pruebas:**
  - módulo de valoraciones: 174 de 174, con 4 pruebas nuevas o ampliadas;
  - suite completa: 1963 pasan y fallan los mismos 16 de la línea base;
  - zona horaria: 394 de 394;
  - build correcto;
  - mutación «Urgente sin voz»: detectada por 3 pruebas.

