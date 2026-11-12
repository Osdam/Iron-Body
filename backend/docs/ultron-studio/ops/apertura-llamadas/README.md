# Las pruebas de la apertura de las llamadas (C-4), guardadas como parches

**Qué es.** Los tres commits de pruebas que hay que aplicar justo después de desplegar el commit que abre las llamadas de voz (`voice(calls-open)`, una línea en `app/Services/Voice/VoiceCallPolicy.php`). Con esa línea abierta caen 69 casos de la batería (35 métodos en 21 ficheros): estos parches los adaptan sin aflojar ninguna cerradura.

| Parche | Commit de origen | Qué hace |
|---|---|---|
| `0001-T-3287769b.patch` | `3287769b` | Las pruebas describen el árbol con las llamadas abiertas: cada una niega por la cerradura que nombra, las de «todo en regla» fijan la fila, el sello, la reserva, el evento y el job, y el estado cerrado sigue probado con una copia en memoria |
| `0002-T2-216b0f1b.patch` | `216b0f1b` | La llamada aceptada se encola UNA sola vez, en la cola `voice` (condición I-1 de la revisión) |
| `0003-T3-33ba6299.patch` | `33ba6299` | Quien llama a la política del H6 también necesita su copia cerrada, y los comentarios dicen lo que hay (menores M-2, M-3 y M-5) |

Solo tocan `backend/tests/` (30 ficheros). Huellas sha256 del 12-11-2026: `9f51b30aabd36baa…`, `5e5c61dec9bc539b…` y `361c9ff25d381862…`. Comprobado con un índice temporal: aplicados en orden sobre la simulación de la línea abierta (`e61ef7a2`) dan el mismo árbol que `33ba6299`.

**Por qué están aquí.** Los commits viven en una rama **local** (`prep/voice-calls-open-tests`, en el worktree `_worktrees/ultron-voz-abierta/Iron-Body` del equipo de desarrollo) que no se sube: lleva delante un commit que simula la línea abierta. Si esa máquina se pierde, las pruebas se pierden. Estos parches son la copia que viaja con el repositorio.

**Cómo se aplican.** El procedimiento completo, con sus precondiciones, es el paso 9 de `../RUNBOOK_PRIMERA_LLAMADA.md`. Si la rama local existe, se usa `git cherry-pick 3287769b 216b0f1b 33ba6299`. Si no:

```bash
# En un árbol limpio, con HEAD == origin/main == el commit de la apertura:
git am backend/docs/ultron-studio/ops/apertura-llamadas/0001-T-3287769b.patch \
       backend/docs/ultron-studio/ops/apertura-llamadas/0002-T2-216b0f1b.patch \
       backend/docs/ultron-studio/ops/apertura-llamadas/0003-T3-33ba6299.patch
# Si no aplican limpio: git am --abort. No resolver a mano: rehacer las pruebas sobre la punta real.
```

Después, las baterías y el comprobador del fixture que lista el runbook. La primera es la rápida: `bateria-rapida.txt` lleva, uno por línea, los 21 ficheros de pruebas en los que caen los 69 casos y las 3 pruebas de la copia cerrada (24 ficheros; con los parches aplicados, `OK (624 tests)`).

**Antes de usarlos** hay que repetir la medición con solo la línea abierta sobre la punta real de `main`: los parches se prepararon sobre `1ef1d72e` (11-11-2026). Una prueba nueva que dependa de la constante no la detecta el parche; la detecta la batería.

**No aplicar nunca** con la constante cerrada: las pruebas caerían (es la comprobación de que no son tautológicas).

Informes: `../../plans/informes-motores/informe-tester-voz-abierta.md` (el autor y la nota del lead) e `informe-reviewer-voz-abierta.md` (aprobado con condiciones, sin bloqueantes).
