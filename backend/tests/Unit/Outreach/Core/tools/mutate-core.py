#!/usr/bin/env python3
"""Mutaciones por muestreo del núcleo B9-core (app/Services/Outreach/Core).

SOLO sobre una COPIA AISLADA del backend (p. ej. un clon APFS con su vendor/:
`cp -c -R backend /ruta/copia/backend`), nunca sobre el árbol compartido: cada
mutante cambia un fichero del núcleo mientras corre la suite. El script se
niega si la ruta está dentro de un árbol de git.

Uso:  python3 mutate-core.py /ruta/copia/backend            (todos)
      python3 mutate-core.py /ruta/copia/backend check      (solo comprueba que cada texto aparece una vez)
      python3 mutate-core.py /ruta/copia/backend id1,id2    (unos pocos)

Por mutante: el texto original aparece exactamente una vez, `php -l`, la suite
tests/Unit/Outreach/Core; se restaura el fichero y se compara su SHA-256.
"""
import hashlib, json, subprocess, sys, os
RAIZ = sys.argv[1]
if subprocess.run(['git', '-C', RAIZ, 'rev-parse', '--is-inside-work-tree'], capture_output=True, text=True).stdout.strip() == 'true':
    sys.exit('Esta ruta está dentro de un árbol de git: usa una copia aislada.')
C = 'app/Services/Outreach/Core/'
M = [
 # (id, fichero, original, mutante, qué rompe)
 ('canon-depth', 'ConditionCanonicalizer.php', "if ($depth > OutreachCatalog::MAX_DEPTH) {", "if ($depth >= OutreachCatalog::MAX_DEPTH) {", 'profundidad 5 deja de valer'),
 ('canon-nodes', 'ConditionCanonicalizer.php', "if ($yo->nodes > OutreachCatalog::MAX_NODES) {", "if ($yo->nodes >= OutreachCatalog::MAX_NODES) {", '50 nodos deja de valer'),
 ('canon-collapse', 'ConditionCanonicalizer.php', "return count($canonicos) === 1 ? $canonicos[0] : [$tipo => $canonicos];", "return [$tipo => $canonicos];", 'no colapsa all/any de un hijo'),
 ('canon-unknown-op', 'ConditionCanonicalizer.php', "$this->fail(\"{$path}.op\", 'unknown_operator');", "$this->fail(\"{$path}.op\", 'operator_not_allowed');", 'código del operador desconocido'),
 ('canon-window-max', 'ConditionCanonicalizer.php', "if ($n * OutreachCatalog::UNIT_HOURS[$unidad] > OutreachCatalog::MAX_WINDOW_DAYS * 24) {", "if ($n * OutreachCatalog::UNIT_HOURS[$unidad] >= OutreachCatalog::MAX_WINDOW_DAYS * 24) {", '730 días dejan de valer'),
 ('canon-in-sort', 'ConditionCanonicalizer.php', "return ['v' => self::sortInDomain($vistos, $dominio, $this->dynamic)];", "return ['v' => $vistos];", 'in/not_in sin ordenar'),
 ('canon-dup', 'ConditionCanonicalizer.php', "if (in_array($r['v'], $vistos, true)) {", "if (false) {", 'repetidos en in'),
 ('canon-between', 'ConditionCanonicalizer.php', "if ($desde > $hasta) {\n            return $this->failNull($path, 'invalid_between');", "if ($desde >= $hasta) {\n            return $this->failNull($path, 'invalid_between');", 'min = max cerrado deja de valer'),
 ('canon-unit-fine', 'ConditionCanonicalizer.php', "} elseif ($unidadHecho === 'days' && $valor['unit'] === 'hours') {", "} elseif ($unidadHecho === 'hours' && $valor['unit'] === 'days') {", 'horas en un hecho en días'),
 ('canon-too-large', 'ConditionCanonicalizer.php', "if (strlen($json) > OutreachCatalog::MAX_BYTES) {", "if (strlen($json) >= OutreachCatalog::MAX_BYTES) {", '8 192 bytes exactos'),
 ('canon-unknown-fact-stop', 'ConditionCanonicalizer.php', "            $this->fail(\"{$path}.fact\", 'unknown_fact');\n\n            return null;", "            $this->fail(\"{$path}.fact\", 'unknown_fact');\n            $hecho = ['type' => 'int', 'args' => [], 'min' => 0, 'max' => 1, 'unit' => 'days', 'domain' => null];", 'DF-G3: hecho desconocido sigue comprobando'),
 ('eval-and', 'ConditionEvaluator.php', "if (in_array(false, $valores, true)) {\n            return false;", "if (in_array(true, $valores, true)) {\n            return false;", 'Y de tres valores'),
 ('eval-or-unknown', 'ConditionEvaluator.php', "return in_array(null, $valores, true) ? null : false;", "return false;", 'O con desconocido'),
 ('eval-not-unknown', 'ConditionEvaluator.php', "$valor = $hijo === null ? null : ! $hijo;", "$valor = ! $hijo;", 'not unknown = true'),
 ('eval-is-known', 'ConditionEvaluator.php', "$op === 'is_known' => $valor !== null,", "$op === 'is_known' => true,", 'is_known siempre'),
 ('eval-more-than-ago', 'ConditionEvaluator.php', "'more_than_ago' => $v < -$n,", "'more_than_ago' => $v <= -$n,", 'more_than_ago estricto'),
 ('eval-within-next', 'ConditionEvaluator.php', "'within_next' => $v >= 0 && $v <= $n,", "'within_next' => $v > 0 && $v <= $n,", 'within_next con 0'),
 ('eval-bucket', 'ConditionEvaluator.php', "$valor !== null && $hecho['trace'] === 'bucket'", "$valor !== null && false", 'la cartera sin tramo'),
 ('eval-events-null', 'ConditionEvaluator.php', "        $cuantos = null;\n        if ($f->events !== null) {\n            $cuantos = 0;\n            foreach ($f->events as $e) {\n                if ($e['key'] === $node['event']", "        $cuantos = 0;\n        if ($f->events !== null) {\n            $cuantos = 0;\n            foreach ($f->events as $e) {\n                if ($e['key'] === $node['event']", 'fuente sin datos = ausencia'),
 ('eval-strategy-other', 'ConditionEvaluator.php', "'strategy' => $v === 'self' ? $c['strategy'] === 'self' : $c['strategy'] !== 'self',", "'strategy' => $v === 'self' ? $c['strategy'] === 'self' : $c['strategy'] === 'other',", 'other sin los de fuera del H9'),
 ('eval-last-tie', 'ConditionEvaluator.php', "($ultimo === null || $en >= RelativeTime::parseInstant($ultimo['at']))", "($ultimo === null || $en > RelativeTime::parseInstant($ultimo['at']))", 'empate del último resultado'),
 ('time-hours', 'RelativeTime.php', "return $ahora->getTimestamp() - $t->getTimestamp() <= $n * 3600;", "return $ahora->getTimestamp() - $t->getTimestamp() < $n * 3600;", '24 h justas'),
 ('time-zone', 'RelativeTime.php', "public const ZONE = 'America/Bogota';", "public const ZONE = 'UTC';", 'días de UTC'),
 ('time-future', 'RelativeTime.php', "        if ($t > $ahora) {\n            return false;\n        }\n        if ($unit === 'hours') {", "        if ($unit === 'hours') {", 'el futuro cuenta'),
 ('cal-tuesday', 'ContactCalendar.php', "2 => [480, 1140], 3 =>", "2 => [480, 1200], 3 =>", 'martes hasta las 20:00'),
 ('cal-holiday', 'ContactCalendar.php', "        if (ColombianHolidays::isHolidayDate($local->format('Y-m-d'))) {\n            $n1[] = self::HOLIDAY;", "        if (false) {\n            $n1[] = self::HOLIDAY;", 'sin festivos'),
 ('cal-slot-edge', 'ContactCalendar.php', "if ($cierra * 60 <= $desde) {", "if ($cierra * 60 < $desde) {", 'hueco en el cierre'),
 ('cal-own-n1', 'ContactCalendar.php', "if ($n1 === [] && $ownHours !== null && !", "if ($ownHours !== null && !", 'N5 también fuera de la ley'),
 ('cal-own-ignored', 'ContactCalendar.php', "        if ($ownHours !== null) {\n            $propia", "        if (false) {\n            $propia", 'el horario propio no estrecha'),
 ('budget-week', 'ContactBudget.php', "if ($c['at']->getTimestamp() >= $desdeSemana) {", "if ($c['at']->getTimestamp() > $desdeSemana) {", 'hace 7 días justos'),
 ('budget-48h', 'ContactBudget.php', "$ahora->getTimestamp() - $ultimo->getTimestamp() < $horas * 3600", "$ahora->getTimestamp() - $ultimo->getTimestamp() <= $horas * 3600", '48 h justas'),
 ('budget-medium', 'ContactBudget.php', "if ($c['medium'] !== $medium && ($otroMedio === null", "if ($c['medium'] === $medium && ($otroMedio === null", 'regla de un canal al revés'),
 ('budget-collections', 'ContactBudget.php', "$purpose === OutreachCatalog::PURPOSE_COLLECTIONS ? self::COLLECTIONS_CHANNEL : self::CROSS_CHANNEL", "$purpose === OutreachCatalog::PURPOSE_COMMERCIAL ? self::COLLECTIONS_CHANNEL : self::CROSS_CHANNEL", 'código de cobranza'),
 ('budget-h9-hours', 'ContactBudget.php', "$horas = max($horas, $config['h9_min_hours']);", "$horas = min($horas, $config['h9_min_hours']);", 'el H9 afloja las horas'),
 ('budget-replies', 'ContactBudget.php', "            if (! $cuenta) {\n                continue;\n            }", "", 'cuentan las respuestas'),
 ('ladder-minor', 'PolicyLadder.php', "if ($in['subject']['isMinor'] === true) {", "if ($in['subject']['isMinor'] !== false) {", 'menor desconocido inferido'),
 ('ladder-latch-medium', 'PolicyLadder.php', "if ($medio === OutreachCatalog::MEDIUM_WHATSAPP && $b['ultronAbortLatch']) {", "if ($b['ultronAbortLatch']) {", 'freno de ULTRON en voz'),
 ('ladder-wait-release', 'PolicyLadder.php', "'waitsForRelease' => $nivel === 'N2'", "'waitsForRelease' => $nivel === 'N1'", 'N2 retiene'),
 ('ladder-shadow', 'PolicyLadder.php', "if ($sombra && in_array($motivo, self::SHADOW_WARNINGS, true)) {", "if (false) {", 'sombra sin avisos'),
 ('ladder-temporal', 'PolicyLadder.php', "$temporal = array_diff($motivos, self::TEMPORAL) === [];", "$temporal = true;", 'todo aplaza'),
 ('ladder-overdue', 'PolicyLadder.php', "return $cartera['overdueDaysMax'] !== null && $cartera['overdueDaysMax'] > $dias;", "return $cartera['overdueDaysMax'] !== null && $cartera['overdueDaysMax'] >= $dias;", 'a los N justos bloquea'),
 ('ladder-overdue-strategy', 'PolicyLadder.php', ": min($umbral['serverDays'], $umbral['strategyDays']);", ": $umbral['strategyDays'];", 'la estrategia afloja el umbral'),
 ('ladder-n7-window', 'PolicyLadder.php', "if ($hueco === null || ($fin !== null && $hueco >= RelativeTime::parseInstant($fin))) {", "if ($hueco === null || ($fin !== null && $hueco > RelativeTime::parseInstant($fin))) {", 'llamada en el fin de la ventana'),
 ('ladder-catalog', 'PolicyLadder.php', "        if (! $s['catalogCompatible']) {", "        if (false) {", 'catálogo incompatible pasa'),
 ('ladder-own-n5', 'PolicyLadder.php', "if (ContactCalendar::window($ahora, $in['ownHours'])['n5'] !== []) {", "if (false) {", 'horario propio no aplaza'),
 ('ladder-retry-schedule', 'PolicyLadder.php', "$reintento = ContactCalendar::nextSlot($desde, $in['ownHours'], $in['strategy']['schedule']);", "$reintento = ContactCalendar::nextSlot($desde, null, null);", 'retryAt sin el horario propio ni el de la estrategia'),
 ('sel-pref', 'ChannelSelector.php', "if (array_filter($suyos, $permitido) !== []) {", "if ($suyos !== []) {", 'preferencia sobre negados'),
 ('sel-earliest', 'ChannelSelector.php', "($antes === null || $d['retryAt'] < $antes)", "($antes === null || $d['retryAt'] > $antes)", 'el aplazamiento más tarde'),
 ('prio-purpose', 'PriorityArbiter.php', "return OutreachCatalog::PURPOSE_RANK[$b['purpose']] <=> OutreachCatalog::PURPOSE_RANK[$a['purpose']]", "return OutreachCatalog::PURPOSE_RANK[$a['purpose']] <=> OutreachCatalog::PURPOSE_RANK[$b['purpose']]", 'commercial gana'),
 ('prio-uuid', 'PriorityArbiter.php', "?: strcmp($a['uuid'], $b['uuid']);", "?: strcmp($b['uuid'], $a['uuid']);", 'desempate al revés'),
 ('prio-reason', 'PriorityArbiter.php', "$c['purpose'] === $ganadora['purpose'] ? self::LOWER_PRIORITY : self::PURPOSE_CONFLICT", "self::LOWER_PRIORITY", 'conflicto de finalidad'),
 ('out-low', 'OutcomeTable.php', "public const CLASSIFIER_LOW = 50;", "public const CLASSIFIER_LOW = 40;", 'umbral bajo'),
 ('out-optout', 'OutcomeTable.php', "if ($outcome !== self::OPTED_OUT && is_string($pedida)", "if (is_string($pedida)", 'la estrategia anula opted_out'),
 ('out-retry', 'OutcomeTable.php', "if ($transicion === self::RETRY_STEP && $attempt > (self::RETRY_MAX[$outcome] ?? 0)) {", "if ($transicion === self::RETRY_STEP && $attempt >= (self::RETRY_MAX[$outcome] ?? 0)) {", 'un reintento menos'),
 ('out-classifier-safe', 'OutcomeTable.php', "$permitidas = $clasificador ? array_values(array_intersect($fila['allowed'], self::CLASSIFIER_SAFE_TRANSITIONS)) : $fila['allowed'];", "$permitidas = $fila['allowed'];", 'la IA avanza'),
 ('fu-delay', 'FollowUpMachine.php', "$desde = max($ahora, self::plus($base, $retraso));", "$desde = $ahora;", 'acorta el retraso'),
 ('fu-touches', 'FollowUpMachine.php', "if ($e['touches'] >= (is_int(", "if ($e['touches'] > (is_int(", 'un contacto de más'),
 ('fu-wait-status', 'FollowUpMachine.php', "'status' => $transicion === OutcomeTable::WAIT ? 'waiting' : 'active',", "'status' => 'active',", 'wait sin waiting'),
 ('fu-window', 'FollowUpMachine.php', "if ($hueco === null || $hueco >= RelativeTime::parseInstant($e['windowEndsAt'])) {", "if ($hueco === null || $hueco > RelativeTime::parseInstant($e['windowEndsAt'])) {", 'trabajo en el fin de la ventana'),
 ('fu-callback', 'FollowUpMachine.php', "            $desde = max($desde, RelativeTime::parseInstant($extras['callbackAt']));", "", 'ignora la hora pedida'),
 ('sc-purpose', 'StrategyContent.php', "if ($finalidad !== null && ! in_array($finalidad, $evento['purposes'], true)) {", "if (false) {", 'disparador de otra finalidad'),
 ('sc-fact-purpose', 'StrategyContent.php', "if ($hecho !== null && ! in_array($finalidad, $hecho['purposes'], true)) {", "if (false) {", 'cartera en comercial'),
 ('sc-template-missing', 'StrategyContent.php', "                $bien = $this->failFalse(\"{$r}.{$canal}\", 'template_missing');", "", 'falta de plantilla'),
 ('sc-waitfor', 'StrategyContent.php', "        } elseif ($hayEspera) {", "        } elseif (false) {", 'wait sin waitFor'),
 ('sc-schedule-legal', 'StrategyContent.php', "if ($hasta <= $abre || $desde >= $cierra) {", "if ($hasta < $abre || $desde > $cierra) {", 'franja tocando el borde'),
 ('sc-limit-commercial', 'StrategyContent.php', "$opcionales = $finalidad === OutreachCatalog::PURPOSE_COMMERCIAL ? self::LIMITS_OPTIONAL : [];", "$opcionales = self::LIMITS_OPTIONAL;", 'umbral en cobranza'),
 ('sc-params-empty', 'StrategyContent.php', "return $canonicos === [] ? ['event' => $t['event']] : ['event' => $t['event'], 'params' => $canonicos];", "return ['event' => $t['event'], 'params' => $canonicos];", 'params vacíos en la huella'),
 ('chain-label', 'StrategyChainSignature.php', "public const SUBKEY_LABEL = 'ultron-studio-strategy-chain';", "public const SUBKEY_LABEL = 'ultron-studio-voice-chain';", 'otra subclave'),
 ('chain-genesis', 'StrategyChainSignature.php', "if (! hash_equals($previous === null ? self::GENESIS_PREV_HASH : (string) $previous['entryHash'], $e['prevHash'])) {", "if ($previous !== null && ! hash_equals((string) $previous['entryHash'], $e['prevHash'])) {", 'génesis sin ceros'),
 ('chain-canonical', 'StrategyChainSignature.php', "        if ($canonico !== $message) {\n            return 'not_canonical';\n        }", "", 'bytes no canónicos'),
 ('chain-approval', 'StrategyChainSignature.php', "if ($conAprobacion ? ! self::uuid($e['approvedByPerson']) : $e['approvedByPerson'] !== null) {", "if ($conAprobacion && ! self::uuid($e['approvedByPerson'])) {", 'aprobación en una pausa'),
 ('json-sort', 'CanonicalJson.php', "sort($claves, SORT_STRING);", "sort($claves);", 'orden numérico de claves'),
 ('json-float', 'CanonicalJson.php', "            throw new InvalidArgumentException(\"El JSON canónico no admite decimales ({$path}).\");", "            return json_encode($value);", 'acepta decimales'),
 ('trace-token', 'DecisionTrace.php', "private const TOKEN = '/^[A-Za-z_][A-Za-z0-9_.:\\-\\/]{0,127}$/D';", "private const TOKEN = '/^[A-Za-z_ ][A-Za-z0-9_.:\\-\\/ +@]{0,127}$/D';", 'deja pasar frases y correos'),
 ('cat-generation', 'OutreachCatalog.php', "'generation' => is_int($valor) && $valor >= 0,", "'generation' => is_int($valor) && $valor >= 1,", 'generación 0'),
 ('cat-keyversion', 'OutreachCatalog.php', "} elseif (($e['keyVersion'] ?? null) !== $vigente['keyVersion']) {", "} elseif (false) {", 'keyVersion distinta'),
 ('cat-range', 'OutreachCatalog.php', "$h('membership.days_to_expiry', 'membership', self::TYPE_INT, $rango('days', -3650, 3650,", "$h('membership.days_to_expiry', 'membership', self::TYPE_INT, $rango('days', -3651, 3650,", 'un dato del catálogo sin subir la versión'),
 ('snap-pii', 'FactSnapshot.php', "public const VALUE_PATTERN = '/^[A-Za-z][A-Za-z0-9_:.-]{0,63}$/D';", "public const VALUE_PATTERN = '/^.{1,64}$/D';", 'la foto acepta teléfonos y frases'),
 ('gate-exclusion-unknown', 'EnrollmentGate.php', "$x !== null && $x->value === null => self::INSUFFICIENT_FACTS,", "$x !== null && $x->value === null => self::ENROLL,", 'exclusión desconocida inscribe'),

 ('out-df-o4', 'OutcomeTable.php', "if ($clasificador && ! in_array($transicion, self::CLASSIFIER_SAFE_TRANSITIONS, true)) {", "if (false) {", 'DF-O4 sin recaída a end_exit'),
 ('ladder-overdue-known', 'PolicyLadder.php', "if ($cartera['known'] !== true) {", "if ($cartera['known'] === false) {", 'cartera desconocida (null) no bloquea'),
 ('ladder-overdue-purpose', 'PolicyLadder.php', "if ($in['purpose'] === OutreachCatalog::PURPOSE_COMMERCIAL && self::overdueBlocks(", "if (self::overdueBlocks(", 'el bloqueo por cartera en cobranza'),
 ('cal-own-into-n1', 'ContactCalendar.php', "if ($n1 === [] && ! self::insideAny($segundo, self::dayIntervals($dia, null, null))) {", "if ($n1 === [] && ! self::insideAny($segundo, self::dayIntervals($dia, $ownHours, null))) {", 'el horario propio vuelve a N1'),
 ('ladder-schedule-with-own', 'PolicyLadder.php', "if ($e['schedule'] !== null && ! ContactCalendar::allows($ahora, null, $e['schedule'])) {", "if ($e['schedule'] !== null && ! ContactCalendar::allows($ahora, $in['ownHours'], $e['schedule'])) {", 'fuera del propio también es fuera de la estrategia'),
 ('sc-limit-min', 'StrategyContent.php', "public const LIMITS_OPTIONAL = ['commercialBlockOverdueDays' => [0, 365]];", "public const LIMITS_OPTIONAL = ['commercialBlockOverdueDays' => [1, 365]];", 'umbral 0 rechazado'),
 ('budget-retry-own', 'ContactBudget.php', "$reintento = ContactCalendar::nextSlot($desde, null);", "$reintento = ContactCalendar::nextSlot($desde, ['weekdays' => [540, 1080], 'saturday' => [540, 780]]);", 'retryAt del presupuesto con horario propio'),
 ('budget-phone-or', 'ContactBudget.php', "if ($deHoy >= $porDia || $telefonoHoy >= $porDia) {", "if ($deHoy >= $porDia) {", 'el teléfono no ocupa el día'),
 ('budget-phone-refs', 'ContactBudget.php', "if (in_array($c['phoneRef'], $refs, true) && $en <= $ahora", "if ($en <= $ahora", 'cualquier teléfono (y el desconocido) cruza'),
 ('budget-phone-future', 'ContactBudget.php', "in_array($c['phoneRef'], $refs, true) && $en <= $ahora && RelativeTime", "in_array($c['phoneRef'], $refs, true) && RelativeTime", 'lo posterior a ahora cuenta'),
 ('budget-phone-day', 'ContactBudget.php', "&& RelativeTime::bogotaDate($en) === $hoy) {\n                $n++;", "&& $en->format('Y-m-d') === $hoy) {\n                $n++;", 'día UTC en vez del de Bogotá'),
 ('budget-phone-shape', 'ContactBudget.php', "if ($claves !== self::PHONE_KEYS || ! is_array($refs)", "if (! is_array($refs)", 'claves de más en el teléfono'),
 ('ladder-phone-pass', 'PolicyLadder.php', "$in['purpose'], $medio, $in['limits'], $in['phone']);", "$in['purpose'], $medio, $in['limits']);", 'la escalera no pasa el teléfono'),
 ('text-retry-template', 'DecisionTraceText.php', "'retryAt' => 'Se vuelve a intentar el {date} a las {time} (Bogotá).',", "'retryAt' => 'Se vuelve a intentar el {date} a las {time}.',", 'la frase del reintento sin zona'),
 ('text-own-hours', 'DecisionTraceText.php', "'calendar_outside_own_hours' => 'Fuera del horario propio del gimnasio (más estricto que la ley).',", "'calendar_outside_own_hours' => 'Fuera del horario permitido.',", 'el texto exportado cambia'),
 ('cat-session-date', 'OutreachCatalog.php', "'membership_end_date', 'session_date' => is_string($valor)", "'membership_end_date' => is_string($valor)", 'L9-29: la fecha de la sesión no se valida como fecha'),
 ('cat-class-keyversion', 'OutreachCatalog.php', "['commercial']), ['keyVersion' => 2]);", "['commercial']), ['keyVersion' => 1]);", 'L9-29: la clave cambia sin subir keyVersion'),
 ('cat-class-template', 'OutreachCatalog.php', "'cls:{class_id}:{session_date}'", "'cls:{class_session_id}'", 'L9-29: vuelve la plantilla antigua'),
]
def sha(p): return hashlib.sha256(open(p,'rb').read()).hexdigest()
res = []
if len(sys.argv) > 2 and sys.argv[2] == 'check':
    for (mid, f, orig, mut, que) in M:
        n = open(os.path.join(RAIZ, C, f)).read().count(orig)
        if n != 1: print('COUNT', n, mid, f)
    print('checked', len(M)); sys.exit(0)
SOLO = set(sys.argv[2].split(',')) if len(sys.argv) > 2 and sys.argv[2] not in ('check',) else None
for (mid, f, orig, mut, que) in M:
    if SOLO is not None and mid not in SOLO: continue
    p = os.path.join(RAIZ, C, f)
    texto = open(p).read()
    antes = sha(p)
    n = texto.count(orig)
    if n != 1:
        res.append({'id': mid, 'result': 'invalid', 'count': n}); print(mid, 'INVALID count', n, flush=True); continue
    open(p, 'w').write(texto.replace(orig, mut))
    try:
        lint = subprocess.run(['php', '-l', p], capture_output=True, text=True)
        if lint.returncode != 0:
            res.append({'id': mid, 'result': 'lint_error'}); print(mid, 'LINT', flush=True); continue
        t = subprocess.run(['php', '-d', 'memory_limit=2G', 'vendor/bin/phpunit', '--no-logging', 'tests/Unit/Outreach/Core'], cwd=RAIZ, capture_output=True, text=True, timeout=600)
        ultima = [l for l in t.stdout.splitlines() if l.startswith('OK') or l.startswith('Tests:')]
        muerto = t.returncode != 0
        res.append({'id': mid, 'file': f, 'what': que, 'result': 'killed' if muerto else 'SURVIVED', 'summary': ultima[-1] if ultima else t.stdout[-200:]})
        print(mid, 'killed' if muerto else 'SURVIVED', '|', ultima[-1] if ultima else '', flush=True)
    finally:
        open(p, 'w').write(texto)
        assert sha(p) == antes, 'no se restauró ' + p
json.dump(res, open(os.path.join(RAIZ, '..', 'out-mutaciones-b9core' + ('-parcial' if SOLO else '') + '.json'), 'w'), ensure_ascii=False, indent=1)
k = sum(1 for r in res if r['result'] == 'killed'); s = [r['id'] for r in res if r['result'] == 'SURVIVED']; inv = [r['id'] for r in res if r['result'] not in ('killed', 'SURVIVED')]
print(f'TOTAL {len(res)} killed={k} survived={len(s)} {s} invalid={inv}')
