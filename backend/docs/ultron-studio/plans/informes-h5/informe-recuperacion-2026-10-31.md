# ULTRON STUDIO · Informe de recuperación del estado real (31-10-2026)

Escrito por el lead al retomar el proyecto tras perder la sesión anterior (cortada el 25-10-2026 ~17:30 UTC por límite de uso). Todo lo que sigue está verificado hoy sobre los repositorios locales, `origin` y el servidor de producción (solo lectura por SSH, alias `ironbody-vps`, usuario root, clave en el agente del Mac; sin aceptar claves de servidor nuevas).

## 1. Hito real alcanzado

| Hito | Estado real | Evidencia |
|---|---|---|
| 1 Arquitectura | COMPLETADO | `ULTRON_STUDIO_ARCHITECTURE_AUDIT.md`, `ULTRON_STUDIO_TECH_STACK.md` |
| 2 Studio visual | COMPLETADO y en producción | CRM `4f7d774` (18-10) y sucesores |
| 3 Motor de control (F + G) | COMPLETADO y en producción | F `9acddc3`/`020db86` (18-10); G `e651dd9`/`44c0e8c` (19-10) |
| 4 Laboratorio de IA | DESPLEGADO EN FASES 1–2 (`HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`) | backend `fef7c87`, CRM `5c1dbd7` (25-10); producción hoy: laboratorio encendido en `fake`, presupuesto 0, sin clave de OpenAI; fase 3 depende de la clave del propietario |
| 5 Gobierno y versionado | **IMPLEMENTADO EN RAMA, REVISADO PARCIALMENTE, NI EMPUJADO NI DESPLEGADO** | ver §2 y §5 |
| 6 Integraciones comerciales | PENDIENTE (solo diseño del 17-10) | `ULTRON_STUDIO_META_REPORT.md`, `ULTRON_STUDIO_TWILIO_REPORT.md`, `ULTRON_STUDIO_ELEVENLABS_READY.md` |
| 7 Seguridad y producción | PENDIENTE | lista de «Pendientes del propietario (Hito 7)» en la hoja de ruta y Enmienda 2 §E2.5 del contrato H5 |

Los commits históricos citados en el encargo (`4f7d774`, `93fdc06`, `c951237`, `020db86`, rama `feat/ultron-studio`) existen y son de los hitos 2 y 3-F; hay desarrollo posterior en `feat/ultron-studio-g`, `feat/ultron-studio-h4` y `feat/ultron-studio-h5`, todo fusionado en `origin/main` salvo el Hito 5.

## 2. Último commit relevante

| Repositorio | Dónde | Rama | HEAD al retomar | HEAD hoy (tras fusionar `origin/main`) | `origin/main` |
|---|---|---|---|---|---|
| Backend (`Iron-Body`) | `_worktrees/thermal-receipts/Iron-Body` | `feat/ultron-studio-h5` | `96397ea` (13 commits sobre la base `0366009`) | `cdb9dc8` | `7318122` |
| CRM (`Iron-Body_Front`) | `_worktrees/thermal-receipts/Iron-Body_Front` | `feat/ultron-studio-h5` | `a29bcc8` (6 commits sobre la base `5c1dbd7`) | `9fe1817` | `4105016` |

- Las dos fusiones se hicieron hoy sin conflictos (`git merge-tree` en seco antes). Lo que `origin/main` añadió desde la base es ajeno al Studio (biometría, caja, transferencias de membresía, potencias de Iron IA, archivar planes); fuera del Studio solo toca `SalesPaymentGuardrailService.php` (+1 línea) y `routes/api.php`. El diff del motor de ULTRON frente a `0366009` sigue limitado a `UltronPublishedConfig.php`, `UltronCommitService.php` y `config/marketing.php`.
- Los checkouts principales (`Iron_Body_Crm/Iron-Body` en `main`, `Iron_Body_Crm/Iron-Body_Front` en `main`) están limpios y por detrás de `origin/main` (42 y 84 commits): no hay trabajo divergente en `main`. Hay dos entradas de `stash` ajenas (`PUNTO 8 WIP`, `dialogo-contrasena`) que no se tocan.
- La rama `fix/h5-lead` (= `96397ea`) ya está fusionada; su worktree en `/tmp` se purgó (figura como `prunable`). No se borra nada sin orden.
- Sin confirmar en el backend: `docs/ultron-studio/plans/informes-h5/export/` (5 fixtures regenerables con `ultron-studio:export-fixtures`; por decisión del lead del 25-10 no se confirman).

## 3. Estado de producción (31-10-2026 21:01 UTC, solo lectura)

- Servidor `srv1728633`, 15 días en marcha; nginx, php8.3-fpm, postgresql 14.24 y supervisor activos.
- API `/var/www/api` en `7318122` = `origin/main`. CRM `/var/www/app` en `4105016` = `origin/main` (build del 29-10-2026 00:49 UTC).
- Studio en producción = Hito 4: 33 rutas `admin/ultron-studio`, 5 migraciones (`2026_10_18_100001/100002`, `2026_10_19_100001/100002/100003`), 10 tablas `ultron_studio_*`, worker `ironbody-lab` RUNNING (13 workers RUNNING, 0 BACKOFF). Datos: 1 borrador, 0 versiones, 0 ejecuciones del laboratorio.
- Entorno: `ULTRON_STUDIO_LAB_ENABLED`, `ULTRON_STUDIO_LAB_DRIVER` y `ULTRON_STUDIO_LAB_DAILY_BUDGET_USD` presentes (fase 2 del Hito 4); **ninguna de las 7 llaves A13 del Hito 5** (`MARKETING_ULTRON_STUDIO_CONFIG`, `ULTRON_STUDIO_PUBLISHING_ENABLED`, …). ULTRON encendido, cobro apagado, canario configurado, `OPENAI_API_KEY` del motor presente, credenciales de Meta Ads (lectura) y Twilio (Verify) presentes; nada de ElevenLabs. Valores no leídos ni impresos.
- Hallazgos operativos (no se corrige nada sin orden): (a) cinco ficheros vacíos sin seguimiento en `/var/www/api/backend/` llamados `-`, `0,`, `1,`, `2,` y `21000.00` (root, 8-10-2026 18:40: restos de una redirección de shell ajena; no afectan al script de la fase 1, que ignora los no seguidos); (b) `.git/index` de `/var/www/api` es de root (el resto de `.git`, de www-data): coherente con lo anotado el 26-10 (git del VPS se opera como root); (c) los respaldos `ultron-studio-g-*` y `ultron-studio-h4-*` están en 755 (los demás en 700): endurecer a 700 durante la fase 1, nunca borrar.

## 4. Funciones completas

- Hitos 1–3 enteros y Hito 4 en fases 1–2, en producción.
- Hito 5, en la rama: migraciones (cadena `ultron_studio_publications`, 30 disparadores/27 CHECK en PG), fixture de 231 casos, lector del motor `UltronPublishedConfig` con sello por turno, núcleo puro (`StudioPublicationDeclaration`, `StudioCanaryEvidence`), API de 38 rutas (publicar/promover/revertir/Legacy, vista previa, evidencia, historial, `publication-check`), idempotencia con reintento 200 `replayed`, carreras reales en PG (38), CRM integrado (diálogos, barra de publicación, historial, `publication_head_invalid`), arnés E2E con pila real como activo del repo (405/408 en la última pasada, rojas corregidas), scripts de operación (`deploy-h5-backend-fase1.sh`, `h5-engine-scope.sh`, `smoke-h5.py`, `smoke-run-h5.sh`, `h5-schema-check.sql`), revisiones de `security`, `api-security-specialist`, `tester` (backend y E2E) y `devops` cerradas con sus correcciones; contrato con Enmiendas 1 y 2.

## 5. Funciones pendientes

Hito 5 (antes de pasar al 6):
1. Veredicto final del `reviewer` (criterios 6 CRM, 7 scripts, 8 docs, 9 regresiones; el anterior cubrió 1–5 sin bloqueantes y se cortó). Lanzado hoy.
2. m-2 del reviewer (409 `revalidation_blocked` sin ningún hallazgo que enseñar cuando el catálogo no sabe leer el contenido): corrección mínima con prueba, hoy.
3. Documentación: secciones «Hito 5» en `DEPLOYMENT_PLAN`, `FINAL_REPORT`, `SECURITY_REPORT`, `BACKEND_REPORT`, `VISUAL_REPORT`, hoja de ruta y cabecera obsoleta del Hito 4 en `ROLLBACK`. Lanzada hoy.
4. Puertas tras la fusión: Studio+Access+Chaos en SQLite, puerta PG (`pgunit.sh`), Marketing, `auth:coverage`, 38 rutas; CRM: módulo, `tsc`, `check:literals`, build de producción. En marcha.
5. `PREVIO` del script de la fase 1 actualizado hoy de `fef7c87` a `7318122` (producción real).
6. Push por avance rápido de los dos repos; fase 1 (respaldo → backend con todo apagado → CRM → smoke fase 1 → verificación 15 min/24 h); fase 2 (`canary`) y primera publicación; fase 3 (`all`) tras 24 h y 3 turnos del canario.

Hito 6: todo (plan del arquitecto lanzado hoy). Hito 7: todo.

## 6. Bloqueantes críticos

- **Puerta del laboratorio:** con `ULTRON_STUDIO_PUBLISH_LAB_GATE=required` y el laboratorio en `fake` sin clave de OpenAI, no existe un veredicto `approved` posible, así que la primera publicación exige `waived` (decisión de gobierno reservada al propietario; la autorización del 25-10 permite tomarla documentándola por escrito en cada publicación y en el informe) o cerrar antes la fase 3 del Hito 4 (clave y proyecto de OpenAI del propietario).
- **Cuatro ojos reales:** publicar exige una identidad distinta de la autora de la versión; en producción hace falta una segunda persona con `ultron.publish` (o la cuenta temporal de smoke, id 32, solo para el ensayo).
- **Evidencia del canario:** promover a `all` exige ≥ 24 h y ≥ 3 turnos reales del canario sellados al 100 %: no se puede acelerar.
- **Hito 6, dependencias externas:** Meta solo lectura con token existente; Twilio solo Verify (sin Voice); ElevenLabs sin credenciales (`ELEVENLABS_NOT_CONFIGURED` se mantiene). Nada de esto bloquea el trabajo independiente.

## 7. Siguiente tarea concreta

Cerrar el Hito 5: (1) puertas tras la fusión en verde, (2) corrección m-2 con prueba, (3) veredicto del reviewer y documentación, (4) commit y push ff de los dos repos, (5) fase 1 en producción con respaldo y smoke. En paralelo, congelar el contrato del Hito 6 a partir del plan del arquitecto.
