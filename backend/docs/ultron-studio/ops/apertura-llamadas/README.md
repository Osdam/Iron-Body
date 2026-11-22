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

## Medición repetida sobre `0c57d7c4` (22-11-2026, backend de voz)

Árbol desechable `_worktrees/cierre-voz-abierta/Iron-Body`, rama local `tmp/voz-abierta-medicion`, que no se integra nunca: `0c57d7c4` + `cbf94374`, la apertura simulada (solo `VoiceCallPolicy.php:23`). `vendor/` clonado y SQLite en memoria. Una sola batería cada vez.

| Paso | Qué | Resultado |
|---|---|---|
| 1 | Solo la línea abierta: `tests/Unit/{Voice,Telephony}` y `tests/Feature/{Voice,Telephony}` | `Tests: 892, Failures: 26, Skipped: 163` |
| 2 | Solo la línea abierta: 89 ficheros. Son V9, B9Cierre, `Facts`, `Guards`, W9, `Unit/Outreach/Core`, `Unit/Twilio`, los de voz e integraciones del Studio y **todas las pruebas nuevas o cambiadas desde `1ef1d72e`** en `Outreach` y `UltronStudio` | `Tests: 2613, Failures: 43, Skipped: 148` |
| 3 | Los 26 + 43 casos frente a la lista del 11-11 (`evidencia-h9/2026-11-11/telefonia-prep/04-…` y `05-…`) | **Los mismos 69 casos, 35 métodos y 21 ficheros** (`diff` vacío). Ninguna prueba nueva depende de la constante. `app/Services/{Voice,Telephony}` no cambió desde `1ef1d72e` |
| 4 | `git am` de los tres parches sobre la apertura simulada | Limpio. `git range-diff 3287769b^..33ba6299 HEAD~3..HEAD`: «=» en los tres. 30 ficheros, nada fuera de `backend/tests/` |
| 5 | Abierta y con los parches: `bateria-rapida.txt` | `OK (624 tests, 24944 assertions)` |
| 6 | Abierta y con los parches: las carpetas del paso 1 y los 89 ficheros del paso 2 | `OK (901)` y `OK (2614)`. `integrations-cases-check.py`: `RESULTADO: OK` |
| 6b | Abierta y con los parches, PostgreSQL 14 (`UNIT_DB=ultron_studio_cierre_voz pgunit.sh … tests/Feature/Telephony tests/Feature/Voice`) | `Tests: 528, Skipped: 27`, sin fallos: la cifra del 11-11 |
| 7 | **No tautológicos:** los parches con la constante CERRADA, `bateria-rapida.txt` | `Tests: 624, Errors: 10, Failures: 69`: caen 79 casos, la cifra del 11-11. Todo lo que cae está en los ficheros de los parches o lee su fixture (`StudioIntegrationsParityTest` lee `integrations-cases.json`) |

**Conclusión:** los parches no hay que rehacerlos y sus huellas no cambian (`9f51b30a…`, `5e5c61de…`, `361c9ff2…`, comprobadas el 22-11). `bateria-rapida.txt` sigue igual: 24 ficheros. Si `main` avanza antes de la apertura, hay que repetir los pasos 1 a 3. Otros dos frentes trabajan en `app/Services/Outreach` y sus pruebas.

**No aplicar nunca** con la constante cerrada: las pruebas caerían (es la comprobación de que no son tautológicas).

Informes: `../../plans/informes-motores/informe-tester-voz-abierta.md` (el autor y la nota del lead) e `informe-reviewer-voz-abierta.md` (aprobado con condiciones, sin bloqueantes).
