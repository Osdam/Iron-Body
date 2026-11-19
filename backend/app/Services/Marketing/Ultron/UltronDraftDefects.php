<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptStamp;

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
 * enlaces o el cobro y retira frases). Nadie la llama todavía (ola 1).
 *
 * EN ESTE ORDEN, uno por código (§4.1, paso 4):
 *  (a) `plans_overview`, con {@see UltronPlansOverview::verify()}: solo si hay
 *      lista (`po`), el borrador no pide herramientas y se cumple la condición
 *      del commit para el panorama (intención de precio, la persona pregunta
 *      el precio, o el texto nombra menos de dos planes). Además, sin lista,
 *      `no_list` si aparece un `PLAN_k` (también con herramientas: en un turno
 *      de cobro quien llama pasa la lista vacía).
 *  (b) `register_tuteo`: el detector del tuteo sobre el texto expandido, solo
 *      con el tono firmado `profesional` (`sp.t`).
 *  (c) `length_over_cap`: la medida del texto expandido por encima del tope
 *      firmado (`sp.n`), cualquier exceso (también si al commit le bastaría
 *      quitar la pregunta final: §4.4).
 *  (d) `benefit_misattributed`: {@see UltronBenefitAttribution} sobre el texto
 *      expandido.
 * Sin versión del Studio (`sp.m` ≠ `studio`), ninguno: Legacy no cambia.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log; las notas no
 * llevan nada del entrante ni del borrador (solo marcas de la lista cerrada,
 * números y textos del catálogo).
 */
final class UltronDraftDefects
{
    /**
     * @param  array{reply_draft:string,intent:string,tools_requested?:list<string>|null}  $draft  el borrador SIN expandir, con su intención y sus herramientas
     * @param  string  $expanded  el mismo con sus marcadores sustituidos (lo que mediría el commit)
     * @param  array<string,mixed>  $sp  el `sp` firmado en el token
     * @param  list<int>  $po  la lista firmada; vacía si no se ofreció o si es un turno de cobro
     * @param  array<int,array<string,mixed>>  $catalog  `activePlans()`
     * @param  string  $inbound  el texto de la persona (solo para saber si pregunta el precio)
     * @return list<array{code:string,detail:?string,issue:string,note:string}>
     */
    public static function of(array $draft, string $expanded, array $sp, array $po, array $catalog, string $inbound): array
    {
        if (($sp['m'] ?? null) !== UltronPromptStamp::MODE_STUDIO) {
            return [];
        }
        $raw = (string) ($draft['reply_draft'] ?? '');
        $po = array_values(array_map('intval', $po));
        $defectos = [];

        // (a) La lista de planes.
        $conHerramientas = (array) ($draft['tools_requested'] ?? []) !== [];
        $detalle = null;
        if ($po === []) {
            $detalle = UltronPlansOverview::verify($raw, [], $catalog);
        } elseif (! $conHerramientas && (
            (string) ($draft['intent'] ?? '') === SalesIntents::PRICING_QUESTION
            || CommercialTurnPolicy::preguntaPorPrecio($inbound)
            || count(array_unique(CommercialTurnPolicy::plansNamedIn($expanded, $catalog))) < 2
        )) {
            $detalle = UltronPlansOverview::verify($raw, $po, $catalog);
        }
        if ($detalle !== null) {
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

        // (c) La longitud.
        $tope = UltronReplyFitter::cap($sp['n'] ?? null);
        if ($tope !== null && UltronReplyFitter::measure($expanded) > $tope) {
            $defectos[] = self::defecto(UltronRegenerationNotes::LENGTH_OVER_CAP, null, [
                'L' => UltronReplyFitter::measure($expanded), 'N' => $tope, 'M' => UltronPromptCompiler::composerTargetChars($tope),
            ]);
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

    /** @return array{code:string,detail:?string,issue:string,note:string} */
    private static function defecto(string $code, ?string $detail, array $params): array
    {
        return [
            'code' => $code,
            'detail' => $detail,
            'issue' => UltronRegenerationNotes::issue($code, $detail),
            'note' => UltronRegenerationNotes::note($code, $detail, $params),
        ];
    }

    /**
     * Cómo llamar al plan en la nota N-B: su marcador sin llaves (`PLAN_2`)
     * si el borrador lo nombra con él, o su nombre del catálogo.
     *
     * @param  list<int>  $po
     * @param  array<int,array<string,mixed>>  $catalog
     */
    private static function comoSeNombra(int $planId, string $raw, array $po, array $catalog): string
    {
        $k = array_search($planId, $po, true);
        if ($k !== false && $k < UltronPlansOverview::MAX_PLANS && preg_match('/\{\{\s*PLAN_'.($k + 1).'\s*\}\}/', $raw) === 1) {
            return 'PLAN_'.($k + 1);
        }
        foreach ($catalog as $p) {
            if ((int) $p['id'] === $planId) {
                return (string) ($p['name'] ?? '');
            }
        }

        return '';
    }
}
