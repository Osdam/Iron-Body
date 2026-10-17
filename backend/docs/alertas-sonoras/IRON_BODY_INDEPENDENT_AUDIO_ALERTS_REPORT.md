# Alertas sonoras independientes: notificaciones generales y valoraciones

**Estado: DESPLEGADO EN PRODUCCIÓN el 17-10-2026, 23:05–23:13 UTC** (18:05–18:13 en Bogotá), por orden expresa («despliega backend y CRM a producción y súbelo a GitHub»). Backend `84319be` y CRM `4334dd8`, también en `origin/main`. Sin migraciones ni envíos reales. Falta la prueba con una sesión real en el PC de recepción, así que **no** es PRODUCTION_VERIFIED (§21.1).
**Fecha:** 17-10-2026.

| Repositorio | Rama | Base |
|---|---|---|
| Repositorio | Rama | Base | Commits locales |
|---|---|---|---|
| `Iron-Body_Front` (CRM) | `feat/alertas-generales` | `origin/main` `d0bcc24`, que es lo publicado hoy | `4334dd8` |
| `Iron-Body` (backend) | `feat/alertas-generales` | `origin/main` `f6fcc06`; producción corre el código de `e2acfba` | `9dfe95f` y este informe |

Los commits están en el §19.

---

## Resumen

**Las notificaciones de la campanita ya pueden sonar.** Se configuran en **Configuración › Notificaciones › Alertas sonoras generales**:

- interruptor principal;
- cuatro sonidos propios: Normal, Importante, Urgente y Voz;
- volumen y silencio temporal;
- ajustes por categoría real;
- un aviso visual discreto.

**Las alertas de valoraciones siguen en su módulo**, con sus preferencias, sus sonidos y sus reglas. Lo único que comparten los dos módulos es un **coordinador de audio**:

- nunca suenan dos sonidos a la vez;
- el orden lo marca la urgencia real;
- las ráfagas suenan una vez;
- con varias pestañas abiertas suena una sola.

**En el backend se corrigen los dos riesgos ya conocidos**, imprescindibles ahora que un aviso «alto» suena en el personal:

- **P-1:** la campanita solo enseña a cada sesión lo que su rol puede ver.
- **P-2:** crear notificaciones a mano exige `content.manage`.

**Probado:**

| Prueba | Resultado |
|---|---|
| CRM: audio y valoraciones | 240 de 240 |
| CRM: suite completa | 2029 pasan; fallan los mismos 16 de la línea base |
| CRM: zona horaria | 394 de 394 |
| Backend: suite completa | 9121 pruebas, los mismos 58 fallos de la línea base, 0 nuevos |
| PostgreSQL 14 | 30 de 30 |
| **Chrome real** | **31 de 31** |
| Mutaciones | 11 de 11 en el CRM y 10 de 10 en el backend (§15) |
| Revisiones independientes | Seguridad de la API y revisión final, sin bloqueantes; lo importante, corregido (§24) |

---

## 1. Arquitectura encontrada

| Pieza | Dónde | Cómo funciona |
|---|---|---|
| **Campanita** | `shared/components/notifications-popover` (en el shell) | Al montarse llama a `startPolling(10000)`: canal SSE más relectura del contador cada 10 s. |
| **Servicio de notificaciones** | `shared/services/notifications.service.ts` | `received$` emite cada notificación del SSE. `queryRaw`, `markAsRead` y `markAllAsRead`. |
| **Canal en tiempo real** | `GET /api/admin/notifications/stream` | SSE de 20 s con sondeo de 1,5 s y vale de corta vida. Reconexión con `openStream`, que pide un vale nuevo. **No reconectaba con cursor:** lo creado en el hueco entre conexiones no llegaba por el canal. |
| **Evento** | `Notification::toPublicArray()` | `uuid` estable, `type`, `priority` (`low`, `medium`, `high`), `status` (`unread`, `read`), `action_type`, `metadata.source_event`. |
| **Tipos reales** | `createAdminNotification` | `payment`, `membership`, `class`, `routine`, `trainer`, `promotion`, `system`, `security` (8 sitios, sobre todo accesos y biometría), `iron_ai` y `valuation`. |
| **Leído / no leído** | — | Global: una persona marca y queda marcado para todos. Es una limitación conocida, no se cambia. |
| **Permisos de lectura** | — | Solo las filas con `required_permission` (las de valoraciones) se filtraban. **El resto era global (P-1).** |
| **Configuración › Notificaciones** | `settings-notifications.ts` | Maqueta estática de canales y plantillas, sin persistencia. La ruta `/settings` exigía `security.view`, `integrations.view`, `roles.manage` o `audit.view`: **Recepción no podía entrar**. |
| **Alertas de valoraciones** | `modules/valuations` | `ValuationAlertService` y `ValuationSoundPlayer`. Preferencias por usuario en `localStorage`, «ya sonado» por navegador, primer gesto para desbloquear y ventana propia (`sonandoHasta`). Sonaban en **todas** las pestañas, con un `localStorage` compartido como único freno. |
| **Sesión** | `AuthService` | Va por pestaña en `sessionStorage`; con «Recordarme», también en `localStorage`. Dos pestañas pueden ser de personas distintas. |
| **Seguridad: P-2** | `POST /api/admin/notifications` | `SELF`: bastaba **cualquier** sesión. Ninguna pantalla lo usa, y el token compartido de automatizaciones tampoco puede (SELF lo excluye). |

## 2. Arquitectura implementada

```
                          ┌─────────── NotificationsService.received$ (SSE, con cursor) ───────────┐
                          │                                                                         │
             ┌────────────▼─────────────┐                                         ┌─────────────────▼───────────┐
             │ GeneralAlertService       │  prefs: ironbody.generalAlerts.prefs.<id>│ ValuationAlertService       │ prefs: ironbody.valuationAlerts.prefs.<id>
             │ (campanita, sin valuation)│  sonado: ironbody.generalAlerts.played   │ (sin cambios de reglas)     │ sonado: ironbody.valuationAlerts.played
             │ GeneralSoundPlayer        │                                          │ ValuationSoundPlayer        │
             └────────────┬─────────────┘                                          └─────────────────┬───────────┘
                          │ claimTab() → request({key, source, priority, durationMs, start, stop, stillWanted})
                          └───────────────────────────►  AudioPlaybackCoordinator  ◄───────────────────┘
                                                      (cola + Web Locks; nada de negocio)
```

- **Dos módulos, dos reproductores y dos `AudioContext`.** Ninguno importa preferencias del otro.
- **El coordinador solo conoce** «módulo», «prioridad», «cuánto dura» y «cómo empiezo y paro».
- **Todo el código de audio es diferido**, en chunks aparte. El bundle inicial crece 0,5 kB, por el `import()` del aviso en el shell y el cursor del canal.

## 3. Sistema de notificaciones generales

`shared/audio/general-alerts.service.ts`. Un aviso suena solo si cumple todo esto:

1. **Llega NUEVO por el canal en tiempo real.** Nunca por la lista, el polling, el historial o al abrir la campanita.
2. **No está leído y tiene `uuid`.**
3. **No es de valoraciones:** ni `type=valuation` ni `action_type=valuation_detail`. Se decide con datos estructurados, nunca con el texto.
4. **Su categoría (`type`) está activada,** y su prioridad alcanza el mínimo de esa categoría.
5. **Se creó hace menos de 5 min,** para que reconexiones y relojes desfasados no traigan sonidos viejos.
6. **No está silenciado temporalmente** para que suene. El aviso visual sale igual.
7. **Esta pestaña es la que suena** (§11) y ese `uuid` no sonó antes en este navegador.
8. **No está dentro de la ventana de ráfaga del módulo:** la duración del sonido más 2,5 s, salvo que sea más urgente.

**Categorías (las reales), con sus valores por defecto:**

| Categoría | Suena por defecto | Desde prioridad |
|---|---|---|
| Pagos | Sí | Todas |
| Membresías | Sí | Media |
| Clases | Sí | Media |
| Seguridad y accesos | Sí, con «Importante» | Solo alta |
| Sistema y avisos administrativos | Sí | Media |
| Rutinas | No | Media |
| Entrenadores | No | Media |
| Promociones y eventos | No | Media |
| IRON IA | No | Media |
| Otros avisos | No | Media |

**El interruptor principal nace apagado.** Para activarlo hace falta un gesto de la persona, que es lo que desbloquea el audio.

## 4. Integración con la campanita

- **La campanita no cambia.** Mismo servicio, mismo contador, misma lista.
- **Mejora del canal SSE:**
  - el backend anuncia su cursor al abrir (evento `ready`);
  - `NotificationsService` lo devuelve como `after_id` al reconectar;
  - si el último contacto tiene más de 2 min, empieza de cero en vez de volcar el historial.
  - **Compatibilidad:** con el backend viejo, el CRM ignora la falta de `ready`; con el CRM viejo, el backend nuevo no rompe nada (es un evento que nadie escucha).
- **«Ver» del aviso visual** usa la misma tabla de rutas que la campanita. `routeFor` se extrajo a `notification-routes.ts` sin cambiar ningún destino.

## 5. Configuración desde el CRM

**Configuración › Notificaciones › «Alertas sonoras generales»** (`settings-sound-alerts.ts`):

- **Interruptor principal** con su estado: Activados, Silenciados hasta las HH:MM (hora de Bogotá), Bloqueados por el navegador o Desactivados.
- **Tipo de sonido:** cuatro tarjetas, cada una con su ▶ para probarla.
- **Volumen** de 0 a 100 %; al soltar suena una muestra.
- **Silenciar temporalmente:** 15 min, 1 h o 4 h, y «Reactivar». Las notificaciones siguen llegando y el aviso visual también.
- **Por categoría:** si suena, desde qué prioridad, qué sonido («General» o uno propio), qué volumen («General», 25, 50, 75 o 100 %) y ▶.
- **No hay nada de valoraciones aquí.** El texto remite a su módulo.

**Acceso a Configuración.** Es un cambio deliberado, para que la recepción configure el sonido de los pagos:

| Quién | Qué ve |
|---|---|
| Quien tiene alguno de `security.view`, `integrations.view`, `roles.manage` o `audit.view` | Todo, como antes |
| El resto | Solo la pestaña Notificaciones, y dentro solo sus alertas sonoras |

- **El menú lateral enseña «Configuración» a todos.**
- **El backend no cambia:** las demás pestañas siguen protegidas en el servidor.
- **Ficheros:** `settings-access.ts`, `settings.ts`, `settings-notifications.ts`, `app.routes.ts` y `app.html`.

## 6. Independencia de valoraciones

**Qué cambió en el módulo de valoraciones**, solo la adaptación mínima para coordinar:

1. **Pide turno:** `claimTab()` y `coordinator.request(...)` en lugar de `player.play(...)`.
   - Prioridad `critical` para «sin iniciar» y `high` para «en 15 min».
   - Sus sonidos, sus preferencias, su ventana `sonandoHasta`, su lista de «ya sonado» y sus frases no cambian.
2. **«Probar» va por `coordinator.preview`:** corta lo que suene en vez de superponerse.
3. **Silenciar o desactivar** además llama a `coordinator.cancelSource('valuations')`.
4. **Relee sus preferencias** cuando otra pestaña las cambia (evento `storage`). Se necesita porque ahora suena solo la pestaña líder, que debe obedecer lo que la persona eligió en otra.
5. **`ValuationSoundPlayer`: tope a `ctx.resume()`.** Chrome, sin gesto, deja esa promesa pendiente para siempre. Por eso el aviso «El navegador bloqueó el sonido» nunca aparecía y el turno quedaba 3 s colgado. Era un **fallo previo**, encontrado en el E2E real.
   - El tope es de 300 ms si la página aún no tuvo ningún gesto, y de 1,5 s si ya lo tuvo, porque un dispositivo Bluetooth o USB puede tardar en arrancar.

**Diferencia observable:**
- Un aviso de valoración puede entrar hasta 150 ms después (el turno agrupa lo simultáneo).
- Si llega mientras suena «Probar» u otro sonido, **espera** a que termine en vez de cortarlo.

**Pruebas de valoraciones:** todas pasan (174 más 2 nuevas). Se ajustó solo el ritmo de algunas:
- `turno()` espera los 150 ms;
- `libre()` deja terminar la prueba de «Activar» antes de enviar el aviso;
- en la de la voz, el segundo aviso espera su turno.

No se relajó ninguna aserción de comportamiento. «Silenciar corta» pasó de «exactamente 1 `stop`» a «al menos 1», porque ahora también para el coordinador.

**Escenarios de independencia, en unitarias y en Chrome real:**

| Escenario | Resultado |
|---|---|
| Generales OFF, valoraciones ON | Suena solo la valoración |
| Generales ON, valoraciones OFF | Suena solo el pago; la general no «adopta» la valoración |
| Las dos ON | Suenan las dos, en orden, sin solaparse |
| Las dos OFF | No hay audio; la bandeja sigue igual |
| Desactivar o silenciar uno | No toca las claves ni el sonido del otro |
| Una valoración en la campanita | Ni suena ni aparece como aviso general |

## 7. Coordinador de audio (`shared/audio/audio-playback-coordinator.ts`)

**Solo ordena reproducciones.** No sabe de citas, pagos ni preferencias.

- **API:**
  - `claimTab()`
  - `request({ key, source, priority, durationMs, start, stop?, stillWanted? })`, que devuelve `played`, `blocked`, `duplicate`, `coalesced`, `expired`, `other-tab`, `cancelled` o `interrupted`
  - `preview(source, start, durationMs, stop?)`
  - `cancelSource(source)`
- **Un sonido a la vez.** Espera `durationMs` y, si hay voz, a que calle (`speaking`/`pending`, tope de 8 s), más un hueco de 250 ms.
- **Cortar corta de verdad:** silenciar, cerrar sesión o «Probar» paran el sonido también si aún está arrancando o esperando los altavoces de otra pestaña. La espera se aborta y se vuelve a comprobar antes y después de `start()`. Esto lo encontró la revisión final y quedó corregido con pruebas de regresión.
- **Sin bloqueos:**
  - lo que espera más de 15 s caduca;
  - un `start()` que no responde en 3 s cuenta como fallido;
  - el candado de salida espera como mucho 10 s;
  - `stillWanted()` se pregunta justo antes de sonar (silenciado, otra sesión).
- **Libera recursos:** cada reproductor desconecta su bus al parar. Al cerrar sesión se cancela lo pendiente y se corta lo que suena.

## 8. Política de prioridades

| Prioridad | Qué entra |
|---|---|
| Crítica | Valoración «sin iniciar»; aviso de seguridad con prioridad alta |
| Alta | Valoración «en 15 min»; cualquier aviso general con prioridad alta |
| Normal | Aviso general con prioridad media |
| Baja | Aviso general con prioridad baja |

- **A igual prioridad gana el que llegó antes.** Una valoración no gana por ser valoración: una membresía alta que llega antes suena antes (probado).

## 9. Manejo de simultaneidad

- **Ventana de 150 ms:** lo que llega a la vez compite por prioridad.
- **Un pendiente por módulo.** Uno más urgente sustituye al que esperaba, conservando su propia hora de llegada.
- **Lo que el mismo módulo pide mientras suena,** con igual o menor prioridad, se agrupa. Cada módulo tiene además su ventana propia.
- **Resultado:**
  - pago + valoración = 2 sonidos en orden;
  - 3 generales = 1;
  - 2 valoraciones = 1;
  - 5 a la vez (3 generales y 2 valoraciones) = 2.
  - Todos siguen en la campanita y, en su caso, en la tarjeta de valoraciones.
- **Nunca se corta una voz por otro aviso.** Solo «Probar», que es un gesto de la persona, corta lo que suena en su pestaña.

## 10. Prevención de duplicados

**Cada aviso suena una vez, por `uuid` estable:**
- lista de «ya sonado» por navegador y módulo (`localStorage`, 300 y 200 entradas);
- clave en memoria en el coordinador.

**No vuelve a sonar al:**
- abrir la campanita;
- recargar el CRM;
- consultar el historial o cambiar de módulo;
- repetirse el polling o recibir dos veces el mismo evento;
- reconectar el canal: el cursor evita huecos, y lo de más de 5 min (general) o 30 min (valoraciones) no suena.

**Al iniciar sesión no suena el historial,** porque el canal empieza en «lo último». Verificado en Chrome real al recargar.

## 11. Coordinación entre pestañas

**Web Locks**, mismo origen; soportado por Chrome y Edge, los navegadores de recepción:

- **Candado de líder por persona** (`ironbody.audio.leader.<id>`).
  - Lo pide la pestaña al primer clic o tecla, primero con «si está libre, ya» (sin ventana en la que no lo tenga nadie), y lo suelta al cerrarse.
  - El navegador pasa el relevo a la siguiente que lo pidió.
  - Sin líder (nadie hizo clic tras recargar), la primera pestaña que intenta sonar lo toma al vuelo. Si el navegador no la deja sonar y no tuvo gesto, lo suelta para otra.
- **Solo la líder apunta un `uuid` como sonado.** Si lo apuntara otra que no suena, la líder lo daría por hecho.
- **Candado global de salida** (`ironbody.audio.output`): ni dos cuentas en dos pestañas suenan a la vez.
- **Las preferencias se sincronizan entre pestañas** con el evento `storage`. Este fallo lo encontró el E2E real.
- **Los candados solo llevan un nombre,** sin avisos ni datos.
- **BroadcastChannel se evaluó y no hace falta:** Web Locks ya da exclusión y relevo, y el evento `storage` sincroniza preferencias.
- **Sin Web Locks** (navegador antiguo), cada pestaña suena por su cuenta y vale la lista de «ya sonado».

**Casos que cubre:**

| Caso | Comportamiento |
|---|---|
| Pestaña cerrada | Relevo |
| Recarga | Documento nuevo: líder al primer gesto |
| Cambio de sesión | Suelta el candado y pide el del nuevo usuario |
| Pestaña en segundo plano | Suena desde el fondo |
| Pérdida de red | El canal reconecta con cursor |

**Pestaña suspendida:** Chrome no congela una pestaña que tiene un Web Lock. Es comportamiento documentado de Chrome, no probado aquí.

## 12. Preferencias de usuario

| | Generales | Valoraciones |
|---|---|---|
| Preferencias por persona | `ironbody.generalAlerts.prefs.<id>` | `ironbody.valuationAlerts.prefs.<id>` |
| «Ya sonado», por navegador | `ironbody.generalAlerts.played` | `ironbody.valuationAlerts.played` |

- **Guardan:** interruptor, sonido, volumen, silencio (hasta qué hora) y, por categoría, si suena, desde qué prioridad, sonido y volumen.
- **Se validan campo a campo:** lo corrupto vuelve a su valor por defecto.
- **No llevan datos sensibles**, solo ajustes.
- **Viven en el navegador, no en el servidor.** Es la misma decisión que se tomó para valoraciones: si la persona cambia de PC, allí empieza con los valores por defecto.

## 13. Seguridad y permisos

| Hallazgo | Estado antes | Ahora |
|---|---|---|
| **P-1.** La campanita era global para lo que no tiene `required_permission` | Confirmado: Administrativo veía pagos | `applyVisibility()` oculta `payment`, `membership`, `class`, `routine` y `trainer` a quien no tenga `payments.view`, `members.view`, `classes.view`, `routines.view` o `trainers.view`. Se aplica igual en la lista, el contador, la búsqueda, la categoría, el stream, `markRead` (404) y `read-all`. Manda el permiso efectivo, también si se cambia en Roles. |
| **P-2.** `POST /api/admin/notifications` abierto a cualquier sesión | Confirmado: Recepción podía crear un «Pago aprobado» alto y difundir a la app de todos los socios | Exige `content.manage`. Lo tienen Super Admin y Administrador; a otros se concede en Roles. Un 403 no escribe nada. |
| Aviso de seguridad fabricado | Posible, con prioridad alta y crítica en el coordinador | `security` queda reservado al sistema, igual que `valuation` (422). |
| Origen de una manual | No se distinguía | `metadata: {source: 'manual', created_by_admin_id}` en las internas. A la app del socio no viaja. |
| Tipos creables a mano (revisión B-2) | Lista de prohibidos: con `content.manage` se podía fabricar un `payment` alto que sonaba en recepción | **Lista de permitidos:** a mano solo `system` y `promotion`, normalizados a minúsculas |
| Formulario público del login (revisión M-1) | Sin sesión, creaba un `security` alto (crítico en el coordinador) y un `system` alto, visibles para todos: cualquiera podía hacer sonar «crítico», 6 veces por minuto por IP | Esos dos avisos llevan `metadata.origin = 'public'`. El CRM les da prioridad sonora baja, así que por defecto ni suenan ni salen como aviso visual; en la campanita siguen igual |
| Cursor del stream (revisión B-1) | El `ready` anunciaba el último id global y dejaba contar lo que la sesión no ve | El cursor es la última fila **visible** para esa sesión |

**Qué cambia para cada rol en la campanita**, con los permisos por defecto:

| Rol | Deja de ver |
|---|---|
| Super Admin y Administrador | Nada |
| Recepción | Rutinas y Entrenadores |
| Entrenador | Pagos |
| Administrativo | Pagos, Membresías, Clases, Rutinas y Entrenadores; sigue viendo Sistema, Seguridad y Promociones |
| Token de automatizaciones | Nada (permisos de lectura); además no puede usar estas rutas |

**Sin cambios:**
- `system`, `security`, `promotion`, `iron_ai` y los demás tipos los siguen viendo todos;
- el leído/no leído sigue siendo global.

**Tres puertas en el cliente** (el servidor ya filtra):
1. el aviso tiene que llegar por el canal de esta sesión;
2. pasar las categorías de la persona;
3. no ser de valoraciones.

**Verificado contra el servidor real** (Laravel con PostgreSQL, `curl`):

| Comprobación | Resultado |
|---|---|
| Recepción crea un aviso a mano | 403 |
| Recepción difunde a todos los socios | 403 |
| Super Admin crea un aviso interno | 201 |
| Recepción ve | `membership`, `payment`, `system` (no `routine`) |
| Administrativo ve | solo `system` |
| Stream al abrir | emite `event: ready` con `{"cursor":4}` |

## 14. Alertas con voz

| Módulo | Frases fijas |
|---|---|
| Generales | «Atención, tienes una notificación importante.» (prioridad alta); «Atención, revisa las notificaciones del sistema.» (el resto) |
| Valoraciones | Sin cambios |

- **Nunca leen el título ni el mensaje:** ni nombres, documentos, teléfonos ni importes. Probado con un aviso que contiene «Laura Gómez (CC 1075…) $120.000».
- **Un aviso público** (formulario sin sesión) nunca usa la frase «importante».
- **Eligen voz en español,** preferentemente latinoamericana o neuronal.
- **Sin voz en español,** «Voz» suena como «Importante», y el panel lo avisa.
- **En Chrome real** dijo exactamente «Atención, tienes una notificación importante.» con la voz Paulina (es-MX).

## 15. Pruebas automatizadas

**CRM (vitest):**

| Suite | Resultado | Qué cubre |
|---|---|---|
| `audio-playback-coordinator.spec.ts` | 23 | Prioridades (con «la valoración no gana siempre»); pago + valoración; tres generales; cinco a la vez; dos valoraciones; agrupado mientras suena; duplicados; voz sin cortar y con tope; «Probar» corta; `start` colgado; caducidad; `stillWanted`; `cancelSource`; logout; sin Web Locks; dos y cuatro pestañas; relevo al cerrar; dos cuentas sin solaparse; líder bloqueada cede. Además, los cuatro casos de la revisión: cortar durante el arranque, «Probar» durante el arranque, cortar esperando los altavoces de otra pestaña y la pestaña líder justo tras el gesto. El `LockManager` de prueba concede de forma asíncrona, como el navegador |
| `general-sounds.spec.ts` | 12 | Cuatro sonidos cerrados y sin ganancias mayores que 1; distintos de los de valoraciones nota a nota; frases fijas; volumen; voz y respaldo; bloqueado; `resume` colgado |
| `general-alerts.spec.ts` | 32 | Básicos; valoración excluida por datos; categoría y prioridad; aviso público sin urgencia; historial; silencio temporal; ráfaga simultánea y espaciada; voz sin datos; sesión; preferencias corruptas; seis escenarios de independencia; preferencias entre pestañas; aviso visual; Configuración (UI completa, bloqueado, quién ve qué, la página real con `?tab=security` sin permisos); rutas; cursor SSE |
| Valoraciones | 176 | Las de siempre, más el `resume` colgado en su reproductor. «Silenciar corta» comprueba exactamente 2 `stop`: coordinador y reproductor |
| **Suite completa** | **2029 pasan, 16 fallan** | Los 16 son los mismos de la línea base (moderación y tiempo real), nombre a nombre |
| `test:agenda-tz` | 394 de 394 | |
| `check:literals` | Correcto | |
| Build de producción | Correcto | Avisos de presupuesto previos |

**Backend (PHPUnit):**

| Suite | Resultado |
|---|---|
| `AdminNotificationVisibilityTest` (nueva) | 11: visibilidad por rol; contador, búsqueda y categoría; marcar y marcar todas; stream; cursor visible; token compartido con 403 por HTTP en las 6 rutas y sin escrituras; permiso efectivo; P-2 por rol; origen manual; lista de permitidos; formulario público marcado |
| `ValuationNotificationsTest` | Ajustada: su aviso «de siempre» pasa de `payment` a `system`, porque prueba `required_permission`; el `POST` manual de entrenador y recepción ahora espera 403 (P-2), y un tipo libre, 422 |
| Flujos de soporte | 76 de 76: `SecurityAccessRecovery`, `SupportTicketActions`, `DeviceConflictRelease`, `MemberPhoneTrail` y `MemberOwnershipLink` |
| PostgreSQL 14 | 30 de 30, con el rol `iron` no superusuario: visibilidad, valoraciones, `NotificationAuthTest` y `StreamTicketTest` |
| Suite completa (SQLite) | 9121 tras las correcciones: 55 errores y 3 fallos, los mismos 58 nombres de la línea base guardada; 0 nuevos |

La línea base fresca del mismo día, en un worktree temporal, dio 198 fallos por diferencias de entorno y no es comparable.

**Mutaciones** (se rompe el código a propósito y alguna prueba debe fallar):

| Repositorio | Mutaciones detectadas |
|---|---|
| Backend: 10 de 10 | `POST` vuelve a SELF; sin filtro por tipo; filtro inerte; `security` fabricable; pagos con el permiso equivocado; sin marca de origen; sin lista de permitidos; cursor global; reporte público sin marca; ticket público sin marca |
| CRM: 11 de 11 | La general «adopta» valoraciones; sin agrupar mientras suena; sin prioridad; no corta tras arrancar; todas las pestañas suenan; sin sincronizar pestañas; aviso público con urgencia; `resume` sin tope; valoraciones sin turno de pestaña; Configuración entera para todos; sin ventana de ráfaga general |

La ventana de ráfaga general sobrevivió al primer intento: se añadió la prueba de «ráfaga espaciada», que la detecta.

**Incidente durante las mutaciones (corregido):** la primera tanda del CRM no llegó a crear las copias de seguridad (un fallo del script en zsh). Las once mutaciones se revirtieron a mano, una por una, sobre el patrón exacto. Se verificó:
- que no quedaba ningún resto;
- que pasaban las 239 pruebas;
- que pasaba el E2E completo.

Después se repitieron con un script en bash que verifica cada copia y cada restauración byte a byte.

## 16. Pruebas en navegador real

**Montaje:**
- Chrome 155.0.8059.80 headless, real: WebAudio, Web Locks y SpeechSynthesis sin simular.
- Laravel real en `:8080`, con PostgreSQL desechable y 10 workers para el SSE.
- CRM de desarrollo en `:4200`.
- Avisos sintéticos creados con el `NotificationService` real, solo en la base local.
- Se observa el audio envolviendo `start`/`stop` y `speak`, sin sustituirlos.
- Guion: `scratchpad/e2e-alertas/browser.cjs`. Capturas: `scratchpad/e2e-alertas/shots/`.

**Resultado: 31 de 31.**
- Además de los escenarios siguientes, la tabla de categorías no se desborda a 1280, 1100 ni 390 px.
- El aviso general queda abajo a la izquierda del contenido, sin tapar la tarjeta de valoraciones.
- Recepción ve «Configuración», solo la pestaña Notificaciones y sin plantillas.
- Activar suena la prueba; estado «Activados».
- Probar cada sonido: nunca dos a la vez.
- Cada módulo, su clave.
- Pago + valoración:
  - dos sonidos de contextos distintos;
  - sin solaparse (valoración entre 1979 y 4090 ms, pago entre 4543 y 5213 ms);
  - primero la valoración;
  - aviso visual y tarjeta a la vez.
- Cinco a la vez: dos sonidos sin solaparse.
- Cuatro pestañas y un pago: suena una sola (1, 0, 0, 0) y el aviso visual sale en las cuatro.
- Al cerrar la que sonaba, suena otra (1, 0, 0).
- Recargar no hace sonar el historial.
- Generales OFF / valoraciones ON y al revés, con preferencias cambiadas en otra pestaña.
- La voz dice solo su frase fija, sin datos.
- Sin gesto tras recargar, el audio está suspendido:
  - no suena;
  - el aviso visual sigue;
  - el panel dice «bloqueado»;
  - tras un clic, el siguiente suena.
- Ningún error de JavaScript.

**Ejecución con la suite del backend en paralelo:** dos comprobaciones de varias pestañas fallaron. Las pestañas recién abiertas tardaban en conectar su canal y el aviso se creaba antes (por diseño, el canal empieza en «lo último»). Aislado funcionaba (cuatro de cuatro). El guion ahora manda un aviso de calentamiento y espera a verlo en todas antes de medir.

**Tres defectos reales que encontró el E2E y que ya están corregidos:**
1. **Preferencias desincronizadas entre pestañas:** la líder obedecía lo que tenía en memoria.
2. **`resume()` sin tope:** el estado «bloqueado» nunca se mostraba, en los dos módulos.
3. **Instrumento engañoso:** `page.evaluate` de puppeteer da activación de usuario. El escenario «sin gesto» se lee por CDP con `userGesture:false`; queda anotado en el guion.

**Limitaciones:**
- No se oyó en altavoces: va con `--mute-audio`. Se midió el grafo real, no el sonido de la sala.
- No se probó en Windows ni con las voces del PC de recepción.
- No se probó una caída de red real, solo el cursor (unitarias y `curl`).

## 17. Regresiones

**Sin fallos nuevos en:** calendario, agendamiento, recordatorios, campanita, roles, pagos, caja, membresías y clases. Las dos suites completas lo confirman.

**No se tocaron:**
- Firebase y los push móviles;
- Meta y WhatsApp;
- Factus, caja, inventario y contratos;
- EmployeeAccess, LocalGym, biometría e impresión;
- las migraciones (no hay ninguna).

**Cambios de comportamiento deliberados, que hay que conocer:**
- **P-1:** qué ve cada rol en la campanita (§13).
- **P-2:** quién crea avisos a mano.
- **Configuración** abierta a todos, con solo Notificaciones para quien no administra.
- **Valoraciones:** pueden entrar hasta 150 ms después y esperan su turno en vez de cortar lo que suena. Con varias pestañas suena una.

## 18. Archivos modificados

**CRM:**

| Tipo | Archivos |
|---|---|
| Nuevos | `shared/audio/audio-playback-coordinator.ts`, `general-alerts.service.ts`, `general-sound-player.ts`, `general-sounds.ts`, `general-alert-toast.ts`, `audio.testing.ts` y sus 3 specs; `shared/services/notification-routes.ts`; `modules/components/settings-sound-alerts.ts`; `modules/settings-access.ts` |
| Modificados | `app.ts`, `app.html`, `app.routes.ts`, `modules/settings.ts`, `modules/components/settings-notifications.ts`, `shared/services/notifications.service.ts`, `notifications-popover.component.ts`; en valoraciones: `valuation-alert.service.ts`, `valuation-sound-player.ts`, `valuation-audio.testing.ts`, `valuation-alert.service.spec.ts`, `valuation-sounds.spec.ts` |

**Backend:**

| Tipo | Archivos |
|---|---|
| Modificados | `app/Http/Controllers/Crm/NotificationController.php`, `app/Support/Access/AuthorizationMap.php`, `tests/Feature/Valuations/ValuationNotificationsTest.php` |
| Nuevos | `tests/Feature/Notifications/AdminNotificationVisibilityTest.php` y este informe |

## 19. Commits generados

Subidos a `origin/main` el 17-10-2026 con el despliegue:

| Repositorio | Commit | Contenido |
|---|---|---|
| Backend `feat/alertas-generales` | `9dfe95f` | `feat(notificaciones)`: la campanita respeta los permisos de cada rol y crear avisos a mano exige `content.manage` |
| Backend `feat/alertas-generales` | `84319be` | `docs(alertas)`: este informe, más la actualización con el despliegue |
| CRM `feat/alertas-generales` | `4334dd8` | `feat(alertas)`: alertas sonoras de la campanita en Configuración y coordinador de audio compartido con valoraciones |

## 20. Riesgos pendientes y decisiones del propietario

**De la revisión de seguridad.** Son previos a este trabajo y quedan para tu decisión:

| Hallazgo | Qué pasa | Propuesta |
|---|---|---|
| **M-2** | El Entrenador con `members.view` ve en la campanita avisos de membresías y clases de socios que no tiene asignados. Además, `system` y `security` siguen visibles para todos los roles, con documento, teléfono, IP o eventos biométricos en su `metadata`. Hoy ninguna cuenta del CRM es de entrenador. | Aplicar el alcance del entrenador a las filas con socio; proteger `security` con un permiso; quitar datos personales de la `metadata` a quien no ve socios |
| **M-3** | En el `POST` manual, un documento que no existe convierte el mensaje en difusión a todos los socios (unos 3.900). Desde P-2 solo puede pasarle a quien tenga `content.manage`. | 422 si no se resuelve; exigir `broadcast: true` explícito |
| **M-4** | En la app, un socio puede borrar una difusión para todos, y su «leído» la marca para todos. Es del lado de la app, fuera de este alcance. | Leído y oculto por socio, o 404 al borrar filas globales |
| **B-3** | El `POST` manual no deja rastro en `AuditTrail`. | Registrar autor y destino |
| **B-4** | El leído es global, conocido de antes. | Leído por persona |
| **Mensaje del formulario público** | Incluye el nombre que escribe el solicitante. Ya no suena ni sale como aviso, pero sigue en la campanita. | No interpolar el nombre |

**Despliegue:**
- **Orden:** backend antes o a la vez que el CRM. El CRM nuevo con el backend viejo dejaría que cualquier sesión activara «Pagos» y recibiera, visible y sonoro, lo que P-1 debe ocultar.
- **Si se revierte solo el backend,** reaparece el problema; conviene revertir también el CRM (§22).

**No verificado aquí:**
- altavoces y voces del PC de recepción;
- si Chrome congela una pestaña en segundo plano que tiene el candado de líder (la documentación dice que no);
- tiempos reales de `resume()` con audio Bluetooth o USB;
- una caída de red real.

**Comportamientos que conviene conocer:**
- Las preferencias viven en el navegador.
- El leído/no leído sigue siendo global.
- Los cambios en valoraciones del §6: hasta 150 ms de retardo, esperan su turno en vez de cortar, suena solo la pestaña líder, y las preferencias se sincronizan entre pestañas (es nuevo).

## 21. Plan de despliegue (cuando se autorice)

**Orden: backend primero.** El CRM nuevo funciona con el backend viejo, y al revés también.

1. **Backup:**
   - `pg_dump` de producción, como en los despliegues anteriores;
   - copia de `/var/www/app/index.html` y `version.json`.
2. **Backend:**
   - `git fetch` y `merge --ff-only` de `feat/alertas-generales` en `/var/www/api`;
   - **sin migraciones**;
   - OPcache revalida en 2 s;
   - `php artisan queue:restart` y comprobar los 12 workers.
3. **CRM:**
   - `npm run build:prod` (sello limpio);
   - `rsync -a --no-owner --no-group` **sin `--delete`** a `/var/www/app`;
   - `chown -R root:root`;
   - comprobar que `index.html` apunta al `main` nuevo y que el anterior sigue en disco.
4. **Push a `main`** de los dos repositorios, como avance rápido.

### 21.1 Despliegue ejecutado (17-10-2026)

1. **Comprobación previa.** `origin/main` no se había movido: backend en `f6fcc06`, CRM en `d0bcc24`; las dos ramas avanzaban en línea recta. La API corría `ca5064c` y el CRM publicado era `d0bcc24`.
2. **Respaldo** en `/var/backups/ironbody/pre-alertas-generales-20261017T230513Z`:
   - volcado completo de la base `ironbody` (30,6 MB, 204 tablas con datos);
   - `/var/www/app` empaquetado;
   - `version.json` y la cabeza de la API;
   - sumas SHA256.
   - El primer volcado salió vacío, porque el nombre de la base no estaba donde lo busqué. Se repitió sobre `ironbody`, la correcta.
3. **Backend:**
   - push a `main` (`f6fcc06..84319be`);
   - en el servidor, `git fetch` y `merge --ff-only`: la API pasa a `84319be`;
   - `php -l` de los tres ficheros: correcto;
   - **sin migraciones**;
   - `php artisan queue:restart`: 12 workers en marcha.
4. **Comprobado en producción:**
   - `AuthorizationMap` resuelve `POST /api/admin/notifications` como `content.manage` y la lista como `@self`;
   - las cuatro rutas de la campanita, sin sesión, dan 401.
5. **CRM:**
   - push a `main` (`d0bcc24..4334dd8`);
   - `npm run build:prod`, con el sello `4334dd8` limpio;
   - `rsync -a --no-owner --no-group` **sin `--delete`** y `chown -R root:root`.
6. **Verificación pública** en `https://ironbodyneiva.cloud`:
   - `index.html` (`no-cache`) apunta a `main-VR37CLFV.js` y `version.json` dice `4334dd8`;
   - el `main` anterior (`main-EVIMKLF3.js`) sigue en 200;
   - `/settings` y `/trainers` en 200;
   - los chunks servidos contienen las preferencias generales, el candado del coordinador, la sección de Configuración, la frase de la voz y el cursor `after_id`.
7. **Salud a las 23:13 UTC:**
   - 0 errores en el log de Laravel desde las 23:00;
   - 0 respuestas 5xx en las últimas 3000 de nginx;
   - las rutas de la campanita, 200 para las sesiones abiertas;
   - 12 workers en marcha.

**Sin verificar en producción:**
- el evento `ready` del stream y el filtro por rol con una sesión real (no se usaron credenciales de producción);
- el sonido en el PC de recepción.

Queda para la checklist del §23.

## 22. Rollback

| Qué revertir | Cómo |
|---|---|
| **CRM** | Copiar el `index.html` y `version.json` respaldados sobre `/var/www/app`; los chunks viejos siguen en disco. No hay datos que deshacer: las preferencias nuevas viven en el navegador y el código anterior las ignora. |
| **Backend** | Volver al commit anterior con `merge --ff-only` del anterior, o un `revert` publicado. Sin migraciones ni datos nuevos. Las notificaciones manuales creadas mientras tanto solo llevan `metadata` extra, inocua. |

**Revertir solo el backend** reabre P-1 y P-2: las alertas sonoras generales sonarían para quien vuelve a ver todos los pagos. Si hay que revertir el backend, conviene revertir también el CRM.

## 23. Checklist de activación

**Tras desplegar:**
- [ ] **Smoke técnico:** `version.json` del CRM, `/trainers` y `/settings` en 200.
- [ ] **`curl` con una sesión de recepción:**
  - `POST /api/admin/notifications` da 403;
  - el stream emite `ready`.
- [ ] **Recepción, en su PC:**
  1. Configuración › Notificaciones › activar;
  2. probar los cuatro ▶;
  3. elegir sonido y volumen;
  4. revisar las categorías.
- [ ] **Valoraciones:** Entrenadores › Calendario de citas › Alertas siguen como estaban.
- [ ] **Un pago real del día:** suena una vez, con aviso visual y en una sola pestaña.
- [ ] **Si aparece «no tiene voz en español»:** instalar la voz en Windows (Configuración › Hora e idioma › Voz) o no usar «Voz».
- [ ] **Administrativo:** ya no ve pagos ni membresías en la campanita (P-1).

## 24. Revisión independiente

### Seguridad de la API (`api-security-specialist`)

**Veredicto: sin bloqueantes.** P-1, P-2 y `ready` cumplen lo prometido, también en PostgreSQL 14.24. El token compartido recibe 403 en las 6 rutas.

| Hallazgo | Estado |
|---|---|
| M-1: avisos «alta» desde el formulario público | **Corregido** (origen público, §13) |
| B-1: el cursor revelaba el máximo global | **Corregido** (cursor visible) |
| B-2: lista de prohibidos | **Corregido** (lista de permitidos) |
| B-5: prueba de un camino inalcanzable | **Corregido** (pruebas HTTP del token) |
| M-2, M-3, M-4, B-3, B-4 | Previos; **decisión del propietario** (§20) |

### Revisión final (`reviewer`)

**Veredicto: aprobado con observaciones, sin bloqueantes.** Lo verificó con un arnés propio sobre el coordinador real y Web Locks nativos de Node 24.

| Observación | Estado |
|---|---|
| 1. Cortar no cortaba durante el arranque o la espera de los altavoces | **Corregido**, con 3 pruebas de regresión |
| 2. El aviso general compartía esquina con la tirilla de caja | **Corregido:** abajo a la izquierda del contenido; en móvil, arriba. Verificado en Chrome |
| 3. Orden de despliegue | Recogido en §20 y §21 |
| 4. El interruptor sin nombre accesible | **Corregido** con `aria-labelledby` |
| 5. La tabla se desbordaba entre 992 y 1330 px | **Corregido** con `minmax(0, …)` y una container query. Verificado en Chrome a 1280, 1100 y 390 px |
| 6. Ventana justo tras el gesto | **Corregido:** liderazgo con `ifAvailable` primero |
| 7. Tope de `resume()` corto con hardware lento | **Corregido:** 1,5 s si ya hubo gesto |
| 8. Mensajes de «Probar» inexactos | **Corregido:** solo dice «el navegador no dejó» si fue un bloqueo |
| 9. Valoraciones silenciadas desde otra pestaña no escuchaban | **Corregido:** escucha si está activada |
| 10. Menú de la cuenta incoherente con el lateral | **Corregido:** todos ven «Configuración» |
| 11. Pruebas | **Corregido:** `stop` exacto, concesión asíncrona en el `LockManager` de prueba y prueba de la página con `?tab=security` |
