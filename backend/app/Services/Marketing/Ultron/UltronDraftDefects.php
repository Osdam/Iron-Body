<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptStamp;
use InvalidArgumentException;

/**
 * LOS DEFECTOS CORREGIBLES DE UN BORRADOR (cierre V2, apartado 4, «regenerar
 * antes de sustituir»; plan del arquitecto `plan-arquitecto-a4-regenerar.md`
 * §4.1, paso 4, y §4.4).
 *
 * Lo que la comprobación del servidor (`ai/check`, ola 4) le dirá a n8n de un
 * borrador del redactor: qué incumple de lo que el modelo puede corregir
 * reescribiendo, con el `issue` del revisor y la nota fija de cada defecto.
 * Usa las MISMAS funciones que el commit (el detector del tuteo, la medida del
 * tope, la regla del panorama, `plansNamedIn()`), para que lo que se marque
 * aquí sea lo que el commit habría descartado, recortado o violado; el commit
 * lo vuelve a comprobar todo sobre su texto final, que puede diferir (pega
 * enlaces o el cobro y retira frases). La llama la comprobación de solo
 * lectura ({@see UltronDraftCheck}, ola 4).
 *
 * EN ESTE ORDEN, uno por código (§4.1, paso 4):
 *  (a) `plans_overview`, con {@see UltronPlansOverview::verify()}: solo si hay
 *      lista (`po`), el borrador no pide herramientas y se cumple la condición
 *      del commit para el panorama (intención de precio, la persona pregunta
 *      el precio, o el texto nombra menos de dos planes). Además, sin lista,
 *      `no_list` si aparece un `PLAN_k` (también con herramientas: en un turno
 *      de cobro quien llama pasa la lista vacía). Y con lista, `unknown_marker`
 *      SIEMPRE que aparezca un `PLAN_k` que no es de ella, se cumpla o no esa
 *      condición (decisión del lead en el encargo de la ola 4, que cierra una
 *      laguna de §4.1, paso 4a): el commit rechaza ese turno con un 422 pase
 *      lo que pase, y es justo el defecto que una reescritura arregla.
 *  (b) `register_tuteo`: el detector del tuteo sobre el texto expandido, solo
 *      con el tono firmado `profesional` (`sp.t`).
 *  (c) `length_over_cap`: la medida del texto expandido por encima del tope
 *      firmado (`sp.n`), cualquier exceso (también si al commit le bastaría
 *      quitar la pregunta final: §4.4). Desde el compilador 6, en un turno en
 *      que el servidor ofreció la lista, contra el tope de la lista
 *      ({@see UltronReplyFitter::plansListCap()}; también con el tope de la
 *      línea base, sin `sp.n`), lo traiga o no el borrador (PT-17, L2: un
 *      solo tope por turno), y su nota y la de la lista van con esa cifra.
 *  (d) `benefit_misattributed`: {@see UltronBenefitAttribution} sobre el texto
 *      expandido.
 * Con la lista y el tope firmados, la nota de (a) cuando falta la lista o va
 * mezclada o repetida, y la de (c), van con el esqueleto y el presupuesto de la
 * lista ({@see self::listBudget()}; 22-11-2026). Los códigos no cambian.
 * Sin versión del Studio (`sp.m` ≠ `studio`), ninguno: Legacy no cambia.
 *
 * CON LA LISTA DEL SERVIDOR (`k` ≥ 8; PT-17, puerta 2, L1;
 * {@see UltronPlansOverview::serverList()}): (a) es la regla estricta de
 * {@see UltronPlansOverview::verify()} SIEMPRE que haya lista, sin la
 * condición de herramientas o precio, y sus notas y la de (c) son las de la
 * ranura ({@see UltronRegenerationNotes::serverNote()}), con lo que ocupa la
 * lista compuesta ({@see self::serverListBudget()}).
 *
 * Y DETRÁS, LAS REGLAS DE POLÍTICA CORREGIBLES (ola 4b, decisión L-3 del lead;
 * {@see self::ofPolicy()}): las seis de {@see UltronRegenerationNotes::POLICY_CODES}
 * que {@see CommercialTurnPolicy::violations()} marca con la política del
 * turno calculada como en el commit, sobre el texto que miraría el commit.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log; las notas no
 * llevan nada del entrante ni del borrador (solo marcas de la lista cerrada,
 * números y textos del catálogo).
 *
 * UNA NOTA NO TUMBA LA PUERTA (correcciones, C6; R-8 de seguridad): si el
 * nombre de un plan queda vacío al sanearlo, la nota lo llama por su marcador
 * (o «ese plan»); y si aun así una nota lanza, ese defecto va sin nota
 * (`note` vacía, que `merge()` no cuenta) en vez de que la puerta responda 500.
 */
final class UltronDraftDefects
{
    /**
     * Como mucho estos defectos en una comprobación, uno por código (ola 4b:
     * era 4, uno por cada código de §4.4). Los cuatro de §4.4 pueden coincidir;
     * de la política, como mucho cuatro a la vez: el saludo que vende va solo
     * con el modo recepción, que apaga `answer_first` (sin el dato y solo
     * preguntas no pueden saltar) y excluye el modo memoria; sin el dato no
     * salta en modo memoria (no lo exige); así que el máximo es la visita a
     * medias, el plan equivocado y dos de {sin el dato, solo preguntas,
     * memoria}. 4 + 4 = 8. El corte es defensivo: por construcción no actúa.
     */
    public const MAX_DEFECTS = 8;

    /**
     * @param  array{reply_draft:string,intent:string,tools_requested?:list<string>|null}  $draft  el borrador SIN expandir, con su intención y sus herramientas
     * @param  string  $expanded  el mismo con sus marcadores sustituidos (lo que mediría el commit)
     * @param  array<string,mixed>  $sp  el `sp` firmado en el token
     * @param  list<int>  $po  la lista firmada; vacía si no se ofreció o si es un turno de cobro
     * @param  array<int,array<string,mixed>>  $catalog  `activePlans()`
     * @param  string  $inbound  el texto de la persona (solo para saber si pregunta el precio)
     * @param  array<int,string>  $listTexts  plan_id → su texto ya expandido ({@see UltronPlansOverview::item()}) de los planes de `po` que se venden AHORA; el que falta cuenta como su marcador literal (así lo deja la expansión)
     * @param  array<int,array<string,mixed>>|null  $knownPlans  con la lista del servidor, los planes del catálogo que conoce el servidor, se vendan o no (`knownPlans()`; revisión final, I1-a): el que no se vende ahora no se escribe a mano
     * @return list<array{code:string,detail:?string,issue:string,note:string}>
     */
    public static function of(array $draft, string $expanded, array $sp, array $po, array $catalog, string $inbound, array $listTexts = [], ?array $knownPlans = null): array
    {
        if (($sp['m'] ?? null) !== UltronPromptStamp::MODE_STUDIO) {
            return [];
        }
        $raw = (string) ($draft['reply_draft'] ?? '');
        $po = array_values(array_map('intval', $po));
        $defectos = [];
        // El tope de ESTE turno: el de la lista si el servidor la ofreció (PT-17, L2, {@see UltronReplyFitter::plansListCap()}); si no, el publicado.
        $tope = UltronReplyFitter::plansListCap($sp, $po) ?? UltronReplyFitter::cap($sp['n'] ?? null);
        // Con la lista del servidor (`k` ≥ 8; PT-17, puerta 2, L1), (a) y (c) van con sus reglas y sus notas.
        $servidor = UltronPlansOverview::serverList($sp);
        $deLaLista = $servidor && $po !== [] && $tope !== null ? self::serverListBudget($po, $listTexts, $tope) : null;

        /*
         * EL PRESUPUESTO DE LA LISTA (corrección (1) del turno 2 de la tercera
         * prueba real): con la lista Y el tope firmados, las notas de la lista
         * (falta, mezclada o repetida) y la de la longitud van en su variante
         * con el esqueleto y lo que ocupan los marcadores ofrecidos ya
         * expandidos. Sin una de las dos cosas, las notas de siempre.
         */
        $presupuesto = null;
        if (! $servidor && $po !== [] && $tope !== null) {
            $textos = [];
            foreach (array_slice($po, 0, UltronPlansOverview::MAX_PLANS) as $i => $id) {
                $textos[] = $listTexts[$id] ?? UltronPlansOverview::marker($i + 1);
            }
            $presupuesto = ['K' => count($textos)] + self::listBudget($textos, $tope);
        }

        // (a) La lista de planes.
        $conHerramientas = (array) ($draft['tools_requested'] ?? []) !== [];
        $detalle = null;
        if ($servidor) {
            /*
             * Con la lista del servidor, la estricta SIEMPRE que haya lista,
             * sin la condición de herramientas o precio: se pide reescribirla
             * en todo turno de lista. Lo que hace después el commit depende de
             * si ve el panorama: en el turno de lista sin herramientas (la
             * persona pidió los planes), una lista que no vale no sale (salta
             * el panorama y sale la lista fija); con herramientas, o si el
             * commit no ve el panorama, el texto sale como esté y solo lo para
             * la red de marcadores si lleva algo con forma de marcador (revisión
             * de seguridad de la puerta 2, M3). Sin lista, solo `no_list`. Las
             * notas, las de la ranura, con lo que ocupa la lista compuesta si
             * falta o va mezclada.
             */
            $delServidor = UltronPlansOverview::verify($raw, $po, $catalog, true, $knownPlans);
            if ($delServidor !== null) {
                $params = in_array($delServidor, [UltronPlansOverview::MISSING, UltronPlansOverview::MIXED], true) ? ($deLaLista ?? []) : [];
                $defectos[] = self::defecto(UltronRegenerationNotes::PLANS_OVERVIEW, $delServidor, $params, false, true);
            }
        } elseif ($po === []) {
            $detalle = UltronPlansOverview::verify($raw, [], $catalog);
        } elseif (! $conHerramientas && (
            (string) ($draft['intent'] ?? '') === SalesIntents::PRICING_QUESTION
            || CommercialTurnPolicy::preguntaPorPrecio($inbound)
            || count(array_unique(CommercialTurnPolicy::plansNamedIn($expanded, $catalog))) < 2
        )) {
            $detalle = UltronPlansOverview::verify($raw, $po, $catalog);
        } elseif (UltronPlansOverview::verify($raw, $po, $catalog) === UltronPlansOverview::UNKNOWN_MARKER) {
            // Fuera de la condición, lo demás de la lista no se mira (como en el commit); el marcador inexistente, sí.
            $detalle = UltronPlansOverview::UNKNOWN_MARKER;
        }
        if ($detalle !== null && $presupuesto !== null && in_array($detalle, [UltronPlansOverview::MISSING, UltronPlansOverview::MIXED], true)) {
            $defectos[] = self::defecto(UltronRegenerationNotes::PLANS_OVERVIEW, $detalle, $presupuesto, true);
        } elseif ($detalle !== null) {
            $params = $detalle === UltronPlansOverview::UNKNOWN_MARKER ? ['K' => count($po)] : [];
            $defectos[] = self::defecto(UltronRegenerationNotes::PLANS_OVERVIEW, $detalle, $params);
        }

        // (b) El tuteo.
        $tono = is_string($sp['t'] ?? null) ? $sp['t'] : null;
        if (UltronFormalReplies::aplica($tono)) {
            $marcas = UltronRegisterDetector::tuteo($expanded, $catalog);
            if ($marcas !== []) {
                $defectos[] = self::defecto(UltronRegenerationNotes::REGISTER_TUTEO, null, ['MARCAS' => $marcas]);
            }
        }

        // (c) La longitud; con la lista, con su presupuesto (con la lista del servidor, su nota: no se quitan planes).
        if ($tope !== null && UltronReplyFitter::measure($expanded) > $tope) {
            $params = ['L' => UltronReplyFitter::measure($expanded), 'N' => $tope, 'M' => UltronPromptCompiler::composerTargetChars($tope)];
            $defectos[] = match (true) {
                $deLaLista !== null => self::defecto(UltronRegenerationNotes::LENGTH_OVER_CAP, null, ['L' => $params['L']] + $deLaLista, false, true),
                $presupuesto !== null => self::defecto(UltronRegenerationNotes::LENGTH_OVER_CAP, null, $params + ['X' => $presupuesto['X'], 'R' => $presupuesto['R']], true),
                default => self::defecto(UltronRegenerationNotes::LENGTH_OVER_CAP, null, $params),
            };
        }

        // (d) El beneficio de otro plan: el primero, con el marcador si la frase nombra el plan por él.
        $mal = UltronBenefitAttribution::misattributed($expanded, $catalog)[0] ?? null;
        if ($mal !== null) {
            $defectos[] = self::defecto(UltronRegenerationNotes::BENEFIT_MISATTRIBUTED, null, [
                'PLAN' => self::comoSeNombra($mal['plan_id'], $raw, $po, $catalog),
                'BENEFICIO' => $mal['benefit'],
            ]);
        }

        return $defectos;
    }

    /**
     * LAS REGLAS DE POLÍTICA QUE EL MODELO PUEDE CORREGIR (ola 4b; decisión
     * L-3 del lead). Las seis de {@see UltronRegenerationNotes::POLICY_CODES}
     * que {@see CommercialTurnPolicy::violations()} marca, en ese orden, cada
     * una con su `issue` y su nota fija. El saludo repetido no entra: el commit
     * lo arregla recortando la fórmula (y aquí, igual que allí, se recorta y se
     * vuelve a mirar, para que lo demás se juzgue sobre el mismo texto).
     *
     * EL MISMO TEXTO QUE MIRA EL COMMIT. El commit llama a `violations()`
     * después de dos recortes, y aquí se hacen los dos con las mismas
     * funciones: (1) con tope firmado, si el texto se pasa y quitándole la
     * pregunta final cabe (regla F1, {@see UltronReplyFitter::draft()}), se
     * mira sin ella; (2) con «profesional», si el tuteo está SOLO en la
     * pregunta final y el turno lleva un bloque que pega Laravel (los enlaces
     * de la app; `$laravelBlock`), se mira sin ella. Si la longitud lo
     * descartaría, no hay recorte por registro (como en el commit).
     *
     * En un turno de lista (compilador 6, `$plansListCap` de
     * {@see UltronReplyFitter::plansListCap()} con la lista del turno; PT-17,
     * L2: lo traiga o no el borrador), la longitud se mide contra ese tope y
     * SIN quitar la pregunta final ({@see UltronReplyFitter::plansListDraft()}),
     * como en el commit. Sin él (null), la regla de siempre.
     *
     * @param  string  $expanded  el borrador expandido (lo que mediría el commit)
     * @param  array<string,mixed>  $sp  el `sp` firmado en el token
     * @param  array<string,mixed>  $policy  la política del turno ({@see CommercialTurnPolicy::decide()}, como en el commit)
     * @param  array<int,array<string,mixed>>  $catalog  `activePlans()`
     * @param  list<string>  $facts  los hechos del gimnasio ({@see CommercialTurnPolicy::knownFacts()}); vacío si la política no los exige
     * @param  bool  $laravelBlock  el texto lleva los enlaces de la app que pega Laravel
     * @param  int|null  $plansListCap  el tope de la lista de este turno, o null
     * @return list<array{code:string,detail:null,issue:string,note:string}>
     */
    public static function ofPolicy(string $expanded, array $sp, array $policy, array $catalog, array $facts, bool $laravelBlock = false, ?int $plansListCap = null): array
    {
        if (($sp['m'] ?? null) !== UltronPromptStamp::MODE_STUDIO) {
            return [];
        }
        // El tono como lo lee el commit (`turnoDelStudio()`): uno del catálogo del compilador, o ninguno.
        $tono = is_string($sp['t'] ?? null) && in_array($sp['t'], UltronPromptCompiler::TONES, true) ? $sp['t'] : null;
        $texto = $expanded;

        // (1) La longitud: la regla F1 quita la pregunta final si así cabe; con la lista, no se recorta.
        $descartado = false;
        $tope = $plansListCap ?? UltronReplyFitter::cap($sp['n'] ?? null);
        if ($tope !== null) {
            $recorte = $plansListCap !== null ? UltronReplyFitter::plansListDraft($texto, $tope) : UltronReplyFitter::draft($texto, $tope);
            $descartado = $recorte === null;
            if ($recorte !== null && $recorte['dropped'] > 0) {
                $texto = $recorte['text'];
            }
        }

        // (2) El registro: con el bloque de Laravel, la pregunta final que tutea se quita si es lo único que tutea.
        if ($laravelBlock && ! $descartado && UltronFormalReplies::aplica($tono) && UltronRegisterDetector::tuteo($texto, $catalog) !== []) {
            $sinPregunta = UltronReplyFitter::withoutFinalQuestion($texto);
            if ($sinPregunta !== null && UltronRegisterDetector::tuteo($sinPregunta, $catalog) === []) {
                $texto = $sinPregunta;
            }
        }

        $violaciones = CommercialTurnPolicy::violations($texto, $policy, $catalog, $facts, $tono);
        if (in_array(CommercialTurnPolicy::VIOLACION_SALUDO_REPETIDO, $violaciones, true)) {
            $violaciones = CommercialTurnPolicy::violations(CommercialTurnPolicy::withoutRepeatedGreeting($texto), $policy, $catalog, $facts, $tono);
        }

        $defectos = [];
        foreach (UltronRegenerationNotes::POLICY_CODES as $codigo) {
            if (in_array($codigo, $violaciones, true)) {
                $defectos[] = self::defecto($codigo, null, []);
            }
        }

        return $defectos;
    }

    /**
     * «LISTA FIRME» (PT-17, puerta 1, L4; decisión P-1 del propietario del
     * 24-11-2026): ¿es este borrador la lista limpia de un turno de lista?
     * Sí si el turno es de lista ({@see UltronReplyFitter::plansListCap()}
     * con la lista del turno: Studio, compilador 6 o posterior, tope y lista
     * ofrecida; vacía en un turno de cobro), el borrador SIN expandir trae una
     * lista válida ({@see UltronPlansOverview::verify()}: dos planes o más,
     * solo con sus marcadores) y el servidor no le encuentra NINGÚN defecto
     * (`$defects`, los de {@see self::of()} y {@see self::ofPolicy()}).
     *
     * La usan `ai/check` (con esto contesta `may_rewrite=false` y el motivo
     * `list_final`: no se pide un tercer borrador para un segundo que ya vale)
     * y el commit (un rechazo blando del revisor sobre ese borrador no produce
     * respaldo; {@see CriticContract::isSoftFail()}), en producción y en el
     * laboratorio. Pura.
     *
     * Con la lista del servidor (`k` ≥ 8; PT-17, puerta 2, L1), la lista
     * válida es la de la regla estricta: {@see UltronPlansOverview::verify()}
     * con `serverList`. Y solo la forma A entera y en su orden o la forma B
     * CON la ranura (revisión final, I1-b): sin la ranura, el revisor juzgó
     * un borrador sin la lista que pondrá el servidor (riesgo 1 de §6), así
     * que su rechazo blando se respeta y `ai/check` deja reescribir.
     *
     * @param  string  $raw  el borrador SIN expandir
     * @param  array<string,mixed>  $sp  el `sp` firmado en el token
     * @param  list<int>  $po  la lista del turno (vacía sin lista o en un turno de cobro)
     * @param  array<int,array<string,mixed>>  $catalog  `activePlans()`
     * @param  list<array<string,mixed>>  $defects  lo que la comprobación le encuentra
     */
    public static function isListFinal(string $raw, array $sp, array $po, array $catalog, array $defects): bool
    {
        $servidor = UltronPlansOverview::serverList($sp);

        return $defects === []
            && $po !== []
            && UltronReplyFitter::plansListCap($sp, $po) !== null
            && UltronPlansOverview::verify($raw, $po, $catalog, $servidor) === null
            && (! $servidor || UltronPlansOverview::shape($raw) === UltronPlansOverview::SHAPE_MARKERS || UltronPlansOverview::hasSlot($raw));
    }

    /**
     * LA CIFRA DE LA LISTA QUE SE LE DICE AL MODELO: el ÚNICO sitio donde se
     * calcula (corrección (1) del turno 2 de la tercera prueba real).
     *
     * `X` = lo que miden, ya expandidos y con la medida del tope
     * ({@see UltronReplyFitter::measure()}), los marcadores ofrecidos, sumados;
     * `M` = lo que se le pide al redactor con ese tope
     * ({@see UltronPromptCompiler::composerTargetChars()}); `R` = lo que queda
     * de `M` para todo lo demás, nunca negativo.
     *
     * LO QUE REVELA (para `security`): el modelo ya ve los nombres y las
     * duraciones de la lista (`plans_overview.plans`), pero ningún precio. Con
     * `X` exacto puede restar el formato conocido («Nombre ($… COP)») y saber
     * cuánto miden JUNTAS las cifras de los precios: su orden de magnitud
     * agregado (con el catálogo de la prueba, 65.000 + 80.000 + 180.000 →
     * 5 + 5 + 6 cifras). No la cifra de ninguno ni la de cada plan. `R` sale
     * de `X` y de `M`, que el modelo ya sabe, así que no revela más. Si
     * `security` pide redondear (por ejemplo, a la decena de arriba), se
     * cambia SOLO la línea de `$x` (y `R` se recalcula sola con la cifra
     * redondeada, que es la que verá el modelo).
     *
     * @param  list<string>  $textos  el texto expandido de cada marcador ofrecido (o el marcador literal, si su plan ya no se vende)
     * @return array{X:int,R:int,M:int}
     */
    public static function listBudget(array $textos, int $tope): array
    {
        $x = array_sum(array_map(UltronReplyFitter::measure(...), $textos));
        $m = UltronPromptCompiler::composerTargetChars($tope);

        return ['X' => $x, 'R' => max(0, $m - $x), 'M' => $m];
    }

    /**
     * LO QUE OCUPA LA LISTA QUE COMPONE LARAVEL (PT-17, puerta 2, L1), para
     * las notas con la lista del servidor: `X` = la medida de la lista
     * compuesta ({@see UltronPlansOverview::compose()}), `N` = el tope de la
     * lista del turno y `R` = max(0, N − X), lo que queda para la prosa. Si
     * la lista no se puede componer (un plan ya no se vende), la de los que
     * quedan: la nota es una ayuda y el commit dará la lista fija igual.
     * Revela lo mismo que {@see self::listBudget()} (la medida agregada de la
     * lista con sus precios, nunca la cifra de ninguno).
     *
     * @param  list<int>  $po  la lista del turno
     * @param  array<int,string>  $listTexts  plan_id → su texto, de los vendibles ahora
     * @return array{X:int,N:int,R:int}
     */
    public static function serverListBudget(array $po, array $listTexts, int $tope): array
    {
        $compuesta = UltronPlansOverview::signedList($po) === null ? null : UltronPlansOverview::compose($po, $listTexts);
        $compuesta ??= implode(UltronPlansOverview::SEPARATOR, array_values(array_filter(array_map(static fn (int $id): ?string => $listTexts[$id] ?? null, $po))));
        $x = UltronReplyFitter::measure($compuesta);

        return ['X' => $x, 'N' => $tope, 'R' => max(0, $tope - $x)];
    }

    /**
     * Si la nota lanza (un parámetro que no es el de su entrada), el defecto va
     * igual, sin nota: n8n sabe por qué reescribir por el código y el `issue`,
     * y la puerta no responde 500 (C6). El código y el detalle son constantes
     * de aquí, así que `issue()` no puede lanzar.
     *
     * Con `$delServidor` (la lista del servidor, `k` ≥ 8), la nota de la
     * ranura ({@see UltronRegenerationNotes::serverNote()}).
     *
     * @return array{code:string,detail:?string,issue:string,note:string}
     */
    private static function defecto(string $code, ?string $detail, array $params, bool $conPresupuesto = false, bool $delServidor = false): array
    {
        try {
            $nota = match (true) {
                $delServidor => UltronRegenerationNotes::serverNote($code, $detail, $params),
                $conPresupuesto => UltronRegenerationNotes::budgetNote($code, $detail, $params),
                default => UltronRegenerationNotes::note($code, $detail, $params),
            };
        } catch (InvalidArgumentException) {
            // A propósito: la nota es una ayuda; el defecto se dice sin ella (quien llama lo deja en el log).
            $nota = '';
        }

        return [
            'code' => $code,
            'detail' => $detail,
            'issue' => UltronRegenerationNotes::issue($code, $detail),
            'note' => $nota,
        ];
    }

    /**
     * Cómo llamar al plan en la nota N-B: su marcador sin llaves (`PLAN_2`)
     * si el borrador lo nombra con él, o su nombre del catálogo. Si el nombre
     * queda vacío al sanearlo (C6; un nombre hecho solo de llaves), su
     * marcador si es de la lista y, si no, «ese plan».
     *
     * @param  list<int>  $po
     * @param  array<int,array<string,mixed>>  $catalog
     */
    private static function comoSeNombra(int $planId, string $raw, array $po, array $catalog): string
    {
        $k = array_search($planId, $po, true);
        $marcador = $k !== false && $k < UltronPlansOverview::MAX_PLANS ? 'PLAN_'.($k + 1) : null;
        if ($marcador !== null && preg_match('/\{\{\s*'.$marcador.'\s*\}\}/', $raw) === 1) {
            return $marcador;
        }
        $nombre = '';
        foreach ($catalog as $p) {
            if ((int) $p['id'] === $planId) {
                $nombre = (string) ($p['name'] ?? '');

                break;
            }
        }

        return UltronRegenerationNotes::cleanText($nombre) !== '' ? $nombre : ($marcador ?? 'ese plan');
    }
}
