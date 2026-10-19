<?php

namespace App\Services\UltronStudio;

/**
 * El modo `fake` del laboratorio (contrato H4 §A3): la fontanería y el smoke
 * SIN RED y SIN COSTE. No consulta ningún modelo:
 *  - el actor devuelve siempre la misma frase del laboratorio, recortada a la
 *    longitud máxima de la versión si se le da;
 *  - el juez responde `unclear` a todos los criterios y a todas las reglas
 *    que se le preguntaron, con «Modo de prueba: sin IA».
 * Así cada caso queda, como mucho, en `review`, y una ejecución `fake` no
 * tiene propuesta ni admite veredicto.
 *
 * La frase no dispara ninguna prueba determinista (una prueba lo comprueba):
 * un caso `fake` solo cae a `fail` si el problema está en el escenario, no en
 * la frase.
 */
final class StudioLabFakeClient implements StudioLabModelClient
{
    public const ACTOR_REPLY = 'Respuesta de ensayo del laboratorio: en modo de prueba no se consulta ningún modelo de IA.';

    public const JUDGE_NOTE = 'Modo de prueba: sin IA';

    /** @param  ?int  $maxChars  `persona.maxChars` del objetivo: la frase se recorta a eso */
    public function __construct(private readonly ?int $maxChars = null) {}

    public function complete(string $role, string $instructions, string $inputJson, array $schema, int $maxOutputTokens): array
    {
        StudioLabPrompts::schemaName($role);

        $salida = $role === self::ROLE_ACTOR
            ? ['reply' => $this->maxChars !== null && $this->maxChars > 0 ? mb_substr(self::ACTOR_REPLY, 0, $this->maxChars) : self::ACTOR_REPLY]
            : self::judge($inputJson);

        if ($salida === null || ! StudioLabPrompts::conforms($schema, $salida)) {
            return ['status' => self::STATUS_ERROR, 'output' => null, 'usage' => null, 'class' => self::CLASS_INVALID_OUTPUT, 'http' => null, 'latencyMs' => 0, 'exception' => null];
        }

        return [
            'status' => self::STATUS_OK,
            'output' => $salida,
            'usage' => ['inputTokens' => 0, 'outputTokens' => 0, 'reasoningTokens' => 0],
            'class' => null,
            'http' => null,
            'latencyMs' => 0,
            'exception' => null,
        ];
    }

    /**
     * `unclear` a todo lo que pregunta la entrada del juez; null si la entrada
     * no tiene la forma de esta versión de prompts.
     *
     * @return array{criteria:list<array<string,string>>,rules:list<array<string,string>>,risks:list<never>,summary:string}|null
     */
    private static function judge(string $inputJson): ?array
    {
        $objetivos = StudioLabPrompts::judgeTargets($inputJson);
        if ($objetivos === null) {
            return null;
        }

        return [
            'criteria' => array_map(static fn (string $id): array => ['id' => $id, 'verdict' => 'unclear', 'evidence' => '', 'reason' => self::JUDGE_NOTE], $objetivos['criteria']),
            'rules' => array_map(static fn (string $code): array => ['code' => $code, 'verdict' => 'unclear', 'reason' => self::JUDGE_NOTE], $objetivos['rules']),
            'risks' => [],
            'summary' => self::JUDGE_NOTE,
        ];
    }
}
