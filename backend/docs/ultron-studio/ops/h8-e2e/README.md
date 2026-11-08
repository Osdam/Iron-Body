# E2E del Hito 8 con la pila real y los simuladores (`ops/h8-e2e.sh`, TEST8-b)

```bash
bash backend/docs/ultron-studio/ops/h8-e2e.sh ambas            # desde la raíz del worktree: las dos fases, cierre, hash y evidencia
H8_E2E_EVIDENCIA=0 bash backend/docs/ultron-studio/ops/h8-e2e.sh abierta    # una fase, sin copiar la evidencia al repo
H8_E2E_HOLD=1 bash backend/docs/ultron-studio/ops/h8-e2e.sh cerrada         # deja la pila en pie (desarrollo); parar con SIGTERM al proceso
```

**Nunca toca producción ni sale a la red.** ElevenLabs y Twilio son los simuladores de OPS8 (`ops/banco-h8/`), en
127.0.0.1, con los adaptadores REALES apuntados a ellos (E6-11). Todo proceso PHP de la pila sale por la valla.

## Qué hace

1. **Dos copias del commit** (`git archive` desde la raíz, fuera del repo, en `~/.cache/ironbody-h8-e2e/run-*`):
   la **cerrada** (el commit tal cual) y la **abierta** (el giro de UNA línea de `CALLS_ENABLED_IN_CODE`, comprobado
   con `diff`; protección 2: nunca en el árbol compartido). Las pruebas `tests/Feature/Voice/E2E/*.php` se copian del
   árbol de trabajo.
2. **Por fase:** PG desechable `ultron_studio_h8_e2e` con el modelo de roles de producción (`h8e2e_owner` migra;
   `h8e2e_app`, solo DML, es el rol del REVOKE de DB8), las 330 migraciones, `import-baseline`; la comprobación previa
   (`ayudante.php preflight`: `APP_ENV=local`, las bases de los adaptadores en 127.0.0.1 —si faltaran, el código cae
   a los hosts reales—, la constante); valla + simuladores + `artisan serve --no-reload` (4 workers) + el worker real
   `queue:work voice`; las sondas de la valla; tres cuentas (Ana y Beto Super Admin, Carla Administrador) con sesiones
   del CRM en ficheros 600; `--verify-voice` contra el simulador (1 GET); el muestreo `lsof`.
3. **Los recorridos** son pruebas PHPUnit (`H8E2ECerradaTest`, `H8E2EAbiertaTest`) que corren en la copia contra la
   pila: HTTP real, SQL por PDO y el control GET de los simuladores. El laboratorio hablado se cierra con la post-llamada
   web del emisor del simulador (`/__emit/post-call?web=1&agent_id=`, OPS8-b), juzgada por el estado de la sesión. Sin el contexto del arnés (`H8_E2E_CONTEXT`), en la
   suite normal, cada prueba sale *skipped* con el motivo: no es un verde.
4. **El cierre:** violaciones de los simuladores (solo se restan, y se dicen, los falsos positivos de F-6 que el paso h
   verificó con su oráculo), hosts de la valla, `lsof`, `ERROR` en los registros, `failed_jobs`, colas y el barrido
   (`barrido.py`: piezas, APP_KEY, tokens, centinelas y teléfonos en registros, base, respuestas y evidencia).
5. **El resumen** con el hash de resultados (sha256 de las comprobaciones ordenadas por fase, paso, nombre y ok) y la
   evidencia en `ops/evidencia-h8/<fecha>/<sello>/` (también barrida).

## Las piezas de este directorio

| Fichero | Qué es |
|---|---|
| `entorno-banco.ejemplo` | La plantilla del entorno de cada copia (sin secretos: los marcadores los rellena el arnés) |
| `ayudante.php` | `preflight`, `cuentas`, `plan`, las dos **costuras** (abajo), `despachar`, `olvidar-deriva`, `revocar` |
| `reloj.php` | Carbon desplazado (`auto_prepend_file`) solo en el worker de UN trabajo de la cola `voice-e2e` |
| `barrido.py` | El barrido de secretos y teléfonos (nunca imprime un valor) |

## Costuras (y por qué)

- **Fila `requested` sellada** (`ayudante.php saliente`): exactamente las columnas, el evento y el job que escribe
  `OutboundGate::decide`. La puerta no deja pasar ninguna saliente hasta E4-6 (`elevenlabs_unverified`); así se ejerce
  el JOB real (recheck TOCTOU, adaptador real, simuladores). La política la prueba la puerta por HTTP.
- **Entrada canaria a mano** (`ayudante.php cadena-canaria`, solo en la copia CERRADA): la que publicar escribe tras
  asignar el número, que con la constante cerrada nunca llega. Sin ella no existe una fila sellada (`vc_stamp_chk`).
- **Reloj del job** (`reloj.php`): el horario legal se vuelve a mirar en el job; para la saliente completa y los
  casos domingo y festivo, el worker de un trabajo corre en el instante pedido (martes 10/11, domingo 15/11, lunes
  festivo 16/11 de 2026). `time()`, la base y los simuladores siguen con el reloj real. El worker residente atiende
  `voice` con el reloj real (su resultado lo predice el oráculo con el fixture de festivos de TEST8-a).

## Trampas (además de las del E2E del CRM)

- `artisan()` es un método de `Illuminate\Foundation\Testing\TestCase`: el de la copia se llama `artisanCopia()`.
- `setTestNow(fn () => CarbonImmutable::createFromTimestamp(...))` recursa sin fin: el cierre devuelve un
  `DateTimeImmutable` nativo.
- Los procesos de la copia van con `env -i`: si heredaran el entorno de PHPUnit usarían la base en memoria.
- Los limitadores son los reales: el cliente respeta `Retry-After` (una espera y un reintento, contados en el paso h).
- `$x['k'] ?? 'x'` con un valor `null` devuelve `'x'`: para «la clave existe y es null», `array_key_exists`.
- El python3 del sistema es 3.9: sin comillas dobles anidadas en las f-string.
- `read` parte por espacios: el JSON que lee el cierre va compacto.
