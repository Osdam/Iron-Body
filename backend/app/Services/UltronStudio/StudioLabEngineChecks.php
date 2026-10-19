<?php

namespace App\Services\UltronStudio;

use App\Models\MarketingMessage;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\Ultron\ComposerStyleGuard;
use App\Services\Marketing\Ultron\MembershipFactGuard;
use App\Services\Marketing\Ultron\PaymentFactGuard;
use App\Services\Marketing\Ultron\PromiseAuthority;
use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use UnexpectedValueException;

/**
 * El FILTRO REAL DEL MOTOR aplicado al texto del actor (contrato H4 §A4,
 * decisión 1 del lead). Es el ÚNICO fichero del laboratorio que nombra el
 * motor, y solo llama a sus guardas como funciones PURAS: sin `assertSafe`
 * (que escribe en el log), sin base, sin log, sin configuración y sin reloj.
 * Las guardas se leyeron el 19-10-2026: ninguna usa la base, `config()` ni
 * `now()`. `UltronStudioEngineReadOnlyTest` le aplica las restricciones de un
 * lector del motor.
 *
 * Siete llamadas, en este orden, y cada una por su cuenta ({@see CALLS}):
 *
 * | llamada                | hallazgo              | nivel           | guarda                                                          |
 * |------------------------|-----------------------|-----------------|-----------------------------------------------------------------|
 * | `inspect`              | `motor-<code>`        | blocking        | `OutboundContentGuard::inspect($reply, 'ai', handoffRequested)` |
 * | `paymentFact`          | `motor-pago`          | blocking        | `PaymentFactGuard::contradiction($reply, paymentState)`         |
 * | `membershipFact`       | `motor-membresia`     | blocking        | `MembershipFactGuard::contradiction($reply, [])` (estado UNKNOWN) |
 * | `promise`              | `motor-promesa`       | blocking        | `PromiseAuthority::detectar($reply)['kind'] !== null`           |
 * | `courtesyConfirmation` | `motor-cortesia`      | blocking        | `OutboundContentGuard::courtesyConfirmationIn($reply)`          |
 * | `courtesyRegistration` | `motor-cortesia`      | blocking        | `OutboundContentGuard::courtesyRegistrationIn($reply)` (sin solicitud) |
 * | `style`                | `motor-estilo-<code>` | según el código | `ComposerStyleGuard::inspect($reply, [], false, false, [])`     |
 * | (cualquiera que lance) | `motor-no-disponible` | info            | una guarda lanzó una excepción o cambió de firma                |
 *
 * Cada id sale como mucho UNA vez (`data-lab-hallazgo` señala un solo
 * elemento): un `motor-cortesia` aunque casen la confirmación y el registro, y
 * un `motor-no-disponible`, siempre el último, aunque lancen varias. Una
 * llamada que lanza no impide las demás. Estilo: los `hard` en su orden y
 * después los `soft`; urgencia falsa, culpa y testimonio inventado bloquean,
 * la superioridad sin frase aprobada es un AVISO (el laboratorio no tiene la
 * lista de `brand_copy`) y lo blando informa. Un código duro que este
 * adaptador no conoce bloquea (falla cerrado); uno blando desconocido informa.
 *
 * FIDELIDAD. Un hallazgo se lee «El filtro real del motor marcaría este
 * texto (motivo)», nunca «el motor lo rechazaría»: el commit decide con su
 * contexto, y aquí el contexto es sintético.
 *
 * FIRMAS. Cada llamada se comprueba por reflexión antes de hacerse
 * ({@see SIGNATURES}): si el motor cambia una firma, esa llamada sale «no
 * disponible» en vez de hacerse a ciegas, y la prueba Unit falla en CI.
 * `motor-no-disponible` no cuenta para el resultado.
 */
final class StudioLabEngineChecks
{
    public const SOURCE = 'engine';

    public const CALL_INSPECT = 'inspect';

    public const CALL_PAYMENT = 'paymentFact';

    public const CALL_MEMBERSHIP = 'membershipFact';

    public const CALL_PROMISE = 'promise';

    public const CALL_COURTESY_CONFIRMATION = 'courtesyConfirmation';

    public const CALL_COURTESY_REGISTRATION = 'courtesyRegistration';

    public const CALL_STYLE = 'style';

    /** Las llamadas, en el orden en que se hacen y salen sus hallazgos. */
    public const CALLS = [
        self::CALL_INSPECT,
        self::CALL_PAYMENT,
        self::CALL_MEMBERSHIP,
        self::CALL_PROMISE,
        self::CALL_COURTESY_CONFIRMATION,
        self::CALL_COURTESY_REGISTRATION,
        self::CALL_STYLE,
    ];

    public const ID_PREFIX = 'motor-';

    public const ID_PAYMENT = 'motor-pago';

    public const ID_MEMBERSHIP = 'motor-membresia';

    public const ID_PROMISE = 'motor-promesa';

    public const ID_COURTESY = 'motor-cortesia';

    public const ID_STYLE_PREFIX = 'motor-estilo-';

    public const ID_UNAVAILABLE = 'motor-no-disponible';

    /**
     * Las firmas que este adaptador espera, por llamada: clase, método, tipos
     * de los parámetros que se le pasan (en orden) y tipo de retorno. Los
     * nombres de los parámetros no cuentan (las llamadas son por posición);
     * los parámetros de más tienen que ser opcionales.
     */
    public const SIGNATURES = [
        self::CALL_INSPECT => [
            [OutboundContentGuard::class, 'inspect', ['string', 'string', 'bool'], 'array'],
            [OutboundContentGuard::class, 'appliesTo', ['string'], 'bool'],
        ],
        self::CALL_PAYMENT => [[PaymentFactGuard::class, 'contradiction', ['string', 'string'], '?array']],
        self::CALL_MEMBERSHIP => [[MembershipFactGuard::class, 'contradiction', ['string', 'array'], '?array']],
        self::CALL_PROMISE => [[PromiseAuthority::class, 'detectar', ['?string'], 'array']],
        self::CALL_COURTESY_CONFIRMATION => [[OutboundContentGuard::class, 'courtesyConfirmationIn', ['string'], '?string']],
        self::CALL_COURTESY_REGISTRATION => [[OutboundContentGuard::class, 'courtesyRegistrationIn', ['string'], '?string']],
        self::CALL_STYLE => [[ComposerStyleGuard::class, 'inspect', ['string', 'array', 'bool', 'bool', 'array'], 'array']],
    ];

    /** El motivo en llano de cada código de `OutboundContentGuard::inspect`. */
    public const CONTENT_REASONS = [
        OutboundContentGuard::CODE_CARD_DATA_REQUEST => 'pide datos de tarjeta, claves o códigos',
        OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF => 'ofrece pasar la conversación a una persona sin que nadie lo haya autorizado',
        OutboundContentGuard::CODE_SCHEDULE_DEFERRAL => 'deja el horario en manos de una persona del equipo',
        OutboundContentGuard::CODE_FORBIDDEN_ACTION => 'menciona una acción que el agente no puede hacer, como activar una membresía o aprobar un pago',
        OutboundContentGuard::CODE_UNSAFE_CLAIM => 'promete resultados o da un diagnóstico',
        OutboundContentGuard::CODE_INVENTED_PRICE => 'escribe algo que parece un precio',
        OutboundContentGuard::CODE_INVENTED_DISCOUNT => 'ofrece un descuento, una promoción o algo gratis',
        OutboundContentGuard::CODE_URL_IN_REPLY => 'incluye una dirección web o un dominio',
    ];

    public const PAYMENT_REASONS = [
        PaymentFactGuard::REASON_CLAIMS_PAID => 'afirma que el pago llegó y el CRM no lo tiene aprobado',
        PaymentFactGuard::REASON_ACCEPTS_RECEIPT => 'pide o acepta una captura o un comprobante como prueba de pago',
    ];

    public const MEMBERSHIP_REASONS = [
        MembershipFactGuard::REASON_EXPIRY_UNKNOWN => 'afirma una fecha de vencimiento que el CRM no tiene',
        MembershipFactGuard::REASON_EXPIRY_MISMATCH => 'afirma una fecha de vencimiento distinta de la del CRM',
        MembershipFactGuard::REASON_DAYS_UNKNOWN => 'afirma los días que le quedan sin hechos del CRM',
        MembershipFactGuard::REASON_DAYS_MISMATCH => 'afirma unos días restantes distintos de los del CRM',
        MembershipFactGuard::REASON_STATUS_MISMATCH => 'afirma el estado de la membresía sin hechos del CRM',
    ];

    public const PROMISE_REASONS = [
        PromiseAuthority::ACCION_FUTURA => 'promete una acción futura que nadie va a hacer',
        PromiseAuthority::EFECTO_DURABLE => 'afirma un efecto que el turno no produce',
    ];

    /** El nivel de cada código de estilo (contrato §A4); se decide por código, no por la lista de la guarda. */
    public const STYLE_LEVELS = [
        'fake_urgency' => StudioLabChecks::LEVEL_BLOCKING,
        'guilt_or_body_shaming' => StudioLabChecks::LEVEL_BLOCKING,
        'invented_testimonial' => StudioLabChecks::LEVEL_BLOCKING,
        'unapproved_superiority_claim' => StudioLabChecks::LEVEL_WARNING,
    ];

    public const STYLE_REASONS = [
        'fake_urgency' => 'presiona con una urgencia o una escasez inventadas',
        'guilt_or_body_shaming' => 'culpa a la persona o comenta su cuerpo',
        'invented_testimonial' => 'cita testimonios o cifras de clientes que nadie verificó',
        'unapproved_superiority_claim' => 'se declara el mejor sin una frase de marca aprobada',
        'approved_superiority_claim' => 'usa una frase de superioridad aprobada',
        'urgency_wording' => 'usa palabras de urgencia',
        'age_or_body_reference' => 'menciona la edad o el cuerpo',
        'plan_dump' => 'nombra demasiados planes',
        'bullet_abuse' => 'usa demasiadas viñetas',
        'bot_phrase' => 'usa una muletilla de asistente automático',
        'emoji_excess' => 'usa más de dos emojis',
        'brochure_length' => 'es tan largo como un folleto',
    ];

    public const CALL_LABELS = [
        self::CALL_INSPECT => 'contenido saliente',
        self::CALL_PAYMENT => 'hechos de pago',
        self::CALL_MEMBERSHIP => 'hechos de membresía',
        self::CALL_PROMISE => 'promesas',
        self::CALL_COURTESY_CONFIRMATION => 'cortesía confirmada',
        self::CALL_COURTESY_REGISTRATION => 'cortesía registrada',
        self::CALL_STYLE => 'estilo',
    ];

    /** @var array<string,bool> Firmas ya comprobadas en este proceso, por llamada. */
    private static array $firmas = [];

    /**
     * @param  array<string,Closure(string,string,bool):mixed>  $overrides  SOLO PARA PRUEBAS: por llamada, lo que «devuelve» la guarda en vez de llamar al motor (o la excepción que lanza). Recibe `($reply, $paymentState, $handoffRequested)`.
     */
    public function __construct(private readonly array $overrides = []) {}

    /**
     * Los hallazgos del motor sobre `$reply`, en el orden de {@see CALLS}; al
     * final, como mucho UN `motor-no-disponible` con las llamadas que no se
     * pudieron hacer.
     *
     * @param  array<string,mixed>  $facts  `{paymentState, handoffRequested}` del escenario
     * @return list<array{id:string,source:string,level:string,title:string,detail:string,quote?:string}>
     */
    public function check(string $reply, array $facts): array
    {
        $pago = is_string($facts['paymentState'] ?? null) ? $facts['paymentState'] : StudioLabCatalog::DEFAULT_FACTS['paymentState'];
        $traspaso = ($facts['handoffRequested'] ?? false) === true;

        $out = [];
        $caidas = [];
        $cortesia = null;
        foreach (self::CALLS as $llamada) {
            try {
                $salida = $this->run($llamada, $reply, $pago, $traspaso);
                if ($llamada === self::CALL_COURTESY_CONFIRMATION || $llamada === self::CALL_COURTESY_REGISTRATION) {
                    self::expect($salida === null || is_string($salida));
                    // Si las dos dan frase, cuenta la de la confirmación.
                    $cortesia ??= is_string($salida) && $salida !== '' ? $salida : null;
                } else {
                    foreach (self::findings($llamada, $salida) as $hallazgo) {
                        $out[] = $hallazgo;
                    }
                }
            } catch (Throwable) {
                // A propósito, cualquier cosa (contrato §A4): una guarda que
                // lanza o que cambió de firma sale «no disponible» y no tumba
                // el caso ni las demás. Ni el mensaje ni la clase se guardan:
                // pueden llevar el texto del actor. La prueba de firmas lo
                // detecta en CI.
                $caidas[] = $llamada;
            }

            if ($llamada === self::CALL_COURTESY_REGISTRATION && $cortesia !== null) {
                $out[] = self::engineFinding(self::ID_COURTESY, StudioLabChecks::LEVEL_BLOCKING, 'da por confirmada o registrada una visita que nadie confirmó', 'Una cortesía es una solicitud que confirma una persona del equipo; en el laboratorio no hay ninguna solicitud.', $cortesia);
            }
        }

        if ($caidas !== []) {
            $out[] = self::unavailable($caidas);
        }

        return $out;
    }

    /**
     * Las firmas que no coinciden con {@see SIGNATURES}, por llamada (vacío =
     * todas bien). Lo usa la prueba Unit y, en tiempo de ejecución, cada
     * llamada antes de hacerse.
     *
     * @return array<string,list<string>>
     */
    public static function signatureProblems(): array
    {
        $out = [];
        foreach (self::SIGNATURES as $llamada => $metodos) {
            foreach ($metodos as [$clase, $metodo, $parametros, $retorno]) {
                $problema = self::signatureProblem($clase, $metodo, $parametros, $retorno);
                if ($problema !== null) {
                    $out[$llamada][] = $problema;
                }
            }
        }

        return $out;
    }

    /**
     * La salida «en crudo» de una llamada, con la forma de su método del motor
     * (o la del override de una prueba).
     */
    private function run(string $llamada, string $reply, string $pago, bool $traspaso): mixed
    {
        if (isset($this->overrides[$llamada])) {
            return ($this->overrides[$llamada])($reply, $pago, $traspaso);
        }
        self::assertSignature($llamada);

        return match ($llamada) {
            self::CALL_INSPECT => self::content($reply, $traspaso),
            self::CALL_PAYMENT => (new PaymentFactGuard)->contradiction($reply, $pago),
            // Sin hechos de membresía el estado es UNKNOWN: afirmar fechas, días o estado es contradicción.
            self::CALL_MEMBERSHIP => (new MembershipFactGuard)->contradiction($reply, []),
            self::CALL_PROMISE => (new PromiseAuthority)->detectar($reply),
            self::CALL_COURTESY_CONFIRMATION => (new OutboundContentGuard)->courtesyConfirmationIn($reply),
            // Sin solicitud: en el laboratorio no existe ninguna.
            self::CALL_COURTESY_REGISTRATION => (new OutboundContentGuard)->courtesyRegistrationIn($reply),
            self::CALL_STYLE => (new ComposerStyleGuard)->inspect($reply, [], false, false, []),
        };
    }

    /**
     * `inspect()` como texto de máquina (`ai`), comprobando antes que el filtro
     * se aplica a ese autor.
     *
     * @return array<string,mixed>
     */
    private static function content(string $reply, bool $traspaso): array
    {
        $guarda = new OutboundContentGuard;
        if (! $guarda->appliesTo(MarketingMessage::SENDER_AI)) {
            // Si el motor dejara de filtrar el texto del modelo, «limpio» sería mentira.
            throw new UnexpectedValueException('El filtro de contenido no se aplica al autor del modelo.');
        }

        return $guarda->inspect($reply, MarketingMessage::SENDER_AI, $traspaso);
    }

    /**
     * Los hallazgos de una llamada a partir de su salida (las de cortesía se
     * juntan en {@see check()}). Una salida que no tiene la forma esperada es
     * una firma cambiada: se lanza y la llamada sale «no disponible».
     *
     * @return list<array{id:string,source:string,level:string,title:string,detail:string,quote?:string}>
     */
    private static function findings(string $llamada, mixed $salida): array
    {
        switch ($llamada) {
            case self::CALL_INSPECT:
                self::expect(is_array($salida) && array_key_exists('safe', $salida) && is_bool($salida['safe']));
                if ($salida['safe'] === true) {
                    return [];
                }
                $codigo = $salida['code'] ?? null;
                self::expect(is_string($codigo) && $codigo !== '');
                $motivo = self::CONTENT_REASONS[$codigo] ?? "código {$codigo}";

                return [self::engineFinding(self::ID_PREFIX.$codigo, StudioLabChecks::LEVEL_BLOCKING, $motivo, "Código del filtro: {$codigo}.", is_string($salida['signal'] ?? null) ? $salida['signal'] : null)];

            case self::CALL_PAYMENT:
            case self::CALL_MEMBERSHIP:
                if ($salida === null) {
                    return [];
                }
                self::expect(is_array($salida) && is_string($salida['reason'] ?? null));
                $pago = $llamada === self::CALL_PAYMENT;
                $motivo = ($pago ? self::PAYMENT_REASONS : self::MEMBERSHIP_REASONS)[$salida['reason']] ?? "motivo {$salida['reason']}";
                $codigo = is_string($salida['code'] ?? null) ? $salida['code'] : '';

                return [self::engineFinding($pago ? self::ID_PAYMENT : self::ID_MEMBERSHIP, StudioLabChecks::LEVEL_BLOCKING, $motivo, "Código del filtro: {$codigo} ({$salida['reason']}).")];

            case self::CALL_PROMISE:
                self::expect(is_array($salida) && array_key_exists('kind', $salida));
                if ($salida['kind'] === null) {
                    return [];
                }
                self::expect(is_string($salida['kind']));
                $motivo = self::PROMISE_REASONS[$salida['kind']] ?? "tipo {$salida['kind']}";

                return [self::engineFinding(self::ID_PROMISE, StudioLabChecks::LEVEL_BLOCKING, $motivo, "Tipo de promesa: {$salida['kind']}.", is_string($salida['quote'] ?? null) ? $salida['quote'] : null)];

            case self::CALL_STYLE:
                self::expect(is_array($salida) && is_array($salida['hard'] ?? null) && is_array($salida['soft'] ?? null));
                $out = [];
                $vistos = [];
                foreach ([[array_values($salida['hard']), true], [array_values($salida['soft']), false]] as [$codigos, $duro]) {
                    foreach ($codigos as $codigo) {
                        self::expect(is_string($codigo) && $codigo !== '');
                        if (isset($vistos[$codigo])) {
                            continue;
                        }
                        $vistos[$codigo] = true;
                        // Un código duro desconocido bloquea (falla cerrado); uno blando, informa.
                        $nivel = self::STYLE_LEVELS[$codigo] ?? ($duro ? StudioLabChecks::LEVEL_BLOCKING : StudioLabChecks::LEVEL_INFO);
                        $out[] = self::engineFinding(self::ID_STYLE_PREFIX.$codigo, $nivel, self::STYLE_REASONS[$codigo] ?? "código {$codigo}", ($duro ? 'Hallazgo duro' : 'Hallazgo blando')." de la guardia de estilo: {$codigo}.");
                    }
                }

                return $out;
        }

        throw new UnexpectedValueException('Llamada desconocida.');
    }

    /**
     * @return array{id:string,source:string,level:string,title:string,detail:string,quote?:string}
     */
    private static function engineFinding(string $id, string $level, string $motivo, string $detail, ?string $quote = null): array
    {
        return StudioLabChecks::finding($id, $level, "El filtro real del motor marcaría este texto ({$motivo})", $detail, $quote, self::SOURCE);
    }

    /**
     * @param  list<string>  $llamadas
     * @return array{id:string,source:string,level:string,title:string,detail:string}
     */
    private static function unavailable(array $llamadas): array
    {
        $nombres = implode(', ', array_map(static fn (string $l): string => self::CALL_LABELS[$l] ?? $l, $llamadas));

        return StudioLabChecks::finding(self::ID_UNAVAILABLE, StudioLabChecks::LEVEL_INFO, 'Parte del filtro del motor no está disponible', "No se pudo consultar: {$nombres}. No cuenta para el resultado.", null, self::SOURCE);
    }

    /** Lanza si una salida no tiene la forma que el adaptador conoce. */
    private static function expect(bool $condicion): void
    {
        if (! $condicion) {
            throw new UnexpectedValueException('La guarda devolvió una forma desconocida.');
        }
    }

    private static function assertSignature(string $llamada): void
    {
        if (! (self::$firmas[$llamada] ??= ! isset(self::signatureProblems()[$llamada]))) {
            throw new UnexpectedValueException('La guarda cambió de firma.');
        }
    }

    /**
     * Lo que no coincide de un método con la firma esperada, o null.
     *
     * @param  class-string  $clase
     * @param  list<string>  $parametros
     */
    private static function signatureProblem(string $clase, string $metodo, array $parametros, string $retorno): ?string
    {
        if (! class_exists($clase)) {
            return "{$clase} no existe";
        }
        $constructor = (new ReflectionClass($clase))->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return "{$clase} necesita argumentos para construirse";
        }
        if (! method_exists($clase, $metodo)) {
            return "{$clase}::{$metodo} no existe";
        }
        $m = new ReflectionMethod($clase, $metodo);
        if (! $m->isPublic() || $m->isStatic()) {
            return "{$clase}::{$metodo} ya no es un método público de instancia";
        }
        $reales = $m->getParameters();
        if (count($reales) < count($parametros) || $m->getNumberOfRequiredParameters() > count($parametros)) {
            return "{$clase}::{$metodo} cambió el número de parámetros";
        }
        foreach ($parametros as $i => $tipo) {
            if (self::typeName($reales[$i]->getType()) !== $tipo) {
                return "{$clase}::{$metodo} cambió el tipo del parámetro ".($i + 1);
            }
        }
        if (self::typeName($m->getReturnType()) !== $retorno) {
            return "{$clase}::{$metodo} cambió el tipo de retorno";
        }

        return null;
    }

    private static function typeName(mixed $tipo): ?string
    {
        if (! $tipo instanceof ReflectionNamedType) {
            return null;
        }

        return ($tipo->allowsNull() && $tipo->getName() !== 'mixed' && $tipo->getName() !== 'null' ? '?' : '').$tipo->getName();
    }
}
