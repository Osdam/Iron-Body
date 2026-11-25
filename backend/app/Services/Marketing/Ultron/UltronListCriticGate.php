<?php

namespace App\Services\Marketing\Ultron;

/** Reuse Gate 1's soft-fail policy before retrying a clean first list draft. */
final class UltronListCriticGate
{
    public static function allows(array $critic, bool $listTurn, mixed $check): bool
    {
        if (($critic['verdict'] ?? null) === 'pass') {
            return true;
        }
        $body = is_array($check) ? ($check['body'] ?? null) : null;
        if (is_string($body)) {
            $body = json_decode($body, true);
        }

        return $listTurn && is_array($check) && ($check['statusCode'] ?? null) === 200 && is_array($body)
            && ($body['ok'] ?? null) === true && ($body['contract'] ?? null) === UltronDraftCheck::CONTRACT
            && ($body['mode'] ?? null) === 'studio' && ($body['attempt'] ?? null) === 1
            && ($body['pass'] ?? null) === true && ($body['defects'] ?? null) === []
            && ($body['budget']['reason'] ?? null) === 'list_final'
            && CriticContract::isSoftFail($critic);
    }

    public static function javascript(): string
    {
        $dimensions = json_encode(CriticContract::DIMENSIONS, JSON_THROW_ON_ERROR);
        $veto = json_encode(CriticContract::VETO_DIMENSIONS, JSON_THROW_ON_ERROR);
        $limit = CriticContract::MAX_ISSUES;
        $contract = json_encode(UltronDraftCheck::CONTRACT, JSON_THROW_ON_ERROR);

        return '(function allowsUltronListCritic(c, listTurn, check) {'
            .'if (c && c.verdict === "pass") return true;'
            .'let b = check && check.body; if (typeof b === "string") { try { b = JSON.parse(b); } catch (_) { return false; }}'
            .'if (!listTurn || !check || check.statusCode !== 200 || !b || b.ok !== true || b.contract !== '.$contract
            .' || b.mode !== "studio" || b.attempt !== 1 || b.pass !== true || !Array.isArray(b.defects) || b.defects.length'
            .' || !b.budget || b.budget.reason !== "list_final") return false;'
            .'const dims = '.$dimensions.', veto = '.$veto.';'
            .'if (!c || c.verdict !== "fail" || (c.hard_fail != null && c.hard_fail !== "")) return false;'
            .'const issues = c.issues === undefined ? [] : c.issues;'
            .'return Array.isArray(issues) && issues.length < '.$limit
            .' && issues.every(i => typeof i === "string" && dims.includes(i) && !veto.includes(i));})';
    }
}
