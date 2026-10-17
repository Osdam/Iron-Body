<?php

namespace App\Providers;

use App\Models\MarketingLeadAttribution;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MemberRiskLock;
use App\Models\PaymentTransaction;
use App\Models\ReceivablePayment;
use App\Models\PhysicalEvaluation;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Models\User;
use App\Observers\Commissions\CommissionAccessObserver;
use App\Observers\Marketing\AttributionOfferObserver;
use App\Observers\Marketing\ConversationPreviewObserver;
use App\Observers\Marketing\MarketingPaymentOutcomeObserver;
use App\Observers\MemberPhoneObserver;
use App\Observers\WorkforceProfileObserver;
use App\Services\Admin\AdminSessionService;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusConfigValidator;
use App\Services\Billing\Factus\FactusTokenManager;
use App\Services\Classes\ClassEventBus;
use App\Services\Exercises\ExerciseCatalogResolver;
use App\Services\Marketing\Analytics\AdvertisingSpendProvider;
use App\Services\Marketing\Analytics\UnavailableSpendProvider;
use App\Services\Marketing\Contracts\AiSalesResponderInterface;
use App\Services\Marketing\FakeAiSalesResponder;
use App\Services\Marketing\HermesCircuitBreaker;
use App\Services\Marketing\HermesSalesResponder;
use App\Services\Marketing\OpenAiSalesResponder;
use App\Services\Marketing\SalesAgentDecisionValidator;
use App\Services\Marketing\SalesAgentPromptBuilder;
use App\Services\Marketing\SalesAiConfig;
use App\Services\Meta\WhatsappIntegrationRegistry;
use App\Services\Observability\QueueHealthService;
use App\Services\Wompi\WompiConfigValidator;
use App\Support\Access\AdminActor;
use App\Support\Access\TrainerMemberScope;
use App\Support\Network\ClientNetwork;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Un bus de hechos de clases por proceso: `batch()` agrupa los avisos de
        // un plan semanal en uno, y para eso tiene que ser la MISMA instancia en
        // toda la petición.
        $this->app->singleton(ClassEventBus::class);

        // Hoy NO hay fuente de gasto publicitario. Se enlaza la implementacion
        // que lo dice en vez de una que devuelva cero: un ROAS calculado sobre
        // gasto cero es una mentira con formato de numero, y alguien tomaria
        // una decision de presupuesto con ella.
        $this->app->bind(
            AdvertisingSpendProvider::class,
            UnavailableSpendProvider::class,
        );

        // Resolver de catálogo de ejercicios: una sola carga de catálogo+aliases
        // por request (evita N+1 al serializar muchas rutinas).
        $this->app->singleton(ExerciseCatalogResolver::class);

        // De dónde salen las credenciales de WhatsApp Cloud API: la conexión
        // hecha desde el CRM si existe, y si no el .env de siempre. Singleton
        // porque la respuesta no cambia a mitad de petición y sin memoria
        // enviar diez mensajes serían diez consultas idénticas.
        $this->app->singleton(WhatsappIntegrationRegistry::class);

        // Facturación electrónica (Factus): el token manager y el cliente HTTP
        // se construyen desde config(billing) (constructores con array $cfg),
        // por eso se registran explícitamente para que el contenedor los inyecte.
        $this->app->bind(FactusTokenManager::class, fn () => FactusTokenManager::fromConfig());
        $this->app->bind(
            FactusClient::class,
            fn ($app) => new FactusClient($app->make(FactusTokenManager::class), (array) config('billing')),
        );

        // Cerebro comercial IA. Cadena de degradación hermes → openai → fake:
        // cada eslabón solo se usa si TODO lo suyo está listo, y si falta algo
        // cae al siguiente. Por defecto queda el responder DETERMINISTA (fake),
        // así que ningún fallo de proveedor rompe producción. El responder
        // efectivo se registra en metadata para auditoría.
        $this->app->bind(AiSalesResponderInterface::class, function ($app) {
            $openAi = fn () => new OpenAiSalesResponder(
                new FakeAiSalesResponder,
                $app->make(SalesAgentPromptBuilder::class),
                $app->make(SalesAgentDecisionValidator::class),
            );

            return match (SalesAiConfig::effectiveDriver()) {
                'hermes' => new HermesSalesResponder(
                    $openAi(),
                    $app->make(SalesAgentPromptBuilder::class),
                    $app->make(SalesAgentDecisionValidator::class),
                    $app->make(HermesCircuitBreaker::class),
                ),
                'openai' => $openAi(),
                default => new FakeAiSalesResponder,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Del pago al inicio: siempre armado, no depende de commercial.events_enabled.
        PaymentTransaction::observe(MarketingPaymentOutcomeObserver::class);

        // El acceso del entrenador depende de que su piso esté pagado, y el
        // abono no siempre pasa por el módulo de Comisiones: también se
        // registra desde Cuentas por cobrar y se deshace al anularlo. Se
        // escucha el movimiento de dinero, que es el hecho que cambia la
        // respuesta, y no la pantalla desde la que se hizo.
        ReceivablePayment::observe(CommissionAccessObserver::class);

        // Quien deja de trabajar aquí pierde su plantilla facial (retención de
        // datos biométricos) y su jornada abierta queda como incidencia, venga
        // la desactivación del camino que venga.
        Trainer::observe(WorkforceProfileObserver::class);
        StaffProfile::observe(WorkforceProfileObserver::class);

        // Cada cambio real del teléfono de una ficha de socio deja rastro, venga
        // del camino que venga: la cuarentena del vínculo con el personal lo
        // necesita para no verificar con un número recién puesto (M2).
        Member::observe(MemberPhoneObserver::class);

        $this->limitadoresInternos();
        $this->limitadorDeClases();
        $this->limitadorDelAgente();
        $this->limitadorDePersonal();
        $this->limitadoresDelTrasladoDePlanes();
        $this->limitadoresDelRegistroPublico();
        $this->limitadorDelServicioDeImpresion();

        $this->guardWompiConfig();
        $this->guardFactusConfig();
        $this->guardUltronConfig();

        // La previsualizacion de la lista se mantiene desde la TABLA de
        // mensajes, no desde cada servicio que envia. Los mensajes nacen por
        // seis caminos distintos y basta que uno se olvide para que la bandeja
        // muestre texto viejo; un observador no se lo puede saltar ninguno.
        MarketingMessage::observe(
            ConversationPreviewObserver::class,
        );

        // Cuando alguien mapea un anuncio a un plan, hay que volver a mirar si
        // lo que promete la pauta sigue existiendo. Meta no manda ese dato, asi
        // que ese mapeo llega SIEMPRE despues del contacto.
        MarketingLeadAttribution::observe(
            AttributionOfferObserver::class,
        );

        /*
         * Los workers viven horas. Sin esto, el primero que resolviera «no hay
         * conexión de WhatsApp» se quedaría con esa respuesta en memoria y
         * seguiría usando el `.env` para siempre, aunque alguien conectara la
         * cuenta desde el CRM cinco minutos después. El síntoma sería de los
         * malos: el canal funciona en la web y no en las colas, y sólo se
         * arregla reiniciando el worker sin que nadie sepa por qué.
         *
         * Una consulta por trabajo es un precio que no se nota; el trabajo ya
         * abrió la base de datos para todo lo demás.
         */
        Queue::before(function (): void {
            app(WhatsappIntegrationRegistry::class)->forget();
        });

        /*
         * El latido de los carriles de cola.
         *
         * Cada trabajo terminado deja constancia de que SU carril tiene a
         * alguien atendiéndolo. Es la única señal que no se puede falsear: un
         * worker puede estar arrancado y bloqueado, o vivo pero escuchando la
         * cola equivocada por un error de configuración, y en los dos casos
         * Supervisor diría que todo va bien. Lo que importa es si el trabajo
         * avanza, y esto lo registra.
         *
         * Es best-effort a propósito: si el cache no está disponible, se pierde
         * visibilidad, pero ni un solo trabajo falla por ello.
         */
        Queue::after(function (JobProcessed $event): void {
            try {
                app(QueueHealthService::class)
                    ->heartbeat($event->job->getQueue() ?: 'default');
            } catch (\Throwable) {
                // La vigilancia no puede ser el motivo de que algo se rompa.
            }
        });

        $this->scopeTrainersToTheirMembers();
    }

    /**
     * Un entrenador solo alcanza a los socios que tiene asignados.
     *
     * `members.view` dice si se entra al modulo de socios; no dice a QUIEN. Sin
     * esto, la cuenta de un entrenador leia la ficha, la nutricion, las
     * valoraciones y el riesgo de CUALQUIER socio con solo cambiar el numero de
     * la URL. Ver {@see TrainerMemberScope} para el porque del diseno.
     *
     * Se registra aqui, sobre los CINCO modelos que exponen datos de socio en
     * las 39 rutas del dominio `members`, y no ruta por ruta: una consulta
     * nueva nace acotada sin que nadie tenga que acordarse.
     *
     * Es INERTE salvo que quien opera sea una cuenta de entrenador. Super Admin
     * y Recepcion no cambian de comportamiento, y fuera de una peticion
     * administrativa —consola, colas, API del socio— no filtra nada.
     */
    private function scopeTrainersToTheirMembers(): void
    {
        // El socio, por su propia clave.
        TrainerMemberScope::guard(Member::class, 'id');

        // Lo que cuelga del socio y se consulta por su cuenta.
        TrainerMemberScope::guard(PhysicalEvaluation::class, 'member_id');
        TrainerMemberScope::guard(MemberContract::class, 'member_id');
        TrainerMemberScope::guard(MemberRiskLock::class, 'member_id');

        // La cuenta de la app del socio: la relacion va al reves.
        TrainerMemberScope::guardUsersOf(User::class);
    }

    /**
     * Impide arrancar con una configuración Wompi que mezcle ambientes
     * (sandbox/producción). No corre en consola de pruebas ni rompe el arranque
     * cuando Wompi aún no está configurado (placeholders vacíos en dev). Un
     * MISMATCH real (llave del ambiente equivocado) sí aborta: es lo correcto.
     */
    /**
     * Dos cerebros no pueden decidir sobre la misma persona.
     *
     * `auto_analyze` enciende el cerebro LOCAL sobre los mensajes entrantes;
     * `ultron.enabled` manda esos mismos mensajes a n8n para que decida allí.
     * Con los dos encendidos, quien escriba recibe dos respuestas, se escriben
     * dos decisiones y la memoria de la conversación queda a merced de cuál
     * termine antes. El cerrojo de conversación no salva de esto: protege de
     * dos ejecuciones simultáneas, no de dos agentes con criterios distintos.
     *
     * Se comprueba al arrancar y no al primer mensaje, porque el primer mensaje
     * ya sería el daño. En producción es fatal; fuera, advertencia, para no
     * bloquear a quien esté probando en local.
     */
    private function guardUltronConfig(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        if (! (bool) config('marketing.ultron.enabled', false)) {
            return;
        }

        if ((bool) config('marketing.inbound.auto_analyze', true)) {
            $mensaje = 'Configuración inválida: MARKETING_ULTRON_ENABLED y '
                .'MARKETING_INBOUND_AUTO_ANALYZE no pueden estar activos a la vez '
                .'(dos cerebros decidirían sobre la misma conversación).';

            if (app()->environment('production')) {
                throw new \RuntimeException($mensaje);
            }

            Log::warning($mensaje);
        }
    }

    private function guardWompiConfig(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        try {
            $validator = WompiConfigValidator::fromConfig();
            $hard = $validator->hardIssues();
            if ($hard !== []) {
                // En producción es fatal (no procesar pagos mal configurados);
                // en local solo se advierte para no bloquear el desarrollo.
                if (app()->environment('production')) {
                    throw new \RuntimeException(implode(' | ', $hard));
                }
                Log::warning('Wompi config con advertencias', ['issues' => $hard]);
            }
        } catch (\RuntimeException $e) {
            if (app()->environment('production')) {
                throw $e;
            }
            Log::warning('Wompi config inválida (no fatal fuera de producción)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Impide arrancar producción con facturación electrónica mal configurada
     * (credenciales/URL de sandbox, sin datos del emisor o sin rango DIAN). Con
     * FACTUS_ENABLED=false el módulo está inerte y no se valida nada. No corre
     * en pruebas. Fuera de producción solo advierte para no bloquear el dev.
     */
    private function guardFactusConfig(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        try {
            $hard = FactusConfigValidator::fromConfig()->hardIssues();
            if ($hard !== []) {
                if (app()->environment('production')) {
                    throw new \RuntimeException(implode(' | ', $hard));
                }
                Log::warning('Factus config con advertencias', ['issues' => $hard]);
            }
        } catch (\RuntimeException $e) {
            if (app()->environment('production')) {
                throw $e;
            }
            Log::warning('Factus config inválida (no fatal fuera de producción)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Escrituras de clases del socio: reservar, cancelar, plan semanal y
     * check-in. Cada una avisa a TODAS las apps conectadas, que vuelven a pedir
     * sus clases, así que un bucle de reservar y cancelar se multiplicaba por
     * cada socio conectado. Por socio y no por IP: en el gimnasio muchos socios
     * comparten la misma red. 30 por minuto sobra para un uso real (doble toque,
     * reintentos).
     *
     * La clave sale del token y no del socio resuelto: Laravel adelanta
     * `ThrottleRequests` a todo el grupo `api` por su prioridad de middleware,
     * así que corre ANTES que `auth.member` y aún no hay socio. Con el socio,
     * caía a la IP y un socio insistente bloqueaba a todo el gimnasio.
     */
    private function limitadorDeClases(): void
    {
        // Kiosko de personal: por DISPOSITIVO declarado (las rutas firmadas
        // validan después que lo sea de verdad). Por IP bloquearía a todo el
        // gimnasio, que sale por la misma.
        //
        // Clave: el id que el controlador VA A USAR. En `device/challenge` es el
        // del cuerpo (la cabecera la pondría quien quisiera); en las firmadas, la
        // cabecera, que la firma ata a la clave del dispositivo. Más un tope por
        // IP: rotar ids inventados no puede dar cupo infinito (revisión de
        // seguridad 2026-10-11, F4).
        RateLimiter::for('kiosk', function (Request $request) {
            $device = (string) ($request->input('device_id') ?: $request->header('X-Device-Id', ''));
            $tooMany = fn ($request, array $headers) => response()->json([
                'ok' => false,
                'code' => 'too_many_requests',
                'message' => 'Demasiados intentos seguidos. Espera un momento.',
            ], 429, $headers);

            return [
                Limit::perMinute(60)
                    ->by($device !== '' ? 'device:'.hash('sha256', $device) : 'ip:'.$request->ip())
                    ->response($tooMany),
                // Todas las tablets del gimnasio salen por la misma IP: holgado
                // para un cambio de turno, corto para un barrido.
                Limit::perMinute(300)->by('kiosk-ip:'.$request->ip())->response($tooMany),
            ];
        });

        // Vincular una tablet: poco frecuente. Cupo propio por IP para no
        // compartirlo con otras rutas `throttle:N,1` (F3).
        RateLimiter::for('kiosk-register', fn (Request $request) => Limit::perMinute(10)
            ->by('kiosk-register:'.$request->ip())
            ->response(fn ($request, array $headers) => response()->json([
                'ok' => false,
                'code' => 'too_many_requests',
                'message' => 'Demasiados intentos de vinculación. Espera un minuto.',
            ], 429, $headers)));

        // Identificar por documento: por dispositivo y más estricto que el
        // resto del kiosko (es lo que se usaría para tantear documentos).
        RateLimiter::for('kiosk-identify', function (Request $request) {
            $device = (string) $request->header('X-Device-Id', '');

            return Limit::perMinute(20)
                ->by($device !== '' ? 'identify:'.hash('sha256', $device) : 'identify-ip:'.$request->ip())
                ->response(fn ($request, array $headers) => response()->json([
                    'ok' => false,
                    'code' => 'too_many_requests',
                    'message' => 'Demasiadas búsquedas seguidas. Espera un momento.',
                ], 429, $headers));
        });

        RateLimiter::for('class-writes', function (Request $request) {
            $token = $request->bearerToken();

            // Texto propio: el de Laravel («Too Many Attempts.») llega en inglés y
            // la app lo enseña tal cual.
            return Limit::perMinute(30)
                ->by($token ? 'member:'.hash('sha256', $token) : 'ip:'.$request->ip())
                ->response(fn ($request, array $headers) => response()->json([
                    'message' => 'Hiciste muchos cambios seguidos en tus clases. Espera un minuto e inténtalo de nuevo.',
                    'code' => 'too_many_class_writes',
                ], 429, $headers));
        });
    }

    /**
     * REGISTRO PÚBLICO DE LA APP (grupo `member.registration.token`).
     *
     * Ese token va dentro de la APK: a efectos prácticos, cualquiera llama a
     * estas rutas, y no tenían ningún cupo (cuarenta altas seguidas, cuarenta
     * 201). No hay socio ni sesión antes de register, así que la clave es la
     * RED (IPv4, o el /64 de IPv6: ver ClientNetwork), en cubos propios que no
     * comparten nada con el login.
     *
     * El wifi del gimnasio sale por UNA IP. Un alta de la APK es un register
     * (al pulsar «Finalizar», tras rellenar el formulario y fotografiar la
     * cédula: minutos por persona) y dos pasos (identidad y rostro); un fallo
     * de red repite register. Diez altas por minuto desde el mismo wifi —una
     * cola de diez personas terminando a la vez, con reintentos— no se
     * alcanzan en el mostrador; treinta pasos por minuto cubren esas diez. El
     * DELETE público ya no borra nada (siempre 409): su cupo solo frena el
     * barrido de ids.
     */
    private function limitadoresDelRegistroPublico(): void
    {
        $demasiadas = fn (string $mensaje) => fn ($request, array $headers) => response()->json([
            'ok' => false,
            'code' => 'too_many_requests',
            'message' => $mensaje,
            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);
        $red = fn (Request $request): string => ClientNetwork::of($request->ip());

        RateLimiter::for('member-register', fn (Request $request) => Limit::perMinute(10)
            ->by('member-register:'.$red($request))
            ->response($demasiadas('Demasiados registros seguidos desde esta red. Espera un minuto e inténtalo de nuevo.')));

        RateLimiter::for('member-register-steps', fn (Request $request) => Limit::perMinute(30)
            ->by('member-register-steps:'.$red($request))
            ->response($demasiadas('Demasiados envíos seguidos desde esta red. Espera un minuto e inténtalo de nuevo.')));

        RateLimiter::for('member-register-discard', fn (Request $request) => Limit::perMinute(10)
            ->by('member-register-discard:'.$red($request))
            ->response($demasiadas('Demasiadas solicitudes seguidas. Espera un minuto.')));
    }

    /**
     * TRASLADO DE SOCIOS ENTRE PLANES: cupo por ADMINISTRADOR, no por IP.
     *
     * Con el limitador anónimo (`throttle:10,1`) el cubo era el de la IP, el
     * mismo que usan el login del CRM y la app de los socios en el Wi-Fi del
     * gimnasio, y corre antes de autenticar: unos cuantos traslados dejaban el
     * login en 429, y cualquiera desde la misma red gastaba el cupo de quien
     * trasladaba. La clave es la PERSONA (`admin:{id}`): la que ya dejó la
     * puerta en `auth_admin` o, si el limitador corre antes, la que resuelve
     * su sesión. Sin sesión válida se vuelve a la IP, en un cubo propio de
     * este limitador que no comparte nada con el login.
     *
     * Bloque autocontenido a propósito: otras ramas añaden limitadores en este
     * mismo fichero.
     */
    private function limitadoresDelTrasladoDePlanes(): void
    {
        $clave = function (Request $request): string {
            $admin = $request->attributes->get('auth_admin');
            if ($admin instanceof \App\Models\Admin) {
                return 'admin:'.$admin->id;
            }

            $token = $request->bearerToken();
            $sesion = $token ? app(\App\Services\Admin\AdminSessionService::class)->resolveByToken($token) : null;

            return $sesion !== null ? 'admin:'.$sesion->admin_id : 'ip:'.$request->ip();
        };
        $demasiadas = fn (string $mensaje) => fn ($request, array $headers) => response()->json([
            'ok' => false,
            'code' => 'too_many_requests',
            'message' => $mensaje,
            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);

        // Ejecutar y revertir: diez por minuto y persona sobra para cualquier
        // traslado de verdad y frena un bucle.
        RateLimiter::for('plan-transfer-write', fn (Request $request) => Limit::perMinute(10)
            ->by('plan-transfer-write:'.$clave($request))
            ->response($demasiadas('Demasiados traslados seguidos. Espera un minuto antes de volver a ejecutar.')));

        // Candidatos, simulaciones, historial y detalle.
        RateLimiter::for('plan-transfer-read', fn (Request $request) => Limit::perMinute(60)
            ->by('plan-transfer-read:'.$clave($request))
            ->response($demasiadas('Demasiadas consultas seguidas al traslado de planes. Espera un momento.')));
    }

    /**
     * EL INTERRUPTOR DEL AGENTE IA NO PUEDE QUEDAR DETRÁS DEL CUBO DE LA IP.
     *
     * Con el limitador anónimo (`throttle:10,1`) el PUT compartía cubo con todo
     * lo que sale de la misma IP —el CRM entero y la app de los socios en el
     * Wi-Fi del gimnasio—, y en pleno incidente pausar devolvía 429 a la
     * primera. La clave es la credencial del admin, por la misma razón que en
     * `class-writes`: el throttle corre antes que la autenticación.
     */
    private function limitadorDelAgente(): void
    {
        $credencial = function (Request $request): string {
            $token = $request->bearerToken();

            return $token ? 'admin:'.hash('sha256', $token) : 'ip:'.$request->ip();
        };

        RateLimiter::for('marketing-agent-write', fn (Request $request) => Limit::perMinute(10)
            ->by('agent-write:'.$credencial($request))
            ->response(fn ($request, array $headers) => response()->json([
                'ok' => false,
                'code' => 'too_many_agent_changes',
                'message' => 'Demasiados cambios seguidos del agente IA. Espera un momento.',
            ], 429, $headers)));

        RateLimiter::for('marketing-agent-read', fn (Request $request) => Limit::perMinute(120)
            ->by('agent-read:'.$credencial($request)));

        // La Agenda comercial, por la misma razón: se relee con cada aviso del
        // canal y con «Cargar más», y con la clave por IP esas lecturas gastaban
        // el cubo del login de toda la oficina.
        RateLimiter::for('marketing-agenda', fn (Request $request) => Limit::perMinute(120)
            ->by('agenda:'.$credencial($request))
            ->response(fn ($request, array $headers) => response()->json([
                'ok' => false,
                'code' => 'too_many_agenda_requests',
                'message' => 'Demasiadas consultas seguidas a la agenda. Espera un momento.',
            ], 429, $headers)));
    }

    /**
     * El servicio local de impresión (/api/receipt-agent/*): 60 por minuto por
     * red (IPv4, o el /64 de IPv6: ver ClientNetwork), en un cubo PROPIO.
     *
     * Con `throttle:60,1` compartiría contador con todas las rutas que usan esa
     * forma desde la misma IP (la firma por defecto es dominio|IP), y todo el
     * gimnasio sale por una. Un equipo de caja canjea y reporta un par de veces
     * por cobro: 60 es holgado para varios equipos y corto para tantear tickets
     * (que además son de 256 bits). La respuesta tiene la forma uniforme del
     * contrato, `{ok:false, code, message}`.
     */
    private function limitadorDelServicioDeImpresion(): void
    {
        RateLimiter::for('receipt-agent', fn (Request $request) => Limit::perMinute(60)
            ->by('receipt-agent:'.ClientNetwork::of($request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json([
                'ok' => false,
                'code' => 'rate_limited',
                'message' => 'Demasiadas peticiones del servicio de impresión. Espera un minuto.',
            ], 429, $headers + ['Cache-Control' => 'no-store'])));
    }

    /**
     * Ficha laboral: comprobar un documento y vincular una ficha de socio.
     *
     * Con nombre, no `throttle:N,1`: el anónimo comparte cubo por IP con el
     * login del CRM (`throttle:8,1`), y unas cuantas comprobaciones en la
     * oficina dejarían a todos sin poder entrar. La clave es el ADMINISTRADOR,
     * no la sesión: abrir otra pestaña o volver a entrar no da cupo nuevo
     * (revisión de seguridad 2026-10-15, B2). El throttle corre antes que la
     * autenticación, así que el admin se resuelve aquí desde el token; si no
     * se resuelve (token compartido, sesión caducada), la clave es el token o
     * la IP y la autenticación lo rechazará igual.
     *
     * Comprobar documentos es lo que serviría para tantear quién es socio;
     * pedir códigos le cuesta un SMS al gimnasio y le escribe a un socio, y
     * por eso el inicio del vínculo tiene además un tope diario. El techo de
     * cada socio y de cada número lo pone la política de OTP.
     */
    private function limitadorDePersonal(): void
    {
        $quien = function (Request $request): string {
            $admin = AdminActor::from($request);
            if ($admin !== null) {
                return 'admin:'.$admin->id;
            }
            $token = $request->bearerToken();
            $sesion = $token ? app(AdminSessionService::class)->resolveByToken($token) : null;
            if ($sesion !== null) {
                return 'admin:'.$sesion->admin_id;
            }

            return $token ? 'token:'.hash('sha256', $token) : 'ip:'.$request->ip();
        };
        // Con `retry_after` (segundos), como el 429 de la política de OTP: la
        // pantalla dice cuánto esperar sin leer cabeceras.
        $tooMany = fn (string $mensaje) => fn ($request, array $headers) => response()->json([
            'ok' => false,
            'code' => 'too_many_requests',
            'message' => $mensaje,
            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);

        RateLimiter::for('workforce-lookup', fn (Request $request) => Limit::perMinute(30)
            ->by('workforce-lookup:'.$quien($request))
            ->response($tooMany('Demasiadas comprobaciones seguidas. Espera un minuto.')));

        // Pedir y confirmar el código comparten este cupo por minuto.
        RateLimiter::for('workforce-member-link', fn (Request $request) => Limit::perMinute(10)
            ->by('workforce-member-link:'.$quien($request))
            ->response($tooMany('Demasiadas solicitudes seguidas. Espera un minuto.')));

        // Solo el inicio (el que manda SMS): 30 al día por administrador.
        RateLimiter::for('workforce-member-link-day', fn (Request $request) => Limit::perDay(30)
            ->by('workforce-member-link-day:'.$quien($request))
            ->response($tooMany('Llegaste al máximo de códigos de verificación por hoy.')));
    }

    /**
     * UN VECINO RUIDOSO NO PUEDE DEJAR MUDO A ULTRON.
     *
     * Las dos puertas internas —la de automatización y la del asesor— iban con
     * `throttle:120,1`, el limitador anónimo de Laravel. Ese construye su clave
     * con el DOMINIO y la IP, no con la ruta, así que las dos compartían un
     * único cubo. Todo lo que entra por n8n sale de la misma IP.
     *
     * Lo que pasó, medido: el detector diario de cumplimiento emitió 3.758
     * eventos en 28 segundos, n8n los relevó a `notify-member` a ~20 por
     * segundo y vació el cubo. Tres minutos después llegaron tres mensajes de
     * WhatsApp, y sus llamadas a `/ai/decide` se encontraron con un 429. El
     * agente no falló: no llegó a enterarse de que alguien había escrito.
     *
     * La clave va por SUPERFICIE, no por IP. Las dos puertas ya están
     * autenticadas con el secreto compartido, así que lo que hace falta aquí
     * no es saber quién llama —eso ya se sabe— sino que el gasto de una no se
     * cobre en el presupuesto de la otra.
     *
     * Los topes siguen siendo topes: esto NO es abrir la mano. La avalancha de
     * notificaciones se arregla donde nace, espaciándola; aquí sólo se impide
     * que se lleve por delante a quien no tiene nada que ver.
     */
    private function limitadoresInternos(): void
    {
        RateLimiter::for('internal-automation', fn () => Limit::perMinute(
            (int) config('automation.rate_limits.automation', 600),
        )->by('internal-automation'));

        RateLimiter::for('internal-marketing-ai', fn () => Limit::perMinute(
            (int) config('automation.rate_limits.marketing_ai', 120),
        )->by('internal-marketing-ai'));
    }
}
