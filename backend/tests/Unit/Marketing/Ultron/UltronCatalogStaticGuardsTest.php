<?php

namespace Tests\Unit\Marketing\Ultron;

use PHPUnit\Framework\TestCase;

/**
 * GUARDA: NINGÚN DATO DEL CATÁLOGO ESCRITO A MANO EN LO QUE HABLA CON LA
 * PERSONA (encargo del propietario del 18-11-2026, V-3: «los planes, precios y
 * beneficios salen SIEMPRE del CRM, en vivo»).
 *
 * Falla si un nombre de plan, una cifra de precio (o de precio original) o un
 * beneficio del catálogo aparece en:
 *  - los prompts base (`resources/ultron-prompts/`, todo el texto);
 *  - lo que se ejecuta en n8n (`n8n/flujos/ultron-studio-s1/*.js` y, desde la
 *    ola 5 de «regenerar antes de sustituir», `n8n/flujos/ultron-studio-s2/*.js`;
 *    todo el texto: comentarios incluidos, es lo más estricto y hoy no estorba);
 *  - el código de `app/Services/Marketing/Ultron/` que NO es comentario (el
 *    mismo quitador que las guardas del laboratorio: `token_get_all` sin
 *    `T_COMMENT` ni `T_DOC_COMMENT`; las cadenas y las expresiones regulares
 *    cuentan).
 *
 * EL CATÁLOGO CONTRA EL QUE SE MIRA: el de producción leído el 12-11 (A4
 * §2.1) y el que siembra `PlansSeeder` (nombres, precios, precios originales
 * y beneficios, leídos del fichero en cada ejecución: si el seeder cambia, la
 * guarda lo sigue sola), más el nombre del tier «Total Access» del seeder,
 * que fue el plan insignia. Por palabra entera, sin mayúsculas ni tildes.
 *
 * LAS EXCEPCIONES SON VIVAS ({@see self::EXCEPCIONES}): cada una dice qué
 * término, cuántas veces y por qué; si desaparece del fichero, la prueba falla
 * para que se borre también de aquí. Ninguna es una fuente de precio ni de
 * beneficio.
 */
class UltronCatalogStaticGuardsTest extends TestCase
{
    /** El catálogo vendible de producción del 12-11-2026 (A4 §2.1): nombre y precio. */
    private const PRODUCCION = [
        ['PLAN DÍA', 10000], ['Plan Semana', 45000], ['Plan Valera', 65000], ['Plan Mensual', 80000],
        ['Trimestre', 210000], ['Semestre', 390000], ['Anualidad', 624000], ['Élite', 180000],
        ['Pro', 499000], ['Prime', 950000], ['Evolution', 1800000], ['PROMO X4', 75000],
        ['INGRESO EMPLEADOS', 1],
    ];

    /** Nombres que no son planes del catálogo de hoy pero lo fueron (el tier premium de `PlansSeeder`, antes el insignia). */
    private const NOMBRES_DE_ANTES = ['Total Access'];

    /**
     * Fichero (desde la raíz del repositorio) → término normalizado → veces.
     *
     * - `CommercialTurnPolicy.php`: «total access» (×2) y «plan elite», dentro
     *   de `VENDE_EN_MEMORIA` y `VENDE_EN_SALUDO`. Son VOCABULARIO de un
     *   detector («este saludo o este recuerdo se puso a vender»), no una
     *   fuente: la misma regla mira además los planes del catálogo vivo con
     *   `plansNamedIn()`. «Total Access» es el nombre del tier premium de
     *   `PlansSeeder` (el insignia de entonces); hoy no hay ningún plan con
     *   ese nombre. Señalado al lead (V-3), sin arreglar.
     * - `ReferenceResolver.php`: «trimestre», «semestre» y «anualidad» en
     *   `NOMBRE_DE_CALENDARIO`: palabras del calendario (lista cerrada del
     *   idioma) que, por coincidir con un plan, NO bastan para elegirlo.
     * - `composer.txt` y su copia en `elegir-prompts.js`: «si se llama
     *   "trimestre", no es "plan trimestre"» (×2), un ejemplo de cómo copiar
     *   el nombre tal cual viene en `active_plans`. Prompt base: fuera de
     *   alcance, y la base no se edita (el compilador exige sus bytes).
     */
    private const EXCEPCIONES = [
        'backend/app/Services/Marketing/Ultron/CommercialTurnPolicy.php' => ['total access' => 2, 'elite' => 1],
        'backend/app/Services/Marketing/Ultron/ReferenceResolver.php' => ['trimestre' => 1, 'semestre' => 1, 'anualidad' => 1],
        'backend/resources/ultron-prompts/n8n-29de5119/composer.txt' => ['trimestre' => 2],
        'n8n/flujos/ultron-studio-s1/elegir-prompts.js' => ['trimestre' => 2],
    ];

    public function test_ningun_dato_del_catalogo_escrito_a_mano(): void
    {
        $encontrados = [];
        foreach (self::ficheros() as $relativo => $texto) {
            $aciertos = self::aciertos($texto, self::catalogo());
            if ($aciertos !== [] || isset(self::EXCEPCIONES[$relativo])) {
                ksort($aciertos);
                $encontrados[$relativo] = $aciertos;
            }
        }
        ksort($encontrados);
        $esperado = array_map(function (array $e): array {
            ksort($e);

            return $e;
        }, self::EXCEPCIONES);
        ksort($esperado);

        $this->assertSame($esperado, $encontrados, 'un nombre, un precio o un beneficio del catálogo escrito a mano (o una excepción que ya no hace falta)');
    }

    public function test_mira_donde_tiene_que_mirar(): void
    {
        $ficheros = array_keys(self::ficheros());
        $this->assertContains('backend/resources/ultron-prompts/n8n-29de5119/composer.txt', $ficheros);
        $this->assertContains('n8n/flujos/ultron-studio-s1/elegir-prompts.js', $ficheros);
        $this->assertContains('n8n/flujos/ultron-studio-s1/preparar-commit.js', $ficheros);
        foreach (['preparar-commit.js', 'leer-comprobacion-1.js', 'leer-comprobacion-2.js', 'nota-del-servidor.js', 'decidir-intento-3.js'] as $s2) {
            $this->assertContains('n8n/flujos/ultron-studio-s2/'.$s2, $ficheros, 'los Code de S2 también');
        }
        $this->assertContains('backend/app/Services/Marketing/Ultron/UltronCommitService.php', $ficheros);
        $this->assertContains('backend/app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php', $ficheros);
        $this->assertGreaterThanOrEqual(5, count(array_filter($ficheros, fn (string $f) => str_starts_with($f, 'backend/resources/ultron-prompts/'))));

        $catalogo = self::catalogo();
        $this->assertContains('plan valera', $catalogo['nombres'], 'del catálogo de producción');
        $this->assertContains('total access', $catalogo['nombres']);
        $this->assertContains(1770000, $catalogo['precios'], 'leído de PlansSeeder (Evolution)');
        $this->assertContains(2160000, $catalogo['precios'], 'el precio original del seeder');
        $this->assertContains('asesoria semi personalizada con entrenador de planta', $catalogo['beneficios']);
    }

    /** El buscador, sobre casos conocidos: lo que caza y lo que no. */
    public function test_el_buscador(): void
    {
        $c = self::catalogo();
        $this->assertSame(['plan mensual' => 1, '80000' => 1], self::aciertos('El Plan Mensual cuesta $80.000 COP.', $c));
        $this->assertSame(['65000' => 3], self::aciertos('65000, 65 000 y 65 mil', $c));
        $this->assertSame(['elite' => 1], self::aciertos('el ÉLITE', $c));
        $this->assertSame(['1800000' => 1], self::aciertos('1.800.000', $c));
        $this->assertSame(['acceso ilimitado al gimnasio' => 1], self::aciertos('Incluye acceso ilimitado al gimnasio.', $c));
        $this->assertSame([], self::aciertos('0x10000, T_TONE_PRO_COMPOSER, profesional, problema, $1 y 200 caracteres', $c), 'hexadecimal, identificador, otras palabras y cifras que no son precios');
        $this->assertSame([], self::aciertos('{{PLAN_PRICE}} {{PLAN_NAME}}', $c), 'los marcadores no son datos');
    }

    /**
     * Los ficheros que se miran, con el texto que cuenta.
     *
     * @return array<string,string> ruta desde la raíz del repositorio → texto
     */
    private static function ficheros(): array
    {
        $backend = dirname(__DIR__, 4);
        $repo = dirname($backend);
        $out = [];
        foreach (glob($backend.'/resources/ultron-prompts/*/*.txt') ?: [] as $f) {
            $out['backend/resources/ultron-prompts/'.basename(dirname($f)).'/'.basename($f)] = (string) file_get_contents($f);
        }
        foreach (['ultron-studio-s1', 'ultron-studio-s2'] as $flujo) {
            foreach (glob($repo.'/n8n/flujos/'.$flujo.'/*.js') ?: [] as $f) {
                $out['n8n/flujos/'.$flujo.'/'.basename($f)] = (string) file_get_contents($f);
            }
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($backend.'/app/Services/Marketing/Ultron', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with((string) $f, '.php')) {
                $out['backend/'.substr((string) $f, strlen($backend) + 1)] = self::sinComentarios((string) file_get_contents((string) $f));
            }
        }

        return $out;
    }

    /** El código sin comentarios: el mismo criterio que `StudioLabIsolationTest::tokens()`. */
    private static function sinComentarios(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /**
     * El catálogo normalizado: nombres, precios (los ≥ 10.000: «$1» no es una
     * cifra que se pueda buscar) y beneficios.
     *
     * @return array{nombres:list<string>,precios:list<int>,beneficios:list<string>}
     */
    private static function catalogo(): array
    {
        $nombres = array_merge(array_column(self::PRODUCCION, 0), self::NOMBRES_DE_ANTES);
        $precios = array_column(self::PRODUCCION, 1);
        $beneficios = [];

        $seeder = (string) file_get_contents(dirname(__DIR__, 4).'/database/seeders/PlansSeeder.php');
        preg_match_all("/'name'\\s*=>\\s*'([^']+)'/u", $seeder, $m);
        $nombres = array_merge($nombres, $m[1]);
        preg_match_all("/'(?:original_)?price'\\s*=>\\s*(\\d+)/u", $seeder, $m);
        $precios = array_merge($precios, array_map('intval', $m[1]));
        // Los beneficios: las cadenas de las constantes BENEFITS_* del seeder.
        if (preg_match_all('/const BENEFITS_\\w+\\s*=\\s*\\[(.*?)\\];/su', $seeder, $bloques)) {
            foreach ($bloques[1] as $bloque) {
                preg_match_all("/'([^']+)'/u", $bloque, $b);
                $beneficios = array_merge($beneficios, $b[1]);
            }
        }

        return [
            'nombres' => array_values(array_unique(array_map(self::normaliza(...), $nombres))),
            'precios' => array_values(array_unique(array_filter($precios, fn (int $p) => $p >= 10000))),
            'beneficios' => array_values(array_unique(array_map(self::normaliza(...), $beneficios))),
        ];
    }

    /**
     * Qué datos del catálogo aparecen en el texto y cuántas veces, por palabra
     * entera (un identificador como `T_TONE_PRO` no es el plan «Pro», ni
     * `0x10000` un precio).
     *
     * @param  array{nombres:list<string>,precios:list<int>,beneficios:list<string>}  $catalogo
     * @return array<string,int>
     */
    private static function aciertos(string $texto, array $catalogo): array
    {
        $t = self::normaliza($texto);
        $out = [];
        foreach ([...$catalogo['nombres'], ...$catalogo['beneficios']] as $termino) {
            $n = preg_match_all('/(?<![a-z0-9_])'.preg_quote($termino, '/').'(?![a-z0-9_])/u', $t);
            if ($n > 0) {
                $out[$termino] = $n;
            }
        }
        foreach ($catalogo['precios'] as $precio) {
            // 80000 · 80.000 · 80,000 · 80 000 · 80 mil · 80k; 1.800.000 con sus separadores.
            $grupos = str_split(strrev((string) $precio), 3);
            $partes = array_reverse(array_map('strrev', $grupos));
            $conSeparador = implode('[.,\\s]?', $partes);
            $miles = (string) intdiv($precio, 1000);
            $n = preg_match_all('/(?<![0-9a-z_.,])(?:'.$conSeparador.'|'.$miles.'\\s?(?:mil|k))(?![0-9a-z_])/u', $t);
            if ($n > 0) {
                $out[(string) $precio] = $n;
            }
        }

        return $out;
    }

    private static function normaliza(string $s): string
    {
        return strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
