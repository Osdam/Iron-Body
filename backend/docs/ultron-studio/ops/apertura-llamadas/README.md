# Las pruebas de la apertura de las llamadas (C-4), guardadas como parches

**Qué es.** Los dos commits de pruebas que hay que aplicar justo después de desplegar el commit que abre las llamadas de voz (`voice(calls-open)`, una línea en `app/Services/Voice/VoiceCallPolicy.php`). Con esa línea abierta caen 69 casos de la batería (35 métodos en 21 ficheros): estos parches los adaptan sin aflojar ninguna cerradura.

| Parche | Commit de origen | Qué hace |
|---|---|---|
| `0001-T-3287769b.patch` | `3287769b` | Las pruebas describen el árbol con las llamadas abiertas: cada una niega por la cerradura que nombra, las de «todo en regla» fijan la fila, el sello, la reserva, el evento y el job, y el estado cerrado sigue probado con una copia en memoria |
| `0002-T2-216b0f1b.patch` | `216b0f1b` | La llamada aceptada se encola UNA sola vez, en la cola `voice` (condición I-1 de la revisión) |

Solo tocan `backend/tests/` (29 ficheros). Huellas sha256 del 11-11-2026: `394b877ec4beb068…` y `286c0f18f40d8623…`.

**Por qué están aquí.** Los commits viven en una rama **local** (`prep/voice-calls-open-tests`, en el worktree `_worktrees/ultron-voz-abierta/Iron-Body` del equipo de desarrollo) que no se sube: lleva delante un commit que simula la línea abierta. Si esa máquina se pierde, las pruebas se pierden. Estos parches son la copia que viaja con el repositorio.

**Cómo se aplican.** El procedimiento completo, con sus precondiciones, es el paso 9 de `../RUNBOOK_PRIMERA_LLAMADA.md`. Si la rama local existe, se usa `git cherry-pick 3287769b 216b0f1b`. Si no:

```bash
# En un árbol limpio, con HEAD == origin/main == el commit de la apertura:
git am backend/docs/ultron-studio/ops/apertura-llamadas/0001-T-3287769b.patch \
       backend/docs/ultron-studio/ops/apertura-llamadas/0002-T2-216b0f1b.patch
# Si no aplican limpio: git am --abort. No resolver a mano: rehacer las pruebas sobre la punta real.
```

Después, las baterías y el comprobador del fixture que lista el runbook. La primera es la rápida: `bateria-rapida.txt` lleva, uno por línea, los 21 ficheros de pruebas en los que caen los 69 casos y las 3 pruebas de la copia cerrada (24 ficheros; con los parches aplicados, `OK (624 tests)`).

**Antes de usarlos** hay que repetir la medición con solo la línea abierta sobre la punta real de `main`: los parches se prepararon sobre `1ef1d72e` (11-11-2026). Una prueba nueva que dependa de la constante no la detecta el parche; la detecta la batería.

**No aplicar nunca** con la constante cerrada: las pruebas caerían (es la comprobación de que no son tautológicas).

Informes: `../../plans/informes-motores/informe-tester-voz-abierta.md` (el autor y la nota del lead) e `informe-reviewer-voz-abierta.md` (aprobado con condiciones, sin bloqueantes).
