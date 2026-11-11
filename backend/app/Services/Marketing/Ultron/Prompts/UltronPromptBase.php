<?php

namespace App\Services\Marketing\Ultron\Prompts;

/**
 * LA BASE DE LOS PROMPTS DE ULTRON (Enmienda M-1 del contrato H5; plan de
 * motores §B.3).
 *
 * Los cinco prompts de sistema del flujo de n8n «Commercial Advisor», versión
 * `29de5119-2e5a-4bc8-a479-60f4fe37c5b3`, con sus BYTES EXACTOS: cada fichero
 * de `resources/ultron-prompts/n8n-29de5119/` es `<nodo>.system` de
 * `docs/marketing/prompts/2026-09-22-voz/nuevo4.json`, que a su vez es
 * `responses.values[0].content` del nodo en el flujo vivo (sha256 comparados el
 * 11-11-2026). Sin salto de línea final añadido.
 *
 * LA BASE NO SE EDITA. Los valores publicados en el Studio se aplican como
 * parches anclados encima ({@see UltronPromptCompiler}); un cambio de la base
 * es una base NUEVA (otro directorio, otro `ID`, otras huellas) y obliga a
 * volver a publicar: las publicaciones firmadas con otra base caen a Legacy en
 * `decide` con motivo `base_changed`.
 *
 * Cada carga verifica los bytes contra los sha256 fijados aquí: un fichero
 * tocado (un editor que añade el salto final o quita un espacio) lanza
 * {@see UltronPromptException} y quien llama cae a Legacy. Las mismas huellas
 * van embebidas en el selector «Elegir Prompts» de n8n, que lleva los mismos
 * bytes como constantes Legacy.
 *
 * PURA: sin base de datos, sin `config()`, sin reloj y sin log. Solo lee sus
 * cinco ficheros, por una ruta relativa a esta clase (sirve igual sin la
 * aplicación arrancada). El Studio puede importarla (Enmienda M-1, M1-6).
 */
final class UltronPromptBase
{
    /** El nombre de la base: el flujo de n8n del que salen sus bytes. Va firmado en `/2` (`prompts.base`). */
    public const ID = 'n8n-29de5119';

    /**
     * El contrato de los prompts: el que se negocia en `decide`
     * (`prompt_contract`) y la primera línea del material de la huella.
     */
    public const CONTRACT = 'ultron-prompts/1';

    /** Orden canónico de los roles: el del material de la huella, el de `roles` y el del selector de n8n. */
    public const ROLES = ['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry'];

    /** Rol → nodo de OpenAI del flujo de n8n que lo usa como prompt de sistema. */
    public const NODES = [
        'strategist' => 'Strategist',
        'composer' => 'Composer',
        'critic' => 'Critic Comercial',
        'composer_retry' => 'Composer Reintento',
        'critic_retry' => 'Critic Comercial 2',
    ];

    /** sha256 (hex, minúsculas) de los bytes exactos de cada fichero. */
    public const SHA256 = [
        'strategist' => 'c84387582f581640493545996c7c61ab9a46d11829d740f1319c34b5c50a6917',
        'composer' => '78437378e4a3b7bfba0b407d73b9e165b3a28e1e0555371bf771d0e47780e866',
        'critic' => '180aa6c37257448f24a15e0ba7dafab918693a60cecf19e6b8141471e200c896',
        'composer_retry' => '92d97a04316cf5a6fecb4a70c3f0e573c49d54f881bf6f2733b96524cb279ccd',
        'critic_retry' => 'a03940c2f4d0d5f21f3ee49007162b1057106bb619aa162292a22673009315ed',
    ];

    /** Tamaño en bytes UTF-8 de cada fichero (112 216 en total). */
    public const BYTES = [
        'strategist' => 29243,
        'composer' => 27132,
        'critic' => 15583,
        'composer_retry' => 25023,
        'critic_retry' => 15235,
    ];

    /**
     * La huella del paquete Legacy: {@see self::bundleHash()} sobre la base tal
     * cual. Es la que n8n observa cuando usa sus constantes Legacy, y la que
     * da `compile(línea base)`. Comprobada en prueba (E1) y embebida en el
     * selector de n8n.
     */
    public const LEGACY_BUNDLE_HASH = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902';

    /** @var array<string,string>|null la base verificada, una vez por proceso */
    private static ?array $loaded = null;

    /**
     * Los cinco textos de la base, en el orden de {@see self::ROLES},
     * verificados contra sus sha256.
     *
     * @return array<string,string> rol → texto
     *
     * @throws UltronPromptException si un fichero no se puede leer o no tiene sus bytes
     */
    public static function roles(): array
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        $roles = [];
        foreach (self::ROLES as $rol) {
            $ruta = self::directory().'/'.$rol.'.txt';
            $bytes = is_file($ruta) && is_readable($ruta) ? @file_get_contents($ruta) : false;
            if (! is_string($bytes)) {
                throw new UltronPromptException(UltronPromptException::BASE_UNREADABLE, "La base {$rol} no se puede leer.");
            }
            if (! hash_equals(self::SHA256[$rol], hash('sha256', $bytes))) {
                throw new UltronPromptException(UltronPromptException::BASE_CORRUPT, "La base {$rol} no tiene los bytes fijados.");
            }
            $roles[$rol] = $bytes;
        }

        return self::$loaded = $roles;
    }

    /**
     * La huella de un paquete (plan §B.3):
     *
     *   sha256_hex(implode("\n", ["ultron-prompts/1",
     *       "strategist", S, "composer", C, "critic", K,
     *       "composer_retry", CR, "critic_retry", KR]))
     *
     * sobre los bytes UTF-8. Exige exactamente los cinco roles, como cadenas;
     * el orden de las claves de entrada no cuenta (se usa el canónico). Es la
     * misma cuenta que hace el selector de n8n en JavaScript.
     *
     * @param  array<string,mixed>  $roles
     *
     * @throws UltronPromptException si falta o sobra un rol, o alguno no es texto
     */
    public static function bundleHash(array $roles): string
    {
        $claves = array_keys($roles);
        sort($claves);
        $esperadas = self::ROLES;
        sort($esperadas);
        if ($claves !== $esperadas) {
            throw new UltronPromptException(UltronPromptException::ROLES, 'El paquete no trae exactamente los cinco roles.');
        }

        $material = [self::CONTRACT];
        foreach (self::ROLES as $rol) {
            if (! is_string($roles[$rol])) {
                throw new UltronPromptException(UltronPromptException::ROLES, "El rol {$rol} no es texto.");
            }
            $material[] = $rol;
            $material[] = $roles[$rol];
        }

        return hash('sha256', implode("\n", $material));
    }

    /** El directorio de esta base, relativo a la clase (backend/resources/ultron-prompts/n8n-29de5119). */
    public static function directory(): string
    {
        return dirname(__DIR__, 5).'/resources/ultron-prompts/'.self::ID;
    }
}
