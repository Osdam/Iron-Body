# H9-E2E-4 · Abrir una estrategia la deja en la URL (CRM)

**Agente:** fe-tpl (frontend) · **Fecha:** 2026-11-11 · **Base:** CRM `feat/ultron-studio-h9` en `7a58f16` (producción)
**Estado:** arreglado en local, **sin commit**. Listo para desplegar.

## Síntomas (del E2E con navegador real sobre lo desplegado)

Comprobaciones del paso `url` en `docs/ultron-studio/ops/h9-e2e/recorridos-h9.cjs:828-866`:

- (a) «Abrir» desde la lista: la URL queda `?pestana=constructor` **sin** `estrategia=<uuid>` (F5 y los enlaces la pierden).
- (b) Con otra estrategia en la URL, crear una nueva y recargar (F5): la URL apunta a la **anterior** y el constructor
  vuelve a ella en silencio.

Evidencia del rojo: `ops/evidencia-h9/2026-11-11/e2e/20261111T033646Z-07e70dac-crm-7a58f16/checks-h9.jsonl:176,181`.

## Causa raíz

`commercial-automation-page.ts:260-263` (en `7a58f16`): `abrirEstrategia(uuid)` hacía **dos navegaciones seguidas**:

```ts
this.session.select(uuid);
void this.router.navigate([], { …, queryParams: { estrategia: uuid }, queryParamsHandling: 'merge', replaceUrl: true }); // :262
this.elegir('constructor'); // :263 → otro router.navigate con { pestana } y 'merge' (:257)
```

Las navegaciones del router son asíncronas. La segunda se lanza antes de que termine la primera: el router **cancela la
primera** (la sustituye) y la **mezcla** (`merge`) de la segunda parte de la URL vigente, que todavía es la de ANTES. Resultado:
la URL de antes + `pestana=constructor`. Si no había `estrategia`, se queda sin ella (a); si había otra, se queda la otra (b).
El constructor abría bien porque la elección va por `session.select()`, no por la URL; por eso solo se veía al recargar.

Las dos mitades son la misma causa: al crear, `ca-strategies.ts:227` emite `abrir` con el uuid nuevo y la página entra en
el mismo `abrirEstrategia()` (`commercial-automation-session.ts:395` ya había elegido la nueva en la sesión).

Lo confirmó antes el tester con la mutación D (`ops/evidencia-h9/2026-11-11/e2e-mutaciones/20261111T024811Z-07e70dac-crm-7a58f16/`:
una sola navegación en el bundle compilado ⇒ las 2 comprobaciones en verde, 78/78).

## Arreglo (cambio mínimo)

`src/app/modules/commercial-automation/commercial-automation-page.ts:255-273`: un único método privado `navegar(p, extra)`
pone la pestaña y hace **una sola** `router.navigate` con `{ pestana, ...extra }` (`merge`, `replaceUrl`, como antes).
`elegir(p)` llama a `navegar(p)`; `abrirEstrategia(uuid)` hace `session.select(uuid)` y `navegar('constructor', { estrategia: uuid })`.
Nada más cambia: la forma de la URL, el teclado de las pestañas y la lectura de `?pestana=`/`?estrategia=` al nacer quedan igual.

## Ficheros tocados (CRM)

| Fichero | Cambio |
|---|---|
| `src/app/modules/commercial-automation/commercial-automation-page.ts` | +13 −4: `navegar()` con una sola navegación |
| `src/app/modules/commercial-automation/commercial-automation-page.spec.ts` | +2 specs (describe «la estrategia abierta queda en la URL (H9-E2E-4)») |

**Ojo al hacer commit:** en el árbol hay ficheros sin seguimiento de la tarea de plantillas (`ca-wa-format*`, `ca-wa-preview*`).
No los importa nadie todavía (no entran en el bundle), pero el commit de este arreglo debe llevar **solo los dos ficheros de arriba**.

## Specs

1. «Abrir» desde la lista deja `?estrategia=<uuid>` y `?pestana=constructor` en la URL (y la pestaña Constructor elegida, y
   la ficha de esa estrategia pedida).
2. Con OTRA estrategia en la URL, crear una nueva (diálogo real, `POST strategies` 201) deja la nueva en la URL; «F5»
   (la página nace otra vez de esa URL, con sesión nueva) pide la ficha de la **nueva** y **ninguna** de la anterior.

El constructor pinta el lienzo de foblex, que necesita `ResizeObserver`: el describe lo simula como `ultron-studio-page.spec.ts`
y lo restaura al terminar.

| Ejecución | Resultado |
|---|---|
| `ng test --include=…/commercial-automation-page.spec.ts` **sin** el arreglo | **2 rojos / 7 verdes (9).** (1) `expected { pestana: 'constructor' }` sin `estrategia`; (2) `estrategia: …0001` (la ANTERIOR) en vez de `…00a1` (la nueva): los dos síntomas del E2E |
| La misma **con** el arreglo | **9/9 verdes**, sin errores no manejados |
| `npx tsc -p tsconfig.app.json --noEmit` y `-p tsconfig.spec.json` | limpios |
| `npx ng build --configuration=production` | exit 0 (26 s); chunk `commercial-automation-page` 405,32 kB / 88,41 kB transferidos; inicial 831,22 kB. Ningún aviso en ficheros del módulo: los NG8107 (electronic-invoice-detail, receivables, context-panel, staff-section) y los de presupuesto son de otros módulos y ya estaban |

## Qué NO verifiqué

- No volví a correr el E2E con navegador (`recorridos-h9.cjs`, paso `url`) sobre este arreglo: la máquina va justa y la
  batería la corre el lead. La mutación D del tester es el mismo cambio sobre el bundle y salió en verde.
- No corrí la batería completa del CRM (la corre el lead al integrar); solo el spec de la página.
