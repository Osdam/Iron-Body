# ULTRON STUDIO: tecnologías y versiones

**Fecha:** 17-10-2026. **Alcance de este documento:** las decisiones de pila del Hito C (frontend visual). Las fases funcionales añadirán lo suyo aquí mismo.

## Decisión de partida: Angular, sin actualizar

| Pieza | Declarado | Instalado | Decisión |
|---|---|---|---|
| Angular (`@angular/core`, `common`, `router`, `forms`…) | `^21.2.0` | 21.2.10 | **Se mantiene.** Es la versión que corre el CRM en producción; el Studio se construye sobre ella. |
| `@angular/build` y `@angular/cli` | `^21.2.9` | 21.2.x | Se mantiene |
| TypeScript | `~5.9.2` | 5.9.x | Se mantiene |
| Node | — | 24.20.0 (npm 11.19.0) | Entorno de construcción actual |
| vitest (`ng test`) | `^4.1.10` | 4.1.x | Se mantiene |
| Sass | — | 1.97.3 (vía `@angular/build`) | Necesario para el tema de Foblex (SCSS) |

**Por qué no se actualiza Angular ahora.** Angular 21.2.10 tiene avisos de seguridad publicados (ver más abajo) que se corrigen en los parches 21.2.15 a 21.2.24, y existe 21.2.25. Es una actualización **de parche** (sin migraciones) y conviene hacerla, pero **no en este cambio**: mezclar la actualización del framework con un módulo nuevo impediría atribuir una regresión a una cosa o a la otra. Queda propuesta como cambio aparte, con su propia suite y su propio despliegue.

## Dependencia nueva: Foblex Flow

| Paquete | Versión fijada (exacta) | Licencia | Publicada | Dependencias entre pares |
|---|---|---|---|---|
| `@foblex/flow` | **19.3.0** (`latest`) | MIT | 27-09-2026 | `@angular/core` y `common` ≥ 17.3.0; `@foblex/2d ^1.2.2`, `utils ^1.1.1`, `mediator ^1.1.3`, `platform ^1.0.4` |
| `@foblex/2d` | 1.2.2 | MIT | — | Angular ≥ 12, rxjs ≥ 6.6 |
| `@foblex/utils` | 1.1.1 | MIT | — | solo `tslib` |
| `@foblex/mediator` | 1.1.3 | MIT | — | Angular ≥ 12, rxjs ≥ 6.6 |
| `@foblex/platform` | 1.0.4 | MIT | — | Angular ≥ 12 |

**Comprobaciones hechas antes de instalar:**
- **Estable y actual:** 19.3.0 es la etiqueta `latest`; doce publicaciones entre mayo y septiembre de 2026 (18.6.1 → 19.3.0): proyecto con mantenimiento activo. Repositorio `github.com/Foblex/f-flow`, documentación en `flow.foblex.com`.
- **Compatibilidad:** las dependencias entre pares aceptan Angular 21 y rxjs 7.8. La instalación no necesitó `--force` ni `--legacy-peer-deps`.
- **Licencia:** MIT en los cinco paquetes. Código abierto, sin dependencias comerciales.
- **Vulnerabilidades:** `npm audit --omit=dev` tras la instalación: **ninguna atribuible a Foblex**. Las seis que aparecen son de Angular 21.2.10 y ya existían (abajo).
- **Impacto en el build:** la librería se carga solo en el chunk diferido de la ruta del Studio. Se mide en el informe visual.
- **Versiones exactas** (sin `^`) en `package.json`, y `package-lock.json` regenerado con npm 11: reproducible con `npm ci`.

**Cómo se usa (reglas de la propia librería, `AI.md` del paquete):** modo clásico (la aplicación es dueña del estado; Foblex emite gestos), `FFlowModule` en el componente, jerarquía `f-flow › f-canvas › [fNode] / f-connection`, conector unificado `[fConnector]`, `ngProjectAs="[fNodes]"` y `"[fConnections]"` para los bucles anidados, `withA11y()` para el teclado. El tema SCSS se importa por ruta relativa porque el `exports` del paquete no publica `./styles`.

## Avisos de seguridad previos (Angular 21.2.10)

`npm audit --omit=dev` lista seis avisos, **todos de Angular y anteriores a este trabajo**. Se corrigen con parches de la misma rama:

| Paquete | Gravedad | Aviso | Corregido en |
|---|---|---|---|
| `@angular/common` | alta | DoS por memoria en `formatDate` (GHSA-48r7-hpm6-gfxm) | ≥ 21.2.17 |
| `@angular/common` | alta | Clave de caché débil en `HttpTransferCache` (GHSA-39pv-4j6c-2g6v) | ≥ 21.2.17 |
| `@angular/common` | alta | DoS por memoria en formato de números (GHSA-p3vc-36g9-x9gr) | ≥ 21.2.15 |
| `@angular/common` | alta | Caché de peticiones con credenciales en `HttpTransferCache` (GHSA-q6f4-qqrg-jv6x) | ≥ 21.2.15 |
| `@angular/common` | alta | Ambigüedad de clave de caché (GHSA-jhpw-976m-542j) | ≥ 21.2.19 |
| `@angular/common` | media | Fuga vía `withRequestsMadeViaParent` (GHSA-p297-fm68-3q8c) | ≥ 21.2.20 |
| `@angular/compiler` | media/alta | Varias evasiones de saneamiento (XSS) (GHSA-58w9-8g37-x9v5, GHSA-f3m7-gqxr-g87x, GHSA-jj27-h5hq-8x99, GHSA-hh8m-fm6v-7cvg) | ≥ 21.2.20 |
| `@angular/core` | media/alta | Evasión de saneamiento y DOM clobbering en hidratación (GHSA-692r-grfm-v8x7, GHSA-rgjc-h3x7-9mwg) | ≥ 21.2.17 |
| `@angular/router` | alta | DoS en SSR por parámetros de matriz (GHSA-ff3f-86qr-9cv3) | ≥ 21.2.24 |

**Exposición real del CRM:** no usa SSR ni `HttpTransferCache`, así que los avisos de caché, hidratación y SSR no le aplican tal cual; los de saneamiento (XSS) y formato sí son pertinentes. **Recomendación:** actualizar a 21.2.25 en un cambio aparte, con la suite completa y un despliegue propio.

## Cambio en `package-lock.json`

Al instalar Foblex, npm 11 además **retiró** catorce entradas opcionales que ya no referenciaba nadie: `tailwindcss` 4.2.4 y los binarios de `lightningcss`, que estaban como dependencia opcional entre pares de `@angular/build` y que el CRM no usa (no hay `tailwind.config`, ni `@tailwind` ni `@import "tailwindcss"` en `src/`). No cambió la versión de ningún otro paquete. Se verifica con el build de producción del informe visual.

## Evaluación de Python / FastAPI / LangGraph

Pendiente del Hito B (diseño de arquitectura), con la auditoría del motor real como entrada. Criterio ya fijado por el encargo: **no se incorpora si no aporta una ventaja comprobable**, y Laravel conserva la autoridad sobre autenticación, autorización, configuraciones, aprobaciones y reglas.
