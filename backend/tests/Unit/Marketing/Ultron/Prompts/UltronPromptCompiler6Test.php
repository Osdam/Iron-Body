<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\Marketing\Ultron\Prompts\UltronPromptException;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use App\Services\UltronStudio\StudioLabEngineFlow;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PT-17b · EL COMPILADOR 6: EL TURNO DE PLANES A LA PRIMERA (diseño del
 * arquitecto `plans/informes-motores/diseno-arq-pt17b-planes-c6.md`, §2 y
 * §5, N1 a N7, con la «Enmienda del lead»; Enmienda A4-6 del H5; plan de
 * motores §B.3, «Compilador 6»). Sustituye a `UltronPromptCompiler5Test`: los
 * textos del 5 que cambian quedan como históricos en §B.3 y aquí solo se
 * comprueba que ya no van en ningún rol.
 *
 * El parche del motor pasa de 11 entradas a 15, solo con textos y solo en el
 * parche del motor: el texto del redactor se va al FINAL de los dos
 * redactores (otra ancla, la última línea) como GUÍA con libertad de
 * redacción, con el tope de la lista (`L`) y las palabras (`W`) del tope
 * gobernado; la frase de `suggested_question` y la del precio pasan a ser
 * condicionales en su sitio; el revisor recibe al FINAL el bloque del turno
 * de planes y una frase corta de lo que ya comprueba el backend (dos
 * inserciones tras la misma línea: quedan esa frase y después LISTA, la
 * última). El estratega, el tono, el tope, las frases, la base, n8n y los
 * intentos no cambian.
 *
 * DECISIONES DEL PROPIETARIO (23-11-2026, anexo del diseño): sin la frase
 * fija del diseño («Claro, tenemos…», «¿Cuál revisamos?») ni su prueba de
 * plantilla, y sin la lista cerrada de registro del revisor (ni su paridad
 * con el detector). Los textos están escritos aquí a mano desde ese anexo.
 *
 * Lo que se fija:
 *  N1. `compile(v4)` (`profesional · 200 · emojis sí · cita · sin frases`):
 *      25 parches (tono 4, tope 6, motor 15) en el orden de la tabla; la
 *      última línea de los dos redactores es la guía con L = 320 y W = 35 y
 *      la siguiente a `FORMA:` el tope; `composer.txt:5` y `:38`,
 *      sustituidos; las dos últimas líneas de los revisores son la frase de
 *      lo que comprueba el backend y LISTA, y la siguiente a
 *      `progressive_disclosure:` el tope; quitando las líneas insertadas, el
 *      revisor es la base con los parches gobernados (HARD_FAIL y FALLA
 *      TAMBIEN, byte a byte); la huella, la de Node; VERSION 6.
 *  N2. Una frase con `A_FIN_CRITIC` dentro no vale (`anchor`); las otras dos
 *      anclas nuevas caen por `charset`; las del 5 siguen valiendo de ancla.
 *  N3. Las 305 combinaciones y listas de frases: cada ancla, una vez al
 *      aplicarse; ningún texto contiene un ancla (las 15); el motor usa en
 *      cada rol exactamente sus anclas; los textos del motor quedan los
 *      últimos, la guía con la L y la W de su tope; topes de rol y paquete.
 *  N4. Los textos al byte, en su forma y sin cifras del catálogo.
 *  N5. Ninguna frase modelo ni pregunta de cierre fija en el redactor.
 *  N6. La línea base y la huella Legacy no se mueven.
 *  N7. El laboratorio frente al motor sigue al compilador 6.
 *  N8. L y W salen del tope gobernado (160, 200, 320 y 480) y L es la cifra
 *      con la que Laravel mide la respuesta con la lista.
 */
class UltronPromptCompiler6Test extends TestCase
{
    // ── Las anclas ──────────────────────────────────────────────────────────

    /** Las tres nuevas del compilador 6 (§2 del diseño). */
    private const A_SUGERIDA = 'Si vas a preguntar, usa suggested_question cuando venga:';

    private const A_FIN_COMPOSER = 'price_verification true significa';

    private const A_FIN_CRITIC = 'Si el borrador explica POR QUE la persona vio otro precio';

    /** Las cinco del compilador 5, que siguen. */
    private const A_COTIZA = 'QUE PLAN SE COTIZA';

    private const A_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos.';

    private const A_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace.';

    private const A_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.';

    private const A_PRECIO = 'el precio solo del que se cotiza.';

    /** Las quince: las siete de siempre, las cinco del 5 y las tres del 6. */
    private const ANCLAS = [
        'COMO SUENAS:', 'FORMA:', 'Emoji opcional y raro, nunca en temas sensibles.', 'naturalness:', 'progressive_disclosure:', 'mas de ~900 caracteres', 'PRINCIPIO:',
        self::A_COTIZA, self::A_PRESENTALO, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO,
        self::A_SUGERIDA, self::A_FIN_COMPOSER, self::A_FIN_CRITIC,
    ];

    // ── Los textos del compilador 6 (anexo del diseño, a mano; ASCII, sin tildes ni llaves, una línea) ──

    /** 3 y 8 · composer y composer_retry, replace de {@see self::A_SUGERIDA}. */
    private const PLANS_COMPOSER_ASK = 'Si vas a preguntar, usa suggested_question cuando venga, nunca si commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND):';

    /** 6 y 10 · composer y composer_retry, replace de {@see self::A_PRECIO} (sustituye al del 5). */
    private const PLANS_COMPOSER_PRICE = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, ni nombres ni precios a mano: los pone el marcador de cada plan (LISTA DE PLANES DEL BACKEND).';

    /** 7 y 11 · composer y composer_retry, insert_after_line tras {@see self::A_FIN_COMPOSER} (sustituye al del 5). La guía: `{L}` y `{W}` salen del tope ({@see self::lista()}). */
    private const PLANS_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar. Presentalos con tus palabras, en una o dos frases naturales: cada plan con su marcador PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves (el de su posicion en plans_overview.plans, una sola vez) y un beneficio breve tomado de sus benefits. El backend cambia cada marcador por el nombre y el precio oficiales: no escribas nombres de plan, ni cifras, ni PLAN_NAME ni PLAN_PRICE. Si la persona pregunto ademas otra cosa, respondela tambien en este mensaje. Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui. LONGITUD DE ESTE TURNO: el mensaje completo, ya con nombres y precios, no pasa de {L} caracteres; cada marcador ocupara unos 25, asi que escribe como mucho unas {W} palabras tuyas. En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar y el tope general). Si plans_overview no viene, nada de esto aplica.';

    /** 12 y 14 · critic y critic_retry, insert_after_line tras {@see self::A_FIN_CRITIC} (sustituye al del 4 y el 5). */
    private const PLANS_CRITIC = 'LISTA DE PLANES DEL BACKEND (va al final porque manda sobre todo lo anterior): si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves, la persona pidio los planes o los precios y el backend eligio esos planes; cada marcador pone el nombre y el precio reales. En ese borrador NO son defecto: dar dos o tres planes en una frase con un beneficio corto de cada uno, que es la forma pedida y no plantilla ni folleto (naturalness, progressive_disclosure); darlos sin preguntar antes objetivo, experiencia u horario, aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id (sales_judgment, phase_alignment, TECNICA, ni customer_fit por la fase); cerrar con una pregunta corta para elegir, con una sola pregunta aunque lleve una o, o sin pregunta (next_step, question_discipline); su longitud, que el backend mide con los marcadores ya sustituidos. No es invented_plan ni invented_price ni esquiva el precio. Todo lo demas falla como siempre: un nombre o una cifra escritos a mano, un beneficio que no esta en active_plans, mas de una pregunta, y cada regla de FALLA TAMBIEN y de HARD_FAIL.';

    /** 13 y 15 · critic y critic_retry, insert_after_line tras {@see self::A_FIN_CRITIC} (nueva, en lugar de la lista cerrada de registro del diseño; queda encima de {@see self::PLANS_CRITIC}). */
    private const PLANS_CRITIC_CHECKED = 'REGISTRO Y LONGITUD EN LA LISTA DE PLANES: si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves, el registro (tu/usted) y la longitud ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos.';

    // ── Los del compilador 5 que siguen igual ───────────────────────────────

    /** 1 · strategist, insert_after_line tras `QUE PLAN SE COTIZA`. */
    private const PLANS_STRATEGIST = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales se le muestran. En ese turno esto manda sobre las reglas de este apartado, sobre resolved_reference y sobre strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con esa lista; no descubre ni presenta un solo plan. Entonces next_best_action recommend_plan, conversation_goal recommend, recommendation_goal compare_plans, information_needed [] (ni experiencia, ni objetivo, ni horario), recommended_plan_id el que digan las reglas de abajo (nunca null) y response_goal: dar esos planes en su orden, con un beneficio corto de cada uno y sin cifras. Si plans_overview no viene, nada de esto aplica.';

    /** 2 · strategist, replace de {@see self::A_PRESENTALO}. */
    private const STRATEGIST_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos; si commercial_turn_policy.plans_overview trae planes, no: manda LISTA DE PLANES DEL BACKEND.';

    /** 4 y 9 · composer y composer_retry, replace de {@see self::A_MARCADOR}. */
    private const COMPOSER_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace, salvo PLAN_1 a PLAN_3 cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).';

    /** 5 · composer, replace de {@see self::A_ELIGE}. */
    private const COMPOSER_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso das esa lista (LISTA DE PLANES DEL BACKEND).';

    // ── Históricos: con el 6 ya no van en ningún rol ────────────────────────

    /** El texto del redactor del compilador 5 (tras `FORMA:`). */
    private const PLANS_COMPOSER_C5 = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. En ese turno esto manda sobre todo este prompt (las reglas de precios y marcadores, recomendar un solo plan con su PORQUE, resolved_reference y suggested_question) y sobre la estrategia si habla de un solo plan: la respuesta es esa lista, en una frase. Nombra los planes en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales, seguido de dos o tres palabras de uno de los benefits que trae ese marcador en plans_overview.plans. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Fuera de los marcadores, unas 15 palabras en todo el mensaje: un arranque corto, los beneficios y, como mucho, una pregunta corta para elegir (sobre cual amplio la informacion); nunca su experiencia ni su objetivo. Con un tope publicado de menos de 200 caracteres, solo PLAN_1 y PLAN_2. Si plans_overview no viene, nada de esto aplica.';

    /** El arranque de la plantilla del diseño, que el propietario descartó: no va en ningún rol. */
    private const PLANTILLA_DEL_DISENO = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar. Entonces reply_draft es SOLO esta frase, en este orden: Claro, tenemos PLAN_1, B1; PLAN_2, B2; y PLAN_3, B3. Q.';

    /** La sustitución del precio del compilador 5. */
    private const COMPOSER_PRECIO_C5 = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, el de cada plan de esa lista con su marcador.';

    /** El texto del revisor de los compiladores 4 y 5 (tras `progressive_disclosure:`). */
    private const PLANS_CRITIC_C4 = 'LISTA DE PLANES DEL BACKEND: los marcadores PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves los sustituye el backend por el nombre y el precio reales de planes que el mismo eligio. Cuentan como nombre y como marcador de precio de ese plan: un borrador que los usa para dar varios planes con su precio NO esquiva el precio, no es invented_plan ni invented_price, y nombrar dos o tres no es defecto de progressive_disclosure. Lo demas se juzga como siempre.';

    // ── Los textos gobernados de la v4 que tienen que quedar en su sitio (tope 200) ──

    private const MAX_COMPOSER_200 = 'TOPE DE LONGITUD PUBLICADO: el limite es 200 caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos 160 caracteres, que son unas 25 palabras: como mucho 3 frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.';

    private const MAX_CRITIC_200 = 'TOPE PUBLICADO: un borrador de mas de ~200 caracteres falla por progressive_disclosure aunque se haya pedido detalle.';

    /**
     * La huella de la v4 con el compilador 6 y sus bytes: la del generador de
     * Node (`derivar-compile-cases.cjs`, c074 de `compile-cases.json`), que
     * transcribe los textos y las fórmulas de L y W de §B.3 sin leer el
     * compilador.
     */
    private const V4_C6_HASH = '962902aba8923e2264e9c7483aabad666f9851a66382e88c213119f189f973a4';

    private const V4_C6_BYTES = 122167;

    /** La de la v4 con el compilador 5 (la que midió el laboratorio): con el 6 ya no la da. */
    private const V4_C5_HASH = '2eb9be6f5d3e6b509b3410457b291c7d582aa73be08f0c9dd00f42229bb909c0';

    /** La de la v4 SOLO con lo gobernado (el compilador 4 sin el motor, `7b09b89c…cc35`). */
    private const V4_GOBERNADO_HASH = '7b09b89c98d48b01561b9cbc07e048c152c0af6a4ba7f583becd49f8e7e8cc35';

    /**
     * La guía del redactor con las cifras de un tope, calculadas AQUÍ con las
     * fórmulas de §B.3 («Compilador 6»), no con las del compilador:
     * L = max(N, 320) y W = intdiv(L − 110, 30) · 5.
     */
    private static function lista(int $n): string
    {
        $l = max($n, 320);

        return str_replace(['{L}', '{W}'], [(string) $l, (string) (intdiv($l - 110, 30) * 5)], self::PLANS_COMPOSER);
    }

    /** La v4 (lo que se volverá a publicar): `profesional · 200 · emojis sí · cita · sin frases`. */
    private static function v4(): array
    {
        return array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200]);
    }

    /** Las 15 entradas del parche del motor, en el orden de la tabla de §2, con el tope de la v4: [rol, operación, ancla, texto]. */
    private static function motor(): array
    {
        return [
            ['strategist', C::INSERT_AFTER_LINE, self::A_COTIZA, self::PLANS_STRATEGIST],
            ['strategist', C::REPLACE, self::A_PRESENTALO, self::STRATEGIST_PRESENTALO],
            ['composer', C::REPLACE, self::A_SUGERIDA, self::PLANS_COMPOSER_ASK],
            ['composer', C::REPLACE, self::A_MARCADOR, self::COMPOSER_MARCADOR],
            ['composer', C::REPLACE, self::A_ELIGE, self::COMPOSER_ELIGE],
            ['composer', C::REPLACE, self::A_PRECIO, self::PLANS_COMPOSER_PRICE],
            ['composer', C::INSERT_AFTER_LINE, self::A_FIN_COMPOSER, self::lista(200)],
            ['composer_retry', C::REPLACE, self::A_SUGERIDA, self::PLANS_COMPOSER_ASK],
            ['composer_retry', C::REPLACE, self::A_MARCADOR, self::COMPOSER_MARCADOR],
            ['composer_retry', C::REPLACE, self::A_PRECIO, self::PLANS_COMPOSER_PRICE],
            ['composer_retry', C::INSERT_AFTER_LINE, self::A_FIN_COMPOSER, self::lista(200)],
            ['critic', C::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::PLANS_CRITIC],
            ['critic', C::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::PLANS_CRITIC_CHECKED],
            ['critic_retry', C::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::PLANS_CRITIC],
            ['critic_retry', C::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::PLANS_CRITIC_CHECKED],
        ];
    }

    /** La línea que sigue a la primera que contiene `$ancla`. */
    private static function lineaTras(string $texto, string $ancla): ?string
    {
        $lineas = explode("\n", $texto);
        foreach ($lineas as $i => $linea) {
            if (str_contains($linea, $ancla)) {
                return $lineas[$i + 1] ?? null;
            }
        }

        return null;
    }

    /** La primera línea que contiene `$texto`. */
    private static function lineaCon(string $rol, string $texto): ?string
    {
        foreach (explode("\n", $rol) as $linea) {
            if (str_contains($linea, $texto)) {
                return $linea;
            }
        }

        return null;
    }

    /**
     * Los roles con SOLO los parches gobernados (sin el motor), aplicados con
     * el `apply()` del compilador: lo que el motor deja intacto.
     *
     * @return array<string,string>
     */
    private static function soloGobernado(array $g): array
    {
        $aplicar = new ReflectionMethod(C::class, 'apply');
        $roles = Base::roles();
        foreach (C::patches($g) as $p) {
            if ($p['parameter'] !== C::ENGINE) {
                $roles[$p['role']] = $aplicar->invoke(null, $roles[$p['role']], $p);
            }
        }

        return $roles;
    }

    /** @return list<array<string,mixed>> los 305 valores no base de la rejilla del contrato */
    private static function noBase(): array
    {
        $out = [];
        foreach (C::TONES as $tono) {
            foreach ([true, false] as $emojis) {
                foreach (range(C::MAX_CHARS_MIN, C::MAX_CHARS_MAX, C::MAX_CHARS_STEP) as $n) {
                    foreach (C::GOALS as $objetivo) {
                        if ([$tono, $emojis, $n, $objetivo] !== ['cercano', true, 320, 'cita']) {
                            $out[] = [C::TONE => $tono, C::EMOJIS => $emojis, C::MAX_CHARS => $n, C::GOAL => $objetivo, C::PHRASES => []];
                        }
                    }
                }
            }
        }

        return $out;
    }

    // ── N1 ──────────────────────────────────────────────────────────────────

    public function test_n1_la_v4_compila_con_la_guia_al_final_de_los_redactores_y_el_bloque_al_final_de_los_revisores(): void
    {
        $c = C::compile(self::v4());
        $r = $c['roles'];
        $base = Base::roles();
        $gobernado = self::soloGobernado(self::v4());

        // 25 parches: tono 4, tope 6 y el motor 15, este al final y en el orden de la tabla.
        $this->assertCount(25, $c['patches']);
        $this->assertSame([...array_fill(0, 4, C::TONE), ...array_fill(0, 6, C::MAX_CHARS), ...array_fill(0, 15, C::ENGINE)], array_column($c['patches'], 'parameter'));
        $this->assertSame(self::motor(), array_map(static fn (array $p): array => [$p['role'], $p['operation'], $p['anchor'], $p['text']], array_slice($c['patches'], -15)));
        $this->assertSame(self::V4_GOBERNADO_HASH, Base::bundleHash($gobernado), 'lo gobernado de la v4 es lo de siempre (el 4 sin el motor)');

        // El estratega: como en el compilador 5 (la inserción tras `QUE PLAN SE COTIZA` y la sustitución de la l.23).
        $this->assertSame(self::PLANS_STRATEGIST, self::lineaTras($r['strategist'], self::A_COTIZA));
        $lineas = explode("\n", $base['strategist']);
        $i = array_key_first(array_filter($lineas, fn (string $l): bool => str_contains($l, self::A_COTIZA)));
        $this->assertSame(17, $i, 'QUE PLAN SE COTIZA es la l.18 de la base');
        $this->assertStringContainsString(self::A_PRESENTALO, $lineas[22], 'y la frase sustituida, la l.23');
        array_splice($lineas, $i + 1, 0, [self::PLANS_STRATEGIST]);
        $this->assertSame(str_replace(self::A_PRESENTALO, self::STRATEGIST_PRESENTALO, implode("\n", $lineas)), $r['strategist'], 'el estratega es la base con esas dos entradas, byte a byte');

        // Los dos redactores.
        foreach (['composer', 'composer_retry'] as $rol) {
            $lineasR = explode("\n", $r[$rol]);
            $this->assertSame(self::lista(200), end($lineasR), "{$rol}: la guía, con L = 320 y W = 35, es la ÚLTIMA línea del prompt");
            $this->assertStringContainsString(self::A_FIN_COMPOSER, $lineasR[count($lineasR) - 2], "{$rol}: justo detrás de la última línea de la base");
            $this->assertSame(self::MAX_COMPOSER_200, self::lineaTras($r[$rol], 'FORMA:'), "{$rol}: tras FORMA: queda el tope, ya no la lista");
            $this->assertSame(1, substr_count($r[$rol], self::lista(200)), $rol);
            $this->assertStringContainsString('no pasa de 320 caracteres', end($lineasR), "{$rol}: con tope 200, el de la lista es 320");
            $this->assertStringContainsString('unas 35 palabras tuyas', end($lineasR), $rol);
            $this->assertStringNotContainsString(self::PLANTILLA_DEL_DISENO, $r[$rol], "{$rol}: la plantilla del diseño no va");
            $this->assertStringNotContainsString(self::PLANS_COMPOSER_C5, $r[$rol], "{$rol}: el texto del compilador 5 ya no va");
            $this->assertStringNotContainsString(self::COMPOSER_PRECIO_C5, $r[$rol], "{$rol}: la sustitución del precio del 5 ya no va");
            // composer.txt:5 y :38, sustituidas en su sitio.
            $sugerida = self::lineaCon($base[$rol], self::A_SUGERIDA);
            $this->assertSame($sugerida, explode("\n", $base[$rol])[4], "{$rol}: la de suggested_question es la l.5 de la base");
            $this->assertSame(str_replace(self::A_SUGERIDA, self::PLANS_COMPOSER_ASK, $sugerida), self::lineaCon($r[$rol], self::PLANS_COMPOSER_ASK), "{$rol}: l.5");
            $precio = self::lineaCon($base[$rol], self::A_PRECIO);
            $this->assertSame($precio, explode("\n", $base[$rol])[37], "{$rol}: la del precio es la l.38 de la base");
            $this->assertSame(str_replace(self::A_PRECIO, self::PLANS_COMPOSER_PRICE, $precio), self::lineaCon($r[$rol], self::PLANS_COMPOSER_PRICE), "{$rol}: l.38");
            foreach ([self::A_SUGERIDA, self::A_MARCADOR, self::A_PRECIO] as $ancla) {
                $this->assertSame([1, 0], [substr_count($base[$rol], $ancla), substr_count($r[$rol], $ancla)], "{$rol}: «{$ancla}» sustituida");
            }
            // Byte a byte: lo gobernado con las sustituciones en su sitio y la guía al final.
            $esperado = str_replace(
                [self::A_SUGERIDA, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO],
                [self::PLANS_COMPOSER_ASK, self::COMPOSER_MARCADOR, self::COMPOSER_ELIGE, self::PLANS_COMPOSER_PRICE],
                $gobernado[$rol],
            )."\n".self::lista(200);
            $this->assertSame($esperado, $r[$rol], "{$rol}: lo gobernado, las sustituciones y la guía, nada más");
        }
        $this->assertSame([1, 0, 1], [substr_count($base['composer'], self::A_ELIGE), substr_count($r['composer'], self::A_ELIGE), substr_count($r['composer'], self::COMPOSER_ELIGE)]);
        $this->assertSame(0, substr_count($base['composer_retry'], 'Elige el plan'), 'el reintento no tiene la frase de :50');

        // Los dos revisores: la frase de lo que comprueba el backend y LISTA, las dos últimas; el tope sigue pegado a progressive_disclosure:.
        foreach (['critic', 'critic_retry'] as $rol) {
            $lineasR = explode("\n", $r[$rol]);
            $n = count($lineasR);
            $this->assertSame([self::PLANS_CRITIC_CHECKED, self::PLANS_CRITIC], [$lineasR[$n - 2], $lineasR[$n - 1]], "{$rol}: la frase del backend y después LISTA, la última");
            $this->assertStringContainsString(self::A_FIN_CRITIC, $lineasR[$n - 3], "{$rol}: tras la última línea de la base");
            $this->assertSame(self::MAX_CRITIC_200, self::lineaTras($r[$rol], 'progressive_disclosure:'), "{$rol}: tras progressive_disclosure: queda el tope");
            $this->assertStringNotContainsString(self::PLANS_CRITIC_C4, $r[$rol], "{$rol}: el texto del revisor del 4 y el 5 ya no va");
            // Quitando las dos líneas insertadas, la base con los parches gobernados: el motor no sustituye nada en un revisor.
            $this->assertSame($gobernado[$rol]."\n".self::PLANS_CRITIC_CHECKED."\n".self::PLANS_CRITIC, $r[$rol], "{$rol}: solo inserciones");
            $this->assertSame($gobernado[$rol], implode("\n", array_slice($lineasR, 0, -2)), $rol);
            // HARD_FAIL, FALLA TAMBIEN y el resto de reglas de la base, byte a byte: cada línea de la base sigue, una vez.
            foreach (['HARD_FAIL', 'FALLA TAMBIEN'] as $regla) {
                $deLaBase = array_values(array_filter(explode("\n", $base[$rol]), static fn (string $l): bool => str_contains($l, $regla) && ! str_contains($l, 'LISTA DE PLANES')));
                $this->assertNotSame([], $deLaBase, "{$rol}: la base tiene {$regla}");
                foreach ($deLaBase as $linea) {
                    $this->assertContains($linea, $lineasR, "{$rol}: la línea de {$regla} sigue tal cual");
                }
            }
            $this->assertSame([], array_values(array_diff(explode("\n", $gobernado[$rol]), $lineasR)), "{$rol}: el motor no quita ni cambia ninguna línea de lo gobernado");
        }

        // La huella: la de Node y la de Python; la del 5 ya no.
        $this->assertSame([self::V4_C6_HASH, self::V4_C6_BYTES], [$c['bundleHash'], $c['bytes']]);
        $this->assertNotSame(self::V4_C5_HASH, $c['bundleHash'], 'la del compilador 5, la que midió el laboratorio');
        $this->assertSame(6, C::VERSION);
        $this->assertSame(6, $c['compiler']);
    }

    // ── N2 ──────────────────────────────────────────────────────────────────

    public function test_n2_una_frase_con_un_ancla_no_vale(): void
    {
        // La del revisor cabe en una frase (57 caracteres del juego cerrado): `anchor`, sola, en una lista y dentro de otra.
        $this->assertSame(57, mb_strlen(self::A_FIN_CRITIC));
        $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation(self::A_FIN_CRITIC));
        $this->assertSame(['reason' => C::PHRASE_ANCHOR, 'index' => 1], C::phrasesViolation(['aaa', self::A_FIN_CRITIC]));
        $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation('y '.self::A_FIN_CRITIC));
        $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation(self::A_FIN_CRITIC.'.'));
        try {
            C::compile(array_replace(C::BASELINE, [C::PHRASES => [self::A_FIN_CRITIC]]));
            $this->fail('la frase con el ancla del revisor tenía que lanzar');
        } catch (UltronPromptException $e) {
            $this->assertSame(UltronPromptException::INVALID_PHRASES, $e->reason, 'se rechaza la frase, antes de llegar al ancla repetida');
        }
        // Las otras dos llevan `_` y `:`, fuera del juego cerrado: caen antes, por `charset`.
        foreach ([self::A_SUGERIDA, self::A_FIN_COMPOSER] as $ancla) {
            $this->assertLessThanOrEqual(C::PHRASE_MAX_CHARS, mb_strlen($ancla), "«{$ancla}»");
            $this->assertSame(C::PHRASE_CHARSET, C::phraseViolation($ancla), "«{$ancla}»");
        }
        // Las del compilador 5 siguen siendo anclas.
        foreach ([self::A_COTIZA, self::A_MARCADOR, self::A_PRECIO] as $ancla) {
            $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation($ancla), "«{$ancla}»");
        }
        foreach ([self::A_PRESENTALO, self::A_ELIGE] as $ancla) {
            $this->assertSame(C::PHRASE_LENGTH, C::phraseViolation($ancla), "«{$ancla}»");
        }
        // Lo que no contiene un ancla entera sigue valiendo.
        $this->assertNull(C::phraseViolation('Si el borrador explica por que la persona vio otro precio'));
        $this->assertNull(C::phraseViolation('la persona vio otro precio'));
    }

    // ── N3 ──────────────────────────────────────────────────────────────────

    public function test_n3_en_toda_combinacion_cada_ancla_una_vez_al_aplicarse_y_ningun_texto_contiene_un_ancla(): void
    {
        $frases = array_map(static fn (string $l): string => str_repeat('ñ', 59).$l, ['á', 'é', 'í', 'ó', 'ú', 'ü', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü']);
        sort($frases, SORT_STRING);
        $casos = self::noBase();
        $this->assertCount(305, $casos);
        $casos[] = array_replace(C::BASELINE, [C::PHRASES => ['gratis']]);
        $casos[] = array_replace(self::v4(), [C::PHRASES => ['gratis', 'te lo regalo']]);
        $casos[] = [C::TONE => 'profesional', C::EMOJIS => false, C::MAX_CHARS => 480, C::GOAL => 'venta', C::PHRASES => $frases];

        $aplicar = new ReflectionMethod(C::class, 'apply');
        $esperadas = [
            'strategist' => [self::A_COTIZA, self::A_PRESENTALO],
            'composer' => [self::A_SUGERIDA, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO, self::A_FIN_COMPOSER],
            'composer_retry' => [self::A_SUGERIDA, self::A_MARCADOR, self::A_PRECIO, self::A_FIN_COMPOSER],
            'critic' => [self::A_FIN_CRITIC, self::A_FIN_CRITIC],
            'critic_retry' => [self::A_FIN_CRITIC, self::A_FIN_CRITIC],
        ];
        $fallos = [];
        foreach ($casos as $g) {
            $nombre = json_encode($g, JSON_UNESCAPED_UNICODE);
            $p = C::patches($g);

            $delMotor = [];
            foreach (array_filter($p, fn (array $x): bool => $x['parameter'] === C::ENGINE) as $x) {
                $delMotor[$x['role']][] = $x['anchor'];
            }
            $this->assertSame($esperadas, $delMotor, "{$nombre}: el parche del motor usa en cada rol exactamente sus anclas");

            // Aplicados uno detrás de otro: cada ancla, exactamente una vez en el texto en el que se aplica.
            $roles = Base::roles();
            foreach ($p as $k => $x) {
                if (substr_count($roles[$x['role']], $x['anchor']) !== 1) {
                    $fallos[] = "{$nombre} #{$k}: «{$x['anchor']}» en {$x['role']}";
                }
                $roles[$x['role']] = $aplicar->invoke(null, $roles[$x['role']], $x);
                foreach (self::ANCLAS as $ancla) {
                    if (str_contains($x['text'], $ancla)) {
                        $fallos[] = "{$nombre} #{$k}: el texto contiene «{$ancla}»";
                    }
                }
            }
            $c = C::compile($g);
            $this->assertSame($roles, $c['roles'], $nombre);
            // Los textos del motor quedan los últimos del prompt, con cualquier otro parche; la guía, con la L y la W de su tope.
            foreach (['composer', 'composer_retry'] as $rol) {
                $l = explode("\n", $c['roles'][$rol]);
                $this->assertSame(self::lista($g[C::MAX_CHARS]), end($l), "{$rol}: {$nombre}");
            }
            foreach (['critic', 'critic_retry'] as $rol) {
                $this->assertSame([self::PLANS_CRITIC_CHECKED, self::PLANS_CRITIC], array_slice(explode("\n", $c['roles'][$rol]), -2), "{$rol}: {$nombre}");
            }
            // Ninguna ancla queda repetida en lo compilado (las sustituidas desaparecen; las demás, una vez).
            foreach ($c['roles'] as $rol => $texto) {
                foreach (self::ANCLAS as $ancla) {
                    if (substr_count($texto, $ancla) > 1) {
                        $fallos[] = "{$nombre}: «{$ancla}» repetida en {$rol}";
                    }
                }
                $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, strlen($texto));
            }
            $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $c['bytes']);
        }
        $this->assertSame([], $fallos);
    }

    // ── N4 ──────────────────────────────────────────────────────────────────

    /** Los cinco textos nuevos (y los cuatro del 5 que siguen): sus bytes, su forma y su tope. */
    public function test_n4_los_textos_al_byte_y_en_su_forma(): void
    {
        // La guía, con cualquier tope, mide lo mismo: L tiene tres cifras y W dos.
        $guias = array_map(self::lista(...), range(C::MAX_CHARS_MIN, C::MAX_CHARS_MAX, C::MAX_CHARS_STEP));
        $this->assertSame([1127], array_values(array_unique(array_map('strlen', $guias))));
        $nuevos = [self::PLANS_COMPOSER_ASK, self::PLANS_COMPOSER_PRICE, self::lista(200), self::PLANS_CRITIC, self::PLANS_CRITIC_CHECKED];
        $this->assertSame([146, 184, 1127, 1119, 265], array_map('strlen', $nuevos));
        $siguen = [self::PLANS_STRATEGIST, self::STRATEGIST_PRESENTALO, self::COMPOSER_MARCADOR, self::COMPOSER_ELIGE];
        $this->assertSame([784, 158, 165, 220], array_map('strlen', $siguen));
        foreach ([...$nuevos, ...$guias, ...$siguen] as $t) {
            $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($t));
            $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $t, 'ASCII imprimible, una línea, como el resto');
            $this->assertStringNotContainsString('{', $t, 'sin llaves: no forma un marcador ni un hueco');
            $this->assertStringNotContainsString('}', $t);
            foreach (self::ANCLAS as $ancla) {
                $this->assertStringNotContainsString($ancla, $t, 'ningún texto contiene un ancla');
            }
        }
        // Ninguna cifra del catálogo: solo las de PLAN_1 a PLAN_3, los 25 de un marcador y, en la guía, su L y su W.
        foreach ([...$nuevos, ...$siguen] as $t) {
            preg_match_all('/\d+/', $t, $cifras);
            $this->assertSame([], array_values(array_diff(array_unique($cifras[0]), ['1', '2', '3', '25', '320', '35'])), $t);
        }
        preg_match_all('/\d+/', self::lista(480), $cifras);
        $this->assertSame(['1', '2', '3', '480', '25', '60'], array_values(array_unique($cifras[0])));
        // Los sustitutos no contienen su ancla (cambia el signo final) y empiezan por ella sin ese signo.
        foreach ([[self::A_SUGERIDA, self::PLANS_COMPOSER_ASK], [self::A_PRECIO, self::PLANS_COMPOSER_PRICE], [self::A_PRESENTALO, self::STRATEGIST_PRESENTALO], [self::A_MARCADOR, self::COMPOSER_MARCADOR], [self::A_ELIGE, self::COMPOSER_ELIGE]] as [$ancla, $sustituto]) {
            $this->assertStringNotContainsString($ancla, $sustituto);
            $this->assertStringStartsWith(substr($ancla, 0, -1), $sustituto, 'empieza por la frase de la base sin su signo final');
        }
        // La guía no pone en duda el tope publicado como cifra: dice el de ESTE turno y que manda sobre el tope general solo en él.
        $this->assertStringNotContainsString('TOPE DE LONGITUD PUBLICADO', self::PLANS_COMPOSER);
        $this->assertStringContainsString('LONGITUD DE ESTE TURNO: el mensaje completo, ya con nombres y precios, no pasa de {L} caracteres', self::PLANS_COMPOSER);
        $this->assertStringContainsString('En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar y el tope general).', self::PLANS_COMPOSER);
        // Y son los del compilador: la v4 los lleva, cada uno en su rol.
        $roles = C::compile(self::v4())['roles'];
        foreach ([[self::PLANS_COMPOSER_ASK, 'composer'], [self::PLANS_COMPOSER_PRICE, 'composer_retry'], [self::lista(200), 'composer'], [self::PLANS_CRITIC, 'critic_retry'], [self::PLANS_CRITIC_CHECKED, 'critic']] as [$t, $rol]) {
            $this->assertSame(1, substr_count($roles[$rol], $t), $rol);
        }
    }

    // ── N5 ──────────────────────────────────────────────────────────────────

    /**
     * DECISIÓN A.1 DEL PROPIETARIO: el redactor tiene libertad de redacción.
     * Ningún texto del motor que lee un redactor lleva una frase modelo («Claro,
     * tenemos…»), una pieza de plantilla (B1, Q) ni una pregunta de cierre fija
     * («¿Cuál revisamos?»): ni comillas que encierren un ejemplo, ni signos de
     * interrogación. Se mira lo que COMPILA, en toda la rejilla.
     */
    public function test_n5_ninguna_frase_modelo_ni_pregunta_de_cierre_fija_en_el_redactor(): void
    {
        $prohibidas = ['Claro', 'tenemos', 'Cual revisamos', 'revisamos', 'exactamente', 'SOLO esta frase', 'en este orden', 'B1', 'B2', 'B3', ' Q ', 'Q es', 'Q.', 'Ejemplo', 'ejemplo', 'por ejemplo', 'Por ejemplo', 'plantilla'];
        $vistos = 0;
        foreach (self::noBase() as $g) {
            foreach (C::patches($g) as $p) {
                if ($p['parameter'] !== C::ENGINE || ! in_array($p['role'], ['composer', 'composer_retry'], true)) {
                    continue;
                }
                $vistos++;
                foreach ($prohibidas as $x) {
                    $this->assertStringNotContainsString($x, $p['text'], "un texto del motor del redactor no lleva «{$x}»");
                }
                $this->assertStringNotContainsString('"', $p['text'], 'sin comillas: ninguna frase que copiar');
                $this->assertStringNotContainsString("'", $p['text']);
                $this->assertStringNotContainsString('?', $p['text'], 'sin una pregunta escrita que el modelo pueda copiar');
            }
        }
        $this->assertSame(305 * 9, $vistos, 'las nueve entradas del motor de los redactores, en los 305 casos');
        // Ni en lo compilado de la v4: ni la plantilla ni su pregunta en ningún rol.
        foreach (C::compile(self::v4())['roles'] as $rol => $texto) {
            $this->assertStringNotContainsString('Claro, tenemos', $texto, $rol);
            $this->assertStringNotContainsString('Cual revisamos', $texto, $rol);
            $this->assertStringNotContainsString(self::PLANTILLA_DEL_DISENO, $texto, $rol);
        }
        // Lo que sí dice: con sus palabras, una o dos frases, y UNA pregunta breve opcional.
        $this->assertStringContainsString('Presentalos con tus palabras, en una o dos frases naturales', self::PLANS_COMPOSER);
        $this->assertStringContainsString('Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui.', self::PLANS_COMPOSER);
        // Y el revisor, sin la lista cerrada de registro del diseño.
        $this->assertStringNotContainsString('solo es tuteo una palabra', self::PLANS_CRITIC_CHECKED);
        $this->assertStringContainsString('el registro (tu/usted) y la longitud ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos.', self::PLANS_CRITIC_CHECKED);
    }

    // ── N6 ──────────────────────────────────────────────────────────────────

    public function test_n6_la_linea_base_es_la_base_y_su_huella_es_legacy(): void
    {
        $c = C::compile(C::BASELINE);
        $this->assertSame([], C::patches(C::BASELINE));
        $this->assertSame([], $c['patches']);
        $this->assertSame(Base::roles(), $c['roles']);
        $this->assertSame('3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902', $c['bundleHash'], 'la huella Legacy, escrita a mano: el compilador 6 no la mueve');
        $this->assertSame(Base::LEGACY_BUNDLE_HASH, $c['bundleHash']);
        foreach ([self::lista(200), self::lista(480), self::PLANS_CRITIC, self::PLANS_CRITIC_CHECKED, self::PLANS_COMPOSER_ASK, self::PLANS_COMPOSER_PRICE] as $t) {
            $this->assertStringNotContainsString($t, implode("\n", $c['roles']));
        }
    }

    // ── N7 ──────────────────────────────────────────────────────────────────

    public function test_n7_el_laboratorio_frente_al_motor_sigue_al_compilador_6(): void
    {
        $this->assertSame(C::VERSION, StudioLabEngineFlow::COMPILER);
        $this->assertSame(6, StudioLabEngineFlow::COMPILER);
        $this->assertSame('motor-S2-c6-2026-11-22.1', StudioLabEngineFlow::promptVersion());
        $this->assertTrue(StudioLabEngineFlow::isEnginePromptVersion(StudioLabEngineFlow::promptVersion()));
    }

    // ── N8 ──────────────────────────────────────────────────────────────────

    /**
     * DECISIÓN A.1: L y W salen CALCULADOS del tope gobernado, con 160, 200,
     * 320 y 480 (320 → 320 y 35; 480 → 480 y 60), y L es la cifra con la que
     * Laravel mide la respuesta con la lista
     * ({@see UltronReplyFitter::plansListCap()}): lo que se le pide al modelo
     * y lo que se le exige no pueden separarse.
     */
    public function test_n8_l_y_w_salen_del_tope_gobernado_y_l_es_la_cifra_de_laravel(): void
    {
        $esperado = [160 => [320, 35], 200 => [320, 35], 320 => [320, 35], 480 => [480, 60]];
        foreach ($esperado as $n => [$l, $w]) {
            $this->assertSame([$l, $w], [C::plansListChars($n), C::plansListWords($n)], "tope {$n}");
            // Con 320 la versión necesita otro parche gobernado para llevar el del motor (aquí, el tono).
            $g = array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => $n]);
            $roles = C::compile($g)['roles'];
            foreach (['composer', 'composer_retry'] as $rol) {
                $ultima = substr($roles[$rol], (int) strrpos($roles[$rol], "\n") + 1);
                $this->assertSame(self::lista($n), $ultima, "{$rol} con tope {$n}");
                $this->assertStringContainsString("no pasa de {$l} caracteres", $ultima);
                $this->assertStringContainsString("unas {$w} palabras tuyas", $ultima);
            }
            // La misma L que aplica Laravel a una respuesta con dos marcadores de la lista (compilador 6).
            $sp = ['m' => 'studio', 'n' => $n, 'k' => C::VERSION];
            $this->assertSame($l, UltronReplyFitter::plansListCap($sp, [3, 4, 8], 'Tenemos {{PLAN_1}} y {{PLAN_2}}.'), "tope {$n}");
        }
        // Toda la rejilla: L = max(N, 320) y W = intdiv(L − 110, 30) · 5, de 35 a 60.
        foreach (range(C::MAX_CHARS_MIN, C::MAX_CHARS_MAX, C::MAX_CHARS_STEP) as $n) {
            $this->assertSame(max($n, 320), C::plansListChars($n));
            $this->assertSame(intdiv(max($n, 320) - 110, 30) * 5, C::plansListWords($n));
        }
        $this->assertSame(320, C::PLANS_LIST_MIN_CHARS);
    }
}
