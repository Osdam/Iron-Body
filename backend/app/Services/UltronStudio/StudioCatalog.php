<?php

namespace App\Services\UltronStudio;

/**
 * El catálogo de ULTRON STUDIO: las diez capas, sus puertos y las aristas de
 * UN turno tal como funciona el motor real (auditoría del 17-10-2026), los
 * caminos y las restricciones que se validan, los parámetros que el inspector
 * sabe editar y la línea base del código.
 *
 * Es la AUTORIDAD. Antes viajaba dentro de un chunk público del CRM; ahora lo
 * sirve el backend (`GET catalog`) y el CRM lo pinta. Es una transcripción
 * literal de `studio-catalog.ts` (CRM, commit 4f7d774) y una prueba la compara
 * con el volcado de aquel fichero, así que cualquier diferencia rompe CI.
 *
 * Los `ref` son NOMBRES de clases, tablas o comandos, sin rutas ni números de
 * línea: esto lo lee cualquiera con permiso para ver el Studio y no debe
 * cartografiar el servidor. Otra prueba lo vigila.
 *
 * Nada de esto lo lee el motor de ULTRON: describe el motor, no lo gobierna.
 *
 * HITO G (contrato §A2 y §A3). Además del grafo, el catálogo dice de cada
 * parámetro qué mando real tiene en el motor (`bindings`: origen, si su valor
 * se lee en vivo, se declara o no existe, y quién lo cambia DE VERDAD), qué
 * otras lecturas del motor enseña el inspector (`readings`) y las condiciones
 * de las reglas del código (`legacyConditions`), que son controles del motor
 * para la coherencia de las reglas. Las restricciones que equivalen a una
 * regla del código la nombran (`rule`). Nada de eso cambia el grafo: los datos
 * de {@see graph()} son los de F, más la clave `rule`, que el validador ignora.
 *
 * VERSIÓN. Cambiar cualquier dato obliga a subir VERSION (una prueba fija la
 * huella de cada versión) y a volver a exportar los fixtures del CRM con
 * `ultron-studio:export-fixtures`.
 */
final class StudioCatalog
{
    public const VERSION = '2026-10-19.1';

    /** Nombre de la línea base del código y del lote de reglas que se importa de él. */
    public const BASELINE_LABEL = 'LEGACY_BASELINE_V1';

    public const BASELINE_DESCRIPTION = 'Lo que hace hoy el motor según el código. No es una versión: el motor no la lee del Studio.';

    /** @var array<string,mixed>|null Los datos sin la huella, montados una vez por proceso. */
    private static ?array $data = null;

    private static ?string $hash = null;

    /**
     * La forma de `GET catalog`: la de `StudioCatalog`, `LayerParameter` y
     * `StudioDefaults` del CRM más `version`, `hash`, `parameters` y `baseline`,
     * y desde G `bindings`, `readings` y `legacyConditions`.
     * Las claves opcionales (`feedback`, `opensTurn`, `options`, `min`, `max`,
     * `step`, `important`, `consequence`, `forbidden`, `when`, `requires`,
     * `forbids`, `rule`, `declaredValue`, `engineOption`) se OMITEN donde no
     * aplican: nunca valen `false` por defecto (`declaredValue` sí puede valer
     * `false`: es el valor que el código fija).
     *
     * @return array<string,mixed>
     */
    public static function toArray(): array
    {
        $data = self::data();

        return ['version' => $data['version'], 'hash' => self::hash()] + $data;
    }

    /**
     * Lo que valida {@see StudioGraphValidator}: capas, puertos, aristas,
     * caminos obligatorios y restricciones de la configuración.
     *
     * @return array{layers:list<array<string,mixed>>,ports:list<array<string,mixed>>,edges:list<array<string,mixed>>,requiredPaths:list<array<string,mixed>>,constraints:list<array<string,mixed>>}
     */
    public static function graph(): array
    {
        $data = self::data();

        return [
            'layers' => $data['layers'],
            'ports' => $data['ports'],
            'edges' => $data['edges'],
            'requiredPaths' => $data['requiredPaths'],
            'constraints' => $data['constraints'],
        ];
    }

    /**
     * Parámetros de borrador por capa: qué sabe editar el inspector y qué
     * admite cada uno (tipo, opciones, mínimo y máximo).
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function parameters(): array
    {
        return self::data()['parameters'];
    }

    /**
     * La línea base del código (`LEGACY_BASELINE_V1`): posiciones de fábrica,
     * aristas presentes hoy y configuración de hoy. No es una versión: el
     * motor no la lee del Studio.
     *
     * @return array{label:string,description:string,positions:array<string,array{x:int,y:int}>,edgeIds:list<string>,configs:array<string,array<string,mixed>>}
     */
    public static function baseline(): array
    {
        return self::data()['baseline'];
    }

    /** @return list<string> Las diez capas, en su orden. */
    public static function layerIds(): array
    {
        return array_column(self::data()['layers'], 'id');
    }

    /**
     * El mando real de cada parámetro (contrato §A2): capa → parámetro →
     * `{origin, valueSource, declaredValue?, engineOption?, comparable, effect, changeVia}`.
     * `live` = lo enseña `GET engine/readings`; `declared` = el valor que fija
     * el código (`declaredValue`); `none` = no hay un valor único que leer.
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    public static function bindings(): array
    {
        return self::data()['bindings'];
    }

    /**
     * Las lecturas del motor que no son parámetros (contrato §A2): pausa,
     * freno, canario del cobro, base de conocimiento y planes.
     *
     * @return list<array{id:string,layer:string,label:string,origin:string,effect:string,changeVia:string}>
     */
    public static function readings(): array
    {
        return self::data()['readings'];
    }

    /**
     * Las condiciones de las reglas del código que tienen (R-01, R-02, R-07 y
     * R-12), por código: los controles del motor que la coherencia de las
     * reglas del Studio usa en los dos lados (contrato §A3).
     *
     * @return array<string,array{when:list<array<string,mixed>>,then:list<array<string,mixed>>}>
     */
    public static function legacyConditions(): array
    {
        return self::data()['legacyConditions'];
    }

    /** sha256 del JSON canónico de {@see toArray()} sin la propia huella. */
    public static function hash(): string
    {
        return self::$hash ??= StudioContentHasher::hash(self::data());
    }

    /**
     * Las reglas comerciales REALES, transcritas del código el 17-10-2026. No
     * se leen en vivo: el comando `ultron-studio:import-baseline` las copia a la
     * tabla de reglas como reglas del código. `refs` señala los parámetros,
     * aristas y categorías de conocimiento sobre los que actúa cada una.
     *
     * `conditions` (Hito G, contrato §A3, sin inventar): solo las cuatro reglas
     * que equivalen a una restricción del catálogo (`hechos`→R-01,
     * `descuentos`→R-02, `derivacion`→R-07, `canario`→R-12) tienen condiciones;
     * el resto vale null (regla documental). La condición de texto de R-02 es
     * parcial y documental: el código aplica más patrones de los tres que cita
     * su descripción. Las aristas de `refs.edges` salen de la auditoría §5.
     *
     * @return list<array{code:string,description:string,type:string,layers:list<string>,priority:string,origin:string,status:string,refs:array{configs:list<array{layer:string,key:string}>,edges:list<string>,knowledgeKeys:list<string>,knowledgeCategories:list<string>},conditions:array{when:list<array<string,mixed>>,then:list<array<string,mixed>>}|null}>
     */
    public static function legacyRules(): array
    {
        return [
            ['code' => 'R-01', 'description' => 'El precio solo sale del catálogo, mediante el marcador PLAN_PRICE que resuelve el commit; el modelo nunca ve una cifra.', 'type' => 'restriction', 'layers' => ['knowledge', 'composer', 'rules'], 'priority' => 'critical', 'origin' => 'UltronDraftPlaceholders · OutboundContentGuard', 'status' => 'active',
                // Auditoría §5: «planes → plan y precio» en el commit (E19) y los marcadores al redactor (E07).
                'refs' => self::refs([['layer' => 'knowledge', 'key' => 'strictFacts']], [], ['E07', 'E19']),
                'conditions' => ['when' => [], 'then' => [self::requireEq('knowledge', 'strictFacts', true)]]],
            ['code' => 'R-02', 'description' => 'Ningún descuento: «gratis», «2x1» y «precio especial» cuentan como descuento y el commit los rechaza.', 'type' => 'restriction', 'layers' => ['strategy', 'rules'], 'priority' => 'critical', 'origin' => 'SalesAgentDecisionSchema (patrones de descuento)', 'status' => 'active',
                'refs' => self::refs([['layer' => 'strategy', 'key' => 'discountsAllowed']]),
                'conditions' => ['when' => [], 'then' => [
                    self::requireEq('strategy', 'discountsAllowed', false),
                    ['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['gratis', '2x1', 'precio especial']],
                ]]],
            ['code' => 'R-03', 'description' => 'Nunca URLs ni datos de tarjeta en una respuesta.', 'type' => 'restriction', 'layers' => ['rules', 'composer'], 'priority' => 'critical', 'origin' => 'OutboundContentGuard::inspect', 'status' => 'active', 'refs' => self::refs(), 'conditions' => null],
            // R-04, R-05 y R-09, auditoría §5: pago, membresía y perfil → commit (la etiqueta de E18 lo dice tal cual).
            ['code' => 'R-04', 'description' => 'No afirma un pago ni acepta una captura como prueba: solo lo que dice Wompi.', 'type' => 'restriction', 'layers' => ['memory', 'rules'], 'priority' => 'critical', 'origin' => 'PaymentFactGuard', 'status' => 'active', 'refs' => self::refs([], [], ['E18']), 'conditions' => null],
            ['code' => 'R-05', 'description' => 'No afirma fechas ni estado de membresía sin hechos del CRM.', 'type' => 'restriction', 'layers' => ['memory', 'rules'], 'priority' => 'critical', 'origin' => 'MembershipFactGuard', 'status' => 'active', 'refs' => self::refs([], [], ['E18']), 'conditions' => null],
            ['code' => 'R-06', 'description' => 'No promete acciones futuras ni efectos que no ocurren; una cortesía se registra como solicitud, nunca como confirmación.', 'type' => 'restriction', 'layers' => ['composer', 'rules'], 'priority' => 'critical', 'origin' => 'PromiseAuthority · CourtesyAuthority', 'status' => 'active', 'refs' => self::refs(), 'conditions' => null],
            ['code' => 'R-07', 'description' => 'Solo deriva a una persona si la petición está corroborada.', 'type' => 'condition', 'layers' => ['intent', 'rules'], 'priority' => 'high', 'origin' => 'HumanHandoffAuthority', 'status' => 'active',
                'refs' => self::refs([['layer' => 'rules', 'key' => 'handoffOnlyCorroborated']]),
                'conditions' => ['when' => [], 'then' => [self::requireEq('rules', 'handoffOnlyCorroborated', true)]]],
            ['code' => 'R-08', 'description' => 'Ante un saludo, recepción sin vender; a quien pide información, primero se le informa; no se saluda dos veces.', 'type' => 'condition', 'layers' => ['intent', 'strategy'], 'priority' => 'high', 'origin' => 'SalesIntents::isPureGreeting · CommercialTurnPolicy', 'status' => 'active', 'refs' => self::refs(), 'conditions' => null],
            ['code' => 'R-09', 'description' => 'El plan que nombra la persona manda; si no, el plan insignia. No se le vende a alguien el plan que ya tiene, salvo renovación.', 'type' => 'condition', 'layers' => ['strategy', 'rules'], 'priority' => 'high', 'origin' => 'CommercialTurnPolicy · UltronCommitService (reventa)', 'status' => 'active', 'refs' => self::refs([], [], ['E18']), 'conditions' => null],
            // Auditoría §5: `brand_copy` → ComposerStyleGuard en el commit (E19); E08 lleva brand_copy al redactor.
            ['code' => 'R-10', 'description' => 'Superlativos solo si están en brand_copy; sin presión ni urgencia inventada.', 'type' => 'style', 'layers' => ['persona', 'composer'], 'priority' => 'normal', 'origin' => 'ComposerStyleGuard', 'status' => 'active', 'refs' => self::refs([], ['brand_copy', 'tone'], ['E08', 'E19']), 'conditions' => null],
            ['code' => 'R-11', 'description' => 'No escribe a quien pidió no recibir mensajes, ni a otro lead con el mismo teléfono que lo haya pedido.', 'type' => 'restriction', 'layers' => ['context', 'integrations'], 'priority' => 'critical', 'origin' => 'ConsentAuthority · MarketingMessageDispatcher', 'status' => 'active', 'refs' => self::refs(), 'conditions' => null],
            ['code' => 'R-12', 'description' => 'Solo la conversación canaria entra en ULTRON; el resto lo atiende una persona.', 'type' => 'permission', 'layers' => ['governance', 'integrations'], 'priority' => 'high', 'origin' => 'UltronEventEmitter · UltronCommitService (canario)', 'status' => 'active',
                // Las aristas que llevan el canario al commit (E12) y a las puertas de entrada (E13).
                'refs' => self::refs([['layer' => 'governance', 'key' => 'canaryOnly']], [], ['E12', 'E13']),
                'conditions' => ['when' => [], 'then' => [self::requireEq('governance', 'canaryOnly', true)]]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $edges = self::edges();

        $legacyConditions = [];
        foreach (self::legacyRules() as $r) {
            if ($r['conditions'] !== null) {
                $legacyConditions[$r['code']] = $r['conditions'];
            }
        }

        return self::$data = [
            'version' => self::VERSION,
            'layers' => self::layers(),
            'ports' => self::ports(),
            'edges' => $edges,
            'requiredPaths' => self::requiredPaths(),
            'constraints' => self::constraints(),
            'parameters' => self::layerParameters(),
            'baseline' => [
                'label' => self::BASELINE_LABEL,
                'description' => self::BASELINE_DESCRIPTION,
                'positions' => self::positions(),
                // Producción hoy: los enlaces de pago están apagados, así que E14 y E16 no están en la línea base.
                'edgeIds' => array_values(array_map(
                    static fn (array $e): string => $e['id'],
                    array_filter($edges, static fn (array $e): bool => $e['id'] !== 'E14' && $e['id'] !== 'E16'),
                )),
                'configs' => self::baselineConfigs(),
            ],
            'bindings' => self::parameterBindings(),
            'readings' => self::engineReadings(),
            'legacyConditions' => $legacyConditions,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function layers(): array
    {
        return [
            [
                'id' => 'knowledge',
                'order' => 1,
                'name' => 'Conocimiento',
                'responsibility' => 'Lo que el agente puede afirmar: base de conocimiento aprobada, planes (sin precio hasta el commit), clases, horarios y la app.',
                'icon' => 'menu_book',
                'accent' => 'violet',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Base de conocimiento: solo ítems activos, vigentes y aprobados', 'kind' => 'table', 'ref' => 'marketing_knowledge_items · MarketingKnowledgeItem'],
                    ['label' => 'Agrupación para el prompt y sello de versión', 'kind' => 'code', 'ref' => 'MarketingKnowledgeBaseService'],
                    ['label' => 'Planes vendibles (al prompt sin precio; el precio se pone en el commit)', 'kind' => 'table', 'ref' => 'plans · UltronDecideService · UltronCommitService'],
                    ['label' => 'Clases, entrenadores y horarios', 'kind' => 'code', 'ref' => 'GymFactsProvider'],
                    ['label' => 'Catálogo y enlaces de la app (fijo)', 'kind' => 'code', 'ref' => 'MobileAppCatalog · MobileAppLinks'],
                ],
                'configurableToday' => ['La base de conocimiento: seeder, consola (marketing:knowledge-review) o API interna (propone borradores). No hay panel en el CRM.', 'Planes, clases y entrenadores desde sus módulos del CRM.'],
                'fixedInCode' => ['Qué hechos cuentan como autorizados y de qué tabla salen', 'El catálogo de la app y la dirección (duplicada en código y en la base)'],
            ],
            [
                'id' => 'persona',
                'order' => 2,
                'name' => 'Personalidad y tono',
                'responsibility' => 'La voz: cercanía, registro, saludo según la hora, superlativos permitidos y lo que nunca dice.',
                'icon' => 'face',
                'accent' => 'magenta',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Prompts de «voz» (system de Composer y Composer Reintento)', 'kind' => 'n8n', 'ref' => 'copia fechada de los prompts (30-09)'],
                    ['label' => 'Tono, identidad y brand_copy en la base de conocimiento', 'kind' => 'table', 'ref' => 'marketing_knowledge_items (tone.*, brand_copy)'],
                    ['label' => 'Playbook de ventas: técnicas por fase y piso ético', 'kind' => 'code', 'ref' => 'UltronSalesPlaybook'],
                    ['label' => 'Guardia de estilo (la presión es 422; lo demás se anota)', 'kind' => 'code', 'ref' => 'ComposerStyleGuard'],
                    ['label' => 'Saludo según la franja horaria', 'kind' => 'code', 'ref' => 'BusinessClock'],
                ],
                'configurableToday' => ['La base de conocimiento (tone.*, brand_copy), con aprobación', 'Los prompts, en n8n (sin versión por turno)'],
                'fixedInCode' => ['Los límites de estilo que comprueba la guardia', 'Los textos curados de respaldo'],
            ],
            [
                'id' => 'intent',
                'order' => 3,
                'name' => 'Comprensión de intención',
                'responsibility' => 'Qué quiere la persona: el cerebro legado clasifica (27 intenciones), lo determinista manda, y el Strategist de n8n recibe la decisión base.',
                'icon' => 'psychology',
                'accent' => 'blue',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Cerebro legado: intención, temperatura y puntuación', 'kind' => 'code', 'ref' => 'SalesAgentOrchestratorService'],
                    ['label' => 'Driver efectivo: hermes → openai → reglas', 'kind' => 'config', 'ref' => 'SalesAiConfig'],
                    ['label' => 'Las 27 intenciones (contrato con n8n)', 'kind' => 'code', 'ref' => 'SalesAgentDecisionSchema'],
                    ['label' => 'Saludo puro, referencias y detectores por regex', 'kind' => 'code', 'ref' => 'SalesIntents · ReferenceResolver · Clausulas'],
                ],
                'configurableToday' => ['El driver y los parámetros del modelo (entorno)', 'El prompt del Strategist (n8n)'],
                'fixedInCode' => ['El vocabulario de intenciones', 'Los detectores deterministas (humano, «ya pagué», reconsentimiento, precio)'],
            ],
            [
                'id' => 'context',
                'order' => 4,
                'name' => 'Contexto de conversación',
                'responsibility' => 'Continuidad: los últimos 10 mensajes (cifras y URLs redactadas), la memoria del turno, la hora de negocio y la novedad.',
                'icon' => 'forum',
                'accent' => 'cyan',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Mensajes recientes redactados', 'kind' => 'code', 'ref' => 'UltronDecideService'],
                    ['label' => 'Memoria de la conversación (JSON)', 'kind' => 'code', 'ref' => 'ConversationMemory'],
                    ['label' => 'Hora de negocio', 'kind' => 'code', 'ref' => 'BusinessClock'],
                    ['label' => 'Novedad: 0,85 sobre 3 salidas en 24 h', 'kind' => 'code', 'ref' => 'UltronCommitService'],
                    ['label' => 'Toma manual, asignación y pausa por conversación', 'kind' => 'code', 'ref' => 'MarketingManualTakeoverService · MarketingConversationAssignmentService'],
                ],
                'configurableToday' => ['ai_enabled y human_takeover por conversación (bandeja)'],
                'fixedInCode' => ['Cuántos mensajes entran (10)', 'Los umbrales de novedad'],
            ],
            [
                'id' => 'memory',
                'order' => 5,
                'name' => 'Memoria comercial',
                'responsibility' => 'Lo autorizado para dar seguimiento: ficha del prospecto, inteligencia de cliente, hechos de pago y membresía, compromisos.',
                'icon' => 'memory',
                'accent' => 'violet',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Ficha permanente del prospecto', 'kind' => 'table', 'ref' => 'marketing_leads.metadata.ultron_profile · LeadProfileService'],
                    ['label' => 'Inteligencia de cliente: hechos conocidos, inferidos, temperatura, ciclo de vida', 'kind' => 'code', 'ref' => 'CustomerIntelligenceService'],
                    ['label' => 'Hechos de pago (Wompi, solo lectura) y de membresía', 'kind' => 'code', 'ref' => 'PaymentStatusProvider'],
                    ['label' => 'Compromisos (citas)', 'kind' => 'table', 'ref' => 'marketing_appointments'],
                ],
                'configurableToday' => [],
                'fixedInCode' => ['Qué se recuerda y cómo se infiere', 'Contiene datos personales: nunca sale a un modelo sin redactar'],
            ],
            [
                'id' => 'strategy',
                'order' => 6,
                'name' => 'Estrategia comercial',
                'responsibility' => 'El modelo elige dentro de menús que acota Laravel: fase (18), transiciones legales, plan obligatorio, herramientas permitidas y pistas.',
                'icon' => 'strategy',
                'accent' => 'blue',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Strategist (LLM) con enums cerrados', 'kind' => 'n8n', 'ref' => 'contrato: StrategyContract'],
                    ['label' => 'Máquina de fases comerciales (18 fases)', 'kind' => 'code', 'ref' => 'CommercialPhaseMachine'],
                    ['label' => 'Política del turno: el plan obligatorio (insignia = is_recommended)', 'kind' => 'code', 'ref' => 'CommercialTurnPolicy'],
                    ['label' => 'Pistas firmadas en el token de decisión', 'kind' => 'code', 'ref' => 'StrategyContract · UltronDecideService'],
                    ['label' => 'Herramientas permitidas (cobro solo con autoridad)', 'kind' => 'code', 'ref' => 'UltronDecideService'],
                ],
                'configurableToday' => ['Plan insignia y orden (Plan.is_recommended, sort_order): cambian qué se cotiza', 'El prompt del Strategist (n8n)'],
                'fixedInCode' => ['Las fases y sus transiciones', 'Qué herramientas existen y qué autoridad exige cada una'],
            ],
            [
                'id' => 'rules',
                'order' => 7,
                'name' => 'Reglas del negocio',
                'responsibility' => 'El commit: 28 controles deterministas en orden fijo. Lo que falla antes de persistir no deja huella; después, solo reescribe o sustituye.',
                'icon' => 'gavel',
                'accent' => 'magenta',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Commit: idempotencia, interruptor/freno/canario, token, transiciones', 'kind' => 'code', 'ref' => 'UltronCommitService'],
                    ['label' => 'Inspección del contenido saliente (tarjeta, URL, pago, membresía, precio, descuento)', 'kind' => 'code', 'ref' => 'OutboundContentGuard'],
                    ['label' => 'Hechos de pago y de membresía', 'kind' => 'code', 'ref' => 'PaymentFactGuard · MembershipFactGuard'],
                    ['label' => 'Promesas, derivación humana, consentimiento, cortesías, canario de pago', 'kind' => 'code', 'ref' => 'PromiseAuthority · HumanHandoffAuthority · ConsentAuthority · CourtesyAuthority · PaymentCanaryAuthority'],
                    ['label' => 'Guardarraíles del agente y de pagos', 'kind' => 'code', 'ref' => 'SalesAgentGuardrailService · SalesPaymentGuardrailService'],
                    ['label' => 'Patrones de precio y descuento', 'kind' => 'code', 'ref' => 'SalesAgentDecisionSchema'],
                ],
                'configurableToday' => ['Banderas de entorno: enlaces de pago, canario del cobro', 'Horario estructurado y brand_copy en la base de conocimiento (actúan como regla)'],
                'fixedInCode' => ['Todas las reglas críticas: son controles del servidor, no instrucciones al modelo'],
            ],
            [
                'id' => 'composer',
                'order' => 8,
                'name' => 'Construcción de respuesta',
                'responsibility' => 'n8n redacta (Composer) y juzga (Critic, con un reintento); Laravel resuelve marcadores, realinea plan y precio y aplica respaldos.',
                'icon' => 'auto_awesome',
                'accent' => 'cyan',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Composer y Critic Comercial (LLM), con Composer Reintento y Critic 2', 'kind' => 'n8n', 'ref' => 'workflow de n8n (5 nodos LLM)'],
                    ['label' => 'Contrato del Critic: 22 dimensiones y 16 fallos duros', 'kind' => 'code', 'ref' => 'CriticContract'],
                    ['label' => 'Marcadores PLAN_PRICE, PLAN_NAME, PLAN_DURATION y APP_LINKS', 'kind' => 'code', 'ref' => 'UltronDraftPlaceholders'],
                    ['label' => 'Texto final: realineado, checkout, reescrituras de verdad, respaldos', 'kind' => 'code', 'ref' => 'UltronCommitService'],
                    ['label' => 'Cerebro legado (OpenAI, Hermes inactivo, reglas)', 'kind' => 'code', 'ref' => 'OpenAiSalesResponder · HermesSalesResponder · FakeAiSalesResponder'],
                ],
                'configurableToday' => ['Modelo y parámetros del cerebro legado (entorno)', 'Los prompts de Composer y Critic (n8n)'],
                'fixedInCode' => ['Los marcadores', 'Los textos de respaldo', 'El esquema de la decisión'],
            ],
            [
                'id' => 'integrations',
                'order' => 9,
                'name' => 'Integraciones',
                'responsibility' => 'Meta (webhook y envío), las puertas de entrada de ULTRON, n8n (decide/commit), Wompi (solo lectura y webhook) y el CRM.',
                'icon' => 'hub',
                'accent' => 'violet',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Webhook de Meta: verificación y deduplicación', 'kind' => 'code', 'ref' => 'WebhookMetaController'],
                    ['label' => 'Registro del mensaje y enrutado', 'kind' => 'code', 'ref' => 'ProcessMetaWebhookEvent · MarketingInboundMessageRouter'],
                    ['label' => 'Puerta de entrada de ULTRON: comprueba si el mensaje es atendible', 'kind' => 'code', 'ref' => 'UltronEventEmitter'],
                    ['label' => 'Envío del turno a n8n (firmado e idempotente)', 'kind' => 'code', 'ref' => 'SendUltronEventToN8n'],
                    ['label' => 'Puente con n8n: contexto del turno, commit y partes de avería', 'kind' => 'code', 'ref' => 'UltronController'],
                    ['label' => 'Despachador y bandeja de salida de WhatsApp', 'kind' => 'code', 'ref' => 'MarketingMessageDispatcher · WhatsappOutboxService'],
                    ['label' => 'Wompi: enlace de pago (apagado) y resultado por webhook', 'kind' => 'code', 'ref' => 'WompiPaymentLinkService · MarketingPaymentOutcomeService'],
                ],
                'configurableToday' => ['Credenciales y números (entorno)', 'El workflow de n8n (en el servidor de n8n)'],
                'fixedInCode' => ['El orden de las puertas de entrada', 'Qué acciones existen y qué autoridad exige cada una'],
            ],
            [
                'id' => 'governance',
                'order' => 10,
                'name' => 'Control y evolución',
                'responsibility' => 'Interruptor, pausa, freno, canario, vigía, acta y copias de prompts. No hay versiones publicables ni versión por turno: eso es ULTRON STUDIO.',
                'icon' => 'verified_user',
                'accent' => 'magenta',
                'critical' => true,
                'realComponents' => [
                    ['label' => 'Interruptor general y canario por conversación', 'kind' => 'env', 'ref' => 'configuración de Mercadeo'],
                    ['label' => 'Pausa desde el CRM (permiso marketing.agent.manage, auditada)', 'kind' => 'code', 'ref' => 'MarketingAgentSwitch'],
                    ['label' => 'Freno de emergencia (se suelta solo a mano)', 'kind' => 'command', 'ref' => 'ultron:abort'],
                    ['label' => 'Vigía de turnos mudos: abre incidentes y puede frenar el canario', 'kind' => 'command', 'ref' => 'UltronTurnWatchdog'],
                    ['label' => 'Acta del canario (contadores que deben dar cero)', 'kind' => 'command', 'ref' => 'ultron:canary-report'],
                    ['label' => 'Copias de prompts por fecha y tabla de compatibilidad n8n ↔ backend', 'kind' => 'config', 'ref' => 'documentación de Mercadeo'],
                ],
                'configurableToday' => ['Pausa (CRM)', 'Interruptor, canario y freno (configuración y consola)'],
                'fixedInCode' => ['No se registra por turno la versión del workflow, del prompt ni del modelo'],
            ],
        ];
    }

    /** @return list<array<string,string>> */
    private static function ports(): array
    {
        return [
            ['id' => 'knowledge:out:facts', 'layer' => 'knowledge', 'direction' => 'out', 'dataType' => 'facts', 'label' => 'Hechos autorizados'],
            ['id' => 'persona:out:voice', 'layer' => 'persona', 'direction' => 'out', 'dataType' => 'voice', 'label' => 'Voz'],
            ['id' => 'intent:in:context', 'layer' => 'intent', 'direction' => 'in', 'dataType' => 'context', 'label' => 'Conversación'],
            ['id' => 'intent:out:intent', 'layer' => 'intent', 'direction' => 'out', 'dataType' => 'intent', 'label' => 'Decisión base'],
            ['id' => 'context:in:events', 'layer' => 'context', 'direction' => 'in', 'dataType' => 'events', 'label' => 'Mensaje entrante'],
            ['id' => 'context:out:context', 'layer' => 'context', 'direction' => 'out', 'dataType' => 'context', 'label' => 'Conversación'],
            ['id' => 'memory:in:reply', 'layer' => 'memory', 'direction' => 'in', 'dataType' => 'reply', 'label' => 'Lo enviado'],
            ['id' => 'memory:in:events', 'layer' => 'memory', 'direction' => 'in', 'dataType' => 'events', 'label' => 'Pagos'],
            ['id' => 'memory:out:memory', 'layer' => 'memory', 'direction' => 'out', 'dataType' => 'memory', 'label' => 'Perfil y hechos'],
            ['id' => 'strategy:in:intent', 'layer' => 'strategy', 'direction' => 'in', 'dataType' => 'intent', 'label' => 'Decisión base'],
            ['id' => 'strategy:in:memory', 'layer' => 'strategy', 'direction' => 'in', 'dataType' => 'memory', 'label' => 'Perfil y hechos'],
            ['id' => 'strategy:in:facts', 'layer' => 'strategy', 'direction' => 'in', 'dataType' => 'facts', 'label' => 'Planes y catálogo'],
            ['id' => 'strategy:out:strategy', 'layer' => 'strategy', 'direction' => 'out', 'dataType' => 'strategy', 'label' => 'Estrategia'],
            ['id' => 'rules:in:decision', 'layer' => 'rules', 'direction' => 'in', 'dataType' => 'decision', 'label' => 'Propuesta'],
            ['id' => 'rules:in:version', 'layer' => 'rules', 'direction' => 'in', 'dataType' => 'version', 'label' => 'Interruptores'],
            ['id' => 'rules:in:memory', 'layer' => 'rules', 'direction' => 'in', 'dataType' => 'memory', 'label' => 'Pagos y membresía'],
            ['id' => 'rules:in:facts', 'layer' => 'rules', 'direction' => 'in', 'dataType' => 'facts', 'label' => 'Planes y precio'],
            ['id' => 'rules:out:reply', 'layer' => 'rules', 'direction' => 'out', 'dataType' => 'reply', 'label' => 'Respuesta validada'],
            ['id' => 'rules:out:payment', 'layer' => 'rules', 'direction' => 'out', 'dataType' => 'payment', 'label' => 'Enlace de pago'],
            ['id' => 'composer:in:facts', 'layer' => 'composer', 'direction' => 'in', 'dataType' => 'facts', 'label' => 'Marcadores'],
            ['id' => 'composer:in:voice', 'layer' => 'composer', 'direction' => 'in', 'dataType' => 'voice', 'label' => 'Voz'],
            ['id' => 'composer:in:strategy', 'layer' => 'composer', 'direction' => 'in', 'dataType' => 'strategy', 'label' => 'Estrategia'],
            ['id' => 'composer:in:context', 'layer' => 'composer', 'direction' => 'in', 'dataType' => 'context', 'label' => 'Conversación'],
            ['id' => 'composer:out:decision', 'layer' => 'composer', 'direction' => 'out', 'dataType' => 'decision', 'label' => 'Borrador y veredicto'],
            ['id' => 'integrations:in:reply', 'layer' => 'integrations', 'direction' => 'in', 'dataType' => 'reply', 'label' => 'Respuesta validada'],
            ['id' => 'integrations:in:payment', 'layer' => 'integrations', 'direction' => 'in', 'dataType' => 'payment', 'label' => 'Enlace de pago'],
            ['id' => 'integrations:in:version', 'layer' => 'integrations', 'direction' => 'in', 'dataType' => 'version', 'label' => 'Interruptores'],
            ['id' => 'integrations:out:events', 'layer' => 'integrations', 'direction' => 'out', 'dataType' => 'events', 'label' => 'Eventos del canal'],
            ['id' => 'governance:in:events', 'layer' => 'governance', 'direction' => 'in', 'dataType' => 'events', 'label' => 'Turnos e incidentes'],
            ['id' => 'governance:out:version', 'layer' => 'governance', 'direction' => 'out', 'dataType' => 'version', 'label' => 'Interruptores'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function edges(): array
    {
        return [
            ['id' => 'E01', 'source' => 'integrations:out:events', 'target' => 'context:in:events', 'label' => 'mensaje entrante → contexto', 'mandatory' => true, 'opensTurn' => true, 'consequence' => ''],
            ['id' => 'E02', 'source' => 'context:out:context', 'target' => 'intent:in:context', 'label' => 'mensajes recientes → cerebro de intención', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E03', 'source' => 'context:out:context', 'target' => 'composer:in:context', 'label' => 'conversación y memoria del turno → redactor', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E04', 'source' => 'intent:out:intent', 'target' => 'strategy:in:intent', 'label' => 'decisión base → Strategist', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E05', 'source' => 'memory:out:memory', 'target' => 'strategy:in:memory', 'label' => 'perfil, pagos y membresía → Strategist', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E06', 'source' => 'knowledge:out:facts', 'target' => 'strategy:in:facts', 'label' => 'planes y base de conocimiento → Strategist (sin precios)', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E07', 'source' => 'knowledge:out:facts', 'target' => 'composer:in:facts', 'label' => 'marcadores de plan y precio → redactor', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E08', 'source' => 'persona:out:voice', 'target' => 'composer:in:voice', 'label' => 'prompts de voz y brand_copy → redactor', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E09', 'source' => 'strategy:out:strategy', 'target' => 'composer:in:strategy', 'label' => 'estrategia validada → redactor', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E10', 'source' => 'composer:out:decision', 'target' => 'rules:in:decision', 'label' => 'borrador, veredicto y token → commit', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E11', 'source' => 'rules:out:reply', 'target' => 'integrations:in:reply', 'label' => 'solo lo validado se envía', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E12', 'source' => 'governance:out:version', 'target' => 'rules:in:version', 'label' => 'interruptor, freno, canario y token → commit', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E13', 'source' => 'governance:out:version', 'target' => 'integrations:in:version', 'label' => 'interruptor, pausa y canario → puertas de entrada y despacho', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E14', 'source' => 'rules:out:payment', 'target' => 'integrations:in:payment', 'label' => 'enlace de pago como mensaje aparte', 'mandatory' => false, 'consequence' => 'No se envían enlaces de pago: el cobro lo gestiona una persona. Es el estado actual de producción (bandera apagada).'],
            ['id' => 'E15', 'source' => 'rules:out:reply', 'target' => 'memory:in:reply', 'label' => 'lo enviado → memoria y ficha del prospecto', 'mandatory' => true, 'feedback' => true, 'consequence' => ''],
            ['id' => 'E16', 'source' => 'integrations:out:events', 'target' => 'memory:in:events', 'label' => 'resultado del pago (Wompi) → memoria y fase', 'mandatory' => false, 'feedback' => true, 'consequence' => 'La memoria no sabría que el prospecto pagó ni cambiaría la fase. Hoy está apagado junto con los enlaces de pago.'],
            ['id' => 'E17', 'source' => 'integrations:out:events', 'target' => 'governance:in:events', 'label' => 'turnos mudos e incidentes → vigía e IRON GUARD', 'mandatory' => true, 'feedback' => true, 'consequence' => ''],
            // El commit lee directamente de la memoria y del conocimiento (auditoría §5, «En el commit»).
            ['id' => 'E18', 'source' => 'memory:out:memory', 'target' => 'rules:in:memory', 'label' => 'pagos, membresía y perfil → commit (hechos y reventa)', 'mandatory' => true, 'consequence' => ''],
            ['id' => 'E19', 'source' => 'knowledge:out:facts', 'target' => 'rules:in:facts', 'label' => 'planes, precio, horario y brand_copy → commit', 'mandatory' => true, 'consequence' => ''],
        ];
    }

    /** @return list<array<string,string>> */
    private static function requiredPaths(): array
    {
        return [
            ['id' => 'entrada-a-envio', 'from' => 'context', 'to' => 'integrations', 'title' => 'Todo mensaje llega a una respuesta validada', 'detail' => 'Contexto → intención → estrategia → redacción → commit → canal.'],
            ['id' => 'hechos-a-envio', 'from' => 'knowledge', 'to' => 'integrations', 'title' => 'Los hechos autorizados llegan a lo que se envía', 'detail' => 'Sin este camino el agente respondería sin conocimiento autorizado.'],
            ['id' => 'control-a-envio', 'from' => 'governance', 'to' => 'integrations', 'title' => 'Los interruptores gobiernan lo que se envía', 'detail' => 'Interruptor, freno, pausa y canario tienen que llegar al commit y al canal.'],
        ];
    }

    /**
     * Lo que el motor real no admite (auditoría §12) y la coherencia entre
     * banderas y conexiones. `rule` (G) nombra la regla del código a la que
     * equivale la restricción; el validador no la lee.
     *
     * @return list<array<string,mixed>>
     */
    private static function constraints(): array
    {
        return [
            ['id' => 'hechos', 'layer' => 'knowledge', 'key' => 'strictFacts', 'forbidden' => false, 'level' => 'blocking', 'title' => 'Hechos sin verificar', 'detail' => 'Contradice R-01: el precio y los hechos solo salen del catálogo. El commit no lo admite.', 'rule' => 'R-01'],
            ['id' => 'descuentos', 'layer' => 'strategy', 'key' => 'discountsAllowed', 'forbidden' => true, 'level' => 'blocking', 'title' => 'Descuentos permitidos', 'detail' => 'Contradice R-02: el commit rechaza cualquier descuento («gratis», «2x1» y «precio especial» incluidos).', 'rule' => 'R-02'],
            ['id' => 'critic', 'layer' => 'composer', 'key' => 'critic', 'forbidden' => false, 'level' => 'blocking', 'title' => 'Sin veredicto del Critic', 'detail' => 'Sin veredicto el commit entra en rendición: no es una versión publicable.'],
            ['id' => 'derivacion', 'layer' => 'rules', 'key' => 'handoffOnlyCorroborated', 'forbidden' => false, 'level' => 'blocking', 'title' => 'Derivación sin corroborar', 'detail' => 'La autoridad de derivación vive en el servidor: cambiarla exige aprobación, no un borrador.', 'rule' => 'R-07'],
            ['id' => 'hermes', 'layer' => 'intent', 'key' => 'driver', 'forbidden' => 'hermes', 'level' => 'blocking', 'title' => 'Hermes está inactivo', 'detail' => 'No hay cerebro Hermes operativo: con él no se clasificaría ningún mensaje.'],
            ['id' => 'canario', 'layer' => 'governance', 'key' => 'canaryOnly', 'forbidden' => false, 'level' => 'blocking', 'title' => 'Tráfico general', 'detail' => 'Abrir ULTRON a todas las conversaciones no está autorizado hoy: solo la canaria.', 'rule' => 'R-12'],
            ['id' => 'apagado', 'layer' => 'governance', 'key' => 'enabled', 'forbidden' => false, 'level' => 'warning', 'title' => 'ULTRON apagado', 'detail' => 'Con el interruptor apagado ningún mensaje entra al agente: la versión sería inerte.'],
            ['id' => 'cobro-sin-camino', 'layer' => 'rules', 'key' => 'paymentLinksEnabled', 'when' => true, 'requires' => ['E14', 'E16'], 'level' => 'blocking', 'title' => 'Enlaces de pago sin su camino', 'detail' => 'Con la bandera encendida hacen falta el envío del enlace y el resultado de Wompi hacia la memoria.'],
            ['id' => 'cobro-aprobacion', 'layer' => 'rules', 'key' => 'paymentLinksEnabled', 'forbidden' => true, 'level' => 'warning', 'title' => 'Cobro automático: exige aprobación', 'detail' => 'Encender los enlaces de pago abre todos los caminos de cobro. Requiere aprobación expresa del propietario antes de publicar.'],
            ['id' => 'cobro-sin-bandera', 'layer' => 'rules', 'key' => 'paymentLinksEnabled', 'when' => false, 'forbids' => ['E14', 'E16'], 'level' => 'warning', 'title' => 'Conexiones de cobro sin efecto', 'detail' => 'Con la bandera apagada esas conexiones no se usan.'],
        ];
    }

    /** @return array<string,array{x:int,y:int}> */
    private static function positions(): array
    {
        return [
            'knowledge' => ['x' => 672, 'y' => 0],
            'persona' => ['x' => 1344, 'y' => 0],
            'governance' => ['x' => 1680, 'y' => 0],
            'context' => ['x' => 336, 'y' => 352],
            'intent' => ['x' => 672, 'y' => 352],
            'strategy' => ['x' => 1008, 'y' => 352],
            'composer' => ['x' => 1344, 'y' => 352],
            'rules' => ['x' => 1680, 'y' => 352],
            'memory' => ['x' => 672, 'y' => 704],
            'integrations' => ['x' => 1344, 'y' => 704],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function baselineConfigs(): array
    {
        return [
            'knowledge' => ['sources' => ['Base de conocimiento aprobada', 'Planes vendibles', 'Clases y horarios'], 'strictFacts' => true],
            'persona' => ['tone' => 'cercano', 'emojis' => true, 'maxChars' => 320],
            'intent' => ['driver' => 'entorno', 'fallback' => 'preguntar'],
            'context' => ['windowTurns' => 10],
            'memory' => ['useLeadProfile' => true],
            'strategy' => ['goal' => 'cita', 'offerPriority' => ['Plan insignia (is_recommended)'], 'discountsAllowed' => false],
            'rules' => ['handoffOnlyCorroborated' => true, 'paymentLinksEnabled' => false],
            'composer' => ['critic' => true, 'retry' => true],
            'integrations' => ['channel' => 'whatsapp', 'appointments' => true],
            'governance' => ['enabled' => true, 'canaryOnly' => true, 'autoBrake' => true],
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function layerParameters(): array
    {
        return [
            'knowledge' => [
                ['key' => 'sources', 'label' => 'Fuentes autorizadas', 'hint' => 'Separadas por comas. Solo de estas fuentes afirma datos.', 'type' => 'tags'],
                ['key' => 'strictFacts', 'label' => 'Solo hechos verificados', 'hint' => 'Si no sabe, pregunta o deriva; nunca completa con suposiciones.', 'type' => 'toggle', 'important' => true, 'consequence' => 'Desactivarlo contradice la regla R-01 (el precio y los hechos solo salen del catálogo). La validación lo bloquea.'],
            ],
            'persona' => [
                [
                    'key' => 'tone',
                    'label' => 'Tono',
                    'hint' => 'Registro general de las respuestas.',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'cercano', 'label' => 'Cercano'],
                        ['value' => 'profesional', 'label' => 'Profesional'],
                        ['value' => 'entusiasta', 'label' => 'Entusiasta'],
                    ],
                ],
                ['key' => 'emojis', 'label' => 'Usa emojis', 'hint' => 'Como mucho dos por mensaje.', 'type' => 'toggle'],
                ['key' => 'maxChars', 'label' => 'Longitud máxima', 'hint' => 'Caracteres por mensaje.', 'type' => 'range', 'min' => 160, 'max' => 480, 'step' => 20],
            ],
            'intent' => [
                [
                    'key' => 'driver',
                    'label' => 'Cerebro de intención',
                    // Hito G: el inspector enseña el valor efectivo (GET engine/readings); ya no puede decir «no verificado».
                    'hint' => 'En producción lo elige el entorno (hermes → openai → reglas); el inspector enseña el valor efectivo que lee el servidor.',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'entorno', 'label' => 'Según el entorno'],
                        ['value' => 'openai', 'label' => 'OpenAI'],
                        ['value' => 'rules', 'label' => 'Solo reglas'],
                        ['value' => 'hermes', 'label' => 'Hermes (inactivo)'],
                    ],
                    'important' => true,
                    'consequence' => 'Cambia qué clasifica cada mensaje. En producción es una variable de entorno; aquí solo es borrador.',
                ],
                [
                    'key' => 'fallback',
                    'label' => 'Si no entiende',
                    'hint' => 'Qué hace con una intención ambigua.',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'preguntar', 'label' => 'Pregunta para aclarar'],
                        ['value' => 'humano', 'label' => 'Deriva a un asesor'],
                    ],
                ],
            ],
            'context' => [
                ['key' => 'windowTurns', 'label' => 'Mensajes en contexto', 'hint' => 'Hoy son 10, fijo en código.', 'type' => 'range', 'min' => 4, 'max' => 30, 'step' => 2],
            ],
            'memory' => [
                ['key' => 'useLeadProfile', 'label' => 'Usar la ficha del prospecto', 'hint' => 'Origen, temperatura, ciclo de vida y acuerdos previos.', 'type' => 'toggle'],
            ],
            'strategy' => [
                [
                    'key' => 'goal',
                    'label' => 'Objetivo comercial',
                    'hint' => 'Hacia dónde orienta cada conversación.',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'cita', 'label' => 'Agendar valoración'],
                        ['value' => 'venta', 'label' => 'Cerrar membresía'],
                        ['value' => 'informar', 'label' => 'Informar y cualificar'],
                    ],
                ],
                ['key' => 'offerPriority', 'label' => 'Ofertas priorizadas', 'hint' => 'En orden, separadas por comas. Hoy manda el plan insignia.', 'type' => 'tags'],
                ['key' => 'discountsAllowed', 'label' => 'Puede ofrecer descuentos', 'hint' => 'Hoy ningún descuento pasa el commit («gratis», «2x1», «precio especial» incluidos).', 'type' => 'toggle', 'important' => true, 'consequence' => 'Contradice la regla R-02: el commit rechaza cualquier descuento. La validación lo marca como bloqueo.'],
            ],
            'rules' => [
                ['key' => 'handoffOnlyCorroborated', 'label' => 'Derivar solo si está corroborado', 'hint' => 'Solo se pasa a una persona cuando la petición es clara.', 'type' => 'toggle', 'important' => true, 'consequence' => 'Es la autoridad de derivación del servidor (HumanHandoffAuthority): cambiarla exige aprobación.'],
                ['key' => 'paymentLinksEnabled', 'label' => 'Enlaces de pago', 'hint' => 'Bandera absoluta: hoy apagada en producción.', 'type' => 'toggle', 'important' => true, 'consequence' => 'Encenderla abre todos los caminos de cobro a la vez. Exige aprobación expresa del propietario.'],
            ],
            'composer' => [
                ['key' => 'critic', 'label' => 'Critic Comercial', 'hint' => 'El veredicto del crítico antes del commit.', 'type' => 'toggle', 'important' => true, 'consequence' => 'Sin veredicto, el commit entra en rendición (texto curado o silencio con revisión del equipo).'],
                ['key' => 'retry', 'label' => 'Reintento del Composer', 'hint' => 'Si el crítico tumba el borrador, un segundo intento.', 'type' => 'toggle'],
            ],
            'integrations' => [
                [
                    'key' => 'channel',
                    'label' => 'Canal',
                    'hint' => 'Por dónde conversa el agente.',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'whatsapp', 'label' => 'WhatsApp (Meta Cloud API)'],
                    ],
                ],
                ['key' => 'appointments', 'label' => 'Agenda citas de valoración', 'hint' => 'Usa la agenda de Mercadeo.', 'type' => 'toggle'],
            ],
            'governance' => [
                ['key' => 'enabled', 'label' => 'ULTRON encendido', 'hint' => 'Interruptor general (entorno).', 'type' => 'toggle', 'important' => true, 'consequence' => 'Desde el Studio solo debería poder apagarse. Encenderlo exige el procedimiento de activación aprobado.'],
                ['key' => 'canaryOnly', 'label' => 'Solo la conversación canaria', 'hint' => 'Hoy ULTRON atiende una conversación concreta, no un porcentaje.', 'type' => 'toggle', 'important' => true, 'consequence' => 'Quitar el canario abre ULTRON a todo el tráfico, que hoy no está autorizado.'],
                ['key' => 'autoBrake', 'label' => 'Freno automático del vigía', 'hint' => 'Si un turno del canario queda mudo, el vigía frena.', 'type' => 'toggle'],
            ],
        ];
    }

    /**
     * El mando real de cada parámetro (contrato §A2, tabla de los 21). En
     * llano y sin cartografiar el servidor: ni rutas, ni variables de entorno,
     * ni comandos (StudioCatalogTest lo vigila). 4 `env_flag`, 1 `engine_data`,
     * 11 `fixed` y 5 `proposal`.
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    private static function parameterBindings(): array
    {
        $codigo = 'Código';

        return [
            'knowledge' => [
                'sources' => self::binding('fixed', 'none', false, 'Fijas en código: el motor solo afirma lo que sale de la base de conocimiento aprobada, los planes vendibles, las clases y horarios y la app. Esta lista no cambia ninguna.', 'Código del motor'),
                'strictFacts' => self::binding('fixed', 'declared', true, 'Fijo en código: el motor solo afirma hechos verificados y el precio sale del catálogo (R-01). No se puede desactivar.', 'Código (no configurable)', ['declaredValue' => true]),
            ],
            'persona' => [
                'tone' => self::binding('proposal', 'none', false, 'Sin mando en el motor: el tono sale de los prompts de n8n y de los ítems de tono y brand_copy aprobados en la base de conocimiento.', 'Prompts de n8n y base de conocimiento'),
                'emojis' => self::binding('proposal', 'none', false, 'Sin interruptor en el motor: la guardia de estilo solo anota los mensajes con más de 2 emojis, sin impedirlos.', 'Prompts de n8n'),
                'maxChars' => self::binding('proposal', 'none', false, 'Sin límite en el motor: la guardia de estilo solo anota los mensajes de más de 900 caracteres, sin cortarlos.', 'Prompts de n8n'),
            ],
            'intent' => [
                // `entorno` en el borrador significa «lo que tenga el motor»: no se marca «≠ motor».
                'driver' => self::binding('env_flag', 'live', true, 'Qué cerebro clasifica cada mensaje. El motor lo elige por entorno (hermes → openai → reglas) y el inspector enseña el valor efectivo que lee el servidor.', 'Entorno (despliegue)', ['engineOption' => 'entorno']),
                'fallback' => self::binding('proposal', 'none', false, 'Sin mando en el CRM: qué hacer con una intención ambigua lo decide el prompt del Strategist en n8n.', 'Prompt del Strategist (n8n)'),
            ],
            'context' => [
                'windowTurns' => self::binding('fixed', 'declared', true, 'Fijo en código: el motor pasa siempre los últimos 10 mensajes, con las cifras y los enlaces redactados.', $codigo, ['declaredValue' => 10]),
            ],
            'memory' => [
                'useLeadProfile' => self::binding('fixed', 'declared', true, 'Fijo en código: el motor usa siempre la ficha del prospecto (origen, temperatura, ciclo de vida y acuerdos previos).', $codigo, ['declaredValue' => true]),
            ],
            'strategy' => [
                'goal' => self::binding('proposal', 'none', false, 'Sin mando en el CRM: hacia dónde orienta cada conversación lo decide el prompt del Strategist en n8n.', 'Prompt del Strategist (n8n)'),
                // Los nombres de los planes no se comparan con las etiquetas del borrador.
                'offerPriority' => self::binding('engine_data', 'live', false, 'Lo que se cotiza sale de los planes vendibles: primero el plan insignia y luego por su orden. El Studio enseña solo sus nombres y no los compara con esta lista.', 'Módulo de Planes del CRM'),
                'discountsAllowed' => self::binding('fixed', 'declared', true, 'Fijo en código: ningún descuento pasa el commit (R-02), «gratis», «2x1» y «precio especial» incluidos.', $codigo, ['declaredValue' => false]),
            ],
            'rules' => [
                'handoffOnlyCorroborated' => self::binding('fixed', 'declared', true, 'Fijo en código: el servidor solo deriva a una persona si la petición está corroborada (R-07).', $codigo, ['declaredValue' => true]),
                'paymentLinksEnabled' => self::binding('env_flag', 'live', true, 'Bandera absoluta de los enlaces de pago: encendida abre todos los caminos de cobro a la vez. Se lee del entorno del servidor.', 'Entorno, con aprobación expresa del propietario'),
            ],
            'composer' => [
                'critic' => self::binding('fixed', 'none', false, 'Vive en n8n: el veredicto del Critic forma parte del workflow y no se lee desde el CRM.', 'Workflow de n8n'),
                'retry' => self::binding('fixed', 'none', false, 'Vive en n8n: el reintento del Composer forma parte del workflow y no se lee desde el CRM.', 'Workflow de n8n'),
            ],
            'integrations' => [
                'channel' => self::binding('fixed', 'declared', true, 'Fijo en código: el agente conversa solo por WhatsApp (Meta Cloud API).', $codigo, ['declaredValue' => 'whatsapp']),
                'appointments' => self::binding('fixed', 'declared', true, 'Fijo en código: la cortesía siempre está permitida y se registra como una solicitud que confirma una persona, nunca como una cita confirmada.', $codigo, ['declaredValue' => true]),
            ],
            'governance' => [
                'enabled' => self::binding('env_flag', 'live', true, 'Interruptor general: apagado, ningún mensaje entra al agente. Se lee del entorno del servidor.', 'Entorno; encenderlo exige el procedimiento de activación'),
                // Si hay canario, nunca cuál es.
                'canaryOnly' => self::binding('env_flag', 'live', true, 'Con una conversación canaria configurada, solo esa entra en ULTRON. El Studio enseña si hay canario, nunca cuál es.', 'Entorno'),
                'autoBrake' => self::binding('fixed', 'declared', true, 'Fijo en código: el vigía frena si un turno queda mudo, y solo en la conversación canaria.', $codigo, ['declaredValue' => true]),
            ],
        ];
    }

    /**
     * Las lecturas del motor que no son parámetros (contrato §A2, «Lecturas
     * extra»), en el orden del contrato. Lo que enseñan lo sirve
     * `GET engine/readings`; aquí solo su descripción.
     *
     * @return list<array{id:string,layer:string,label:string,origin:string,effect:string,changeVia:string}>
     */
    private static function engineReadings(): array
    {
        return [
            ['id' => 'governance.paused', 'layer' => 'governance', 'label' => 'Pausa del agente', 'origin' => 'engine_setting', 'effect' => 'Con la pausa puesta ULTRON no atiende ninguna conversación, aunque el interruptor esté encendido.', 'changeVia' => 'Pausa desde Mercadeo (CRM)'],
            ['id' => 'governance.brake', 'layer' => 'governance', 'label' => 'Freno de emergencia', 'origin' => 'engine_setting', 'effect' => 'Con el freno echado el commit no envía nada. Lo echa una persona desde la consola o el vigía en la conversación canaria.', 'changeVia' => 'Freno de emergencia (consola); se suelta con firma'],
            ['id' => 'rules.paymentCanary', 'layer' => 'rules', 'label' => 'Canario del cobro', 'origin' => 'env_flag', 'effect' => 'Si hay una conversación canaria del cobro configurada. El Studio enseña si existe, nunca cuál es.', 'changeVia' => 'Entorno'],
            ['id' => 'knowledge.base', 'layer' => 'knowledge', 'label' => 'Base de conocimiento', 'origin' => 'engine_data', 'effect' => 'Lo que el motor puede afirmar: versión, ítems activos por categoría (las 14, también las que están a cero) y pendientes de revisión. El Studio no enseña su contenido.', 'changeVia' => 'Consola o API interna (propone), con aprobación'],
            ['id' => 'strategy.plans', 'layer' => 'strategy', 'label' => 'Planes vendibles', 'origin' => 'engine_data', 'effect' => 'Los planes que el motor puede cotizar, en su orden y con la insignia marcada. Solo los nombres: ni precios ni identificadores.', 'changeVia' => 'Módulo de Planes'],
        ];
    }

    /**
     * Un ParameterBinding con sus claves en el orden del contrato. `$extra`
     * lleva `declaredValue` (solo con `declared`) o `engineOption`.
     *
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private static function binding(string $origin, string $valueSource, bool $comparable, string $effect, string $changeVia, array $extra = []): array
    {
        return ['origin' => $origin, 'valueSource' => $valueSource] + $extra + ['comparable' => $comparable, 'effect' => $effect, 'changeVia' => $changeVia];
    }

    /**
     * La afirmación `require <capa>.<clave> eq <valor>` en su forma canónica.
     *
     * @return array<string,mixed>
     */
    private static function requireEq(string $layer, string $key, mixed $value): array
    {
        return ['kind' => 'require', 'target' => 'config', 'layer' => $layer, 'key' => $key, 'op' => 'eq', 'value' => $value];
    }

    /**
     * @param  list<array{layer:string,key:string}>  $configs
     * @param  list<string>  $knowledgeCategories
     * @param  list<string>  $edges
     * @return array{configs:list<array{layer:string,key:string}>,edges:list<string>,knowledgeKeys:list<string>,knowledgeCategories:list<string>}
     */
    private static function refs(array $configs = [], array $knowledgeCategories = [], array $edges = []): array
    {
        return ['configs' => $configs, 'edges' => $edges, 'knowledgeKeys' => [], 'knowledgeCategories' => $knowledgeCategories];
    }
}
