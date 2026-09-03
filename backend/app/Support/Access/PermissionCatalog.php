<?php

namespace App\Support\Access;

/**
 * Catálogo de permisos del CRM: qué existe, cómo se llama en castellano y a
 * qué dominio pertenece.
 *
 * ÚNICA fuente de verdad del vocabulario. El CRM ya no traduce claves por su
 * cuenta —lo hacía porque el servidor no las nombraba— y no puede inventarse
 * un permiso que aquí no esté: la matriz se pinta con lo que devuelve este
 * catálogo.
 *
 * Regla de admisión: una llave entra solo si {@see AuthorizationMap} la exige
 * en alguna ruta real. Un permiso que no protege ningún endpoint es un
 * interruptor desconectado, y de esos había 44 en la pantalla anterior.
 */
final class PermissionCatalog
{
    /**
     * Dominios en el orden en que se muestran. El orden no es alfabético a
     * propósito: primero lo que se usa a diario en el mostrador, después la
     * gestión, y al final lo que solo toca la dirección.
     *
     * @var array<string, array{label: string, icon: string, hint: string}>
     */
    private const DOMAINS = [
        'members' => ['label' => 'Miembros', 'icon' => 'group', 'hint' => 'Fichas de socios, membresías y contratos'],
        'payments' => ['label' => 'Pagos', 'icon' => 'payments', 'hint' => 'Cobros de membresías y suscripciones'],
        'cash.products' => ['label' => 'Caja de productos', 'icon' => 'storefront', 'hint' => 'Mostrador y venta de productos'],
        'cash.gym' => ['label' => 'Caja del gimnasio', 'icon' => 'fitness_center', 'hint' => 'Turno de caja de membresías'],
        'plans' => ['label' => 'Planes', 'icon' => 'card_membership', 'hint' => 'Catálogo de planes y sus funciones'],
        'inventory' => ['label' => 'Inventario', 'icon' => 'inventory_2', 'hint' => 'Productos y existencias'],
        'classes' => ['label' => 'Clases y asistencia', 'icon' => 'calendar_month', 'hint' => 'Horarios, reservas, ingreso y torniquete'],
        'routines' => ['label' => 'Rutinas y ejercicios', 'icon' => 'exercise', 'hint' => 'Rutinas asignadas y catálogo de ejercicios'],
        'trainers' => ['label' => 'Entrenadores', 'icon' => 'sports', 'hint' => 'Fichas, asignaciones y tareas de entrenadores'],
        'support' => ['label' => 'Soporte', 'icon' => 'support_agent', 'hint' => 'Reportes de acceso y seguridad de socios'],
        'equipment' => ['label' => 'Equipos', 'icon' => 'fitness_center', 'hint' => 'Maquinaria del gimnasio'],
        'billing' => ['label' => 'Facturación electrónica', 'icon' => 'receipt_long', 'hint' => 'Comprobantes DIAN y tarifas fiscales'],
        'marketing' => ['label' => 'Mercadeo', 'icon' => 'campaign', 'hint' => 'Bandeja, campañas, citas y agente comercial'],
        'content' => ['label' => 'Contenido de la app', 'icon' => 'smartphone', 'hint' => 'Eventos, anuncios, rachas y notificaciones push'],
        'moderation' => ['label' => 'Moderación', 'icon' => 'gavel', 'hint' => 'Historias, lives y sanciones de comunidad'],
        'nutrition' => ['label' => 'Nutrición', 'icon' => 'nutrition', 'hint' => 'Catálogo de alimentos'],
        'reports' => ['label' => 'Informes', 'icon' => 'analytics', 'hint' => 'Panel de inicio y estadísticas'],
        'earnings' => ['label' => 'Ganancias', 'icon' => 'trending_up', 'hint' => 'Ingresos del negocio'],
        'ai' => ['label' => 'Asistente IA', 'icon' => 'smart_toy', 'hint' => 'Asistente del CRM'],
        'security' => ['label' => 'Seguridad', 'icon' => 'security', 'hint' => 'Incidentes y estado de la plataforma'],
        'integrations' => ['label' => 'Integraciones', 'icon' => 'hub', 'hint' => 'WhatsApp Business y canales externos'],
        'audit' => ['label' => 'Auditoría', 'icon' => 'history', 'hint' => 'Registro de quién hizo qué'],
        'users' => ['label' => 'Usuarios administrativos', 'icon' => 'manage_accounts', 'hint' => 'Cuentas que entran al CRM'],
        'roles' => ['label' => 'Roles y permisos', 'icon' => 'admin_panel_settings', 'hint' => 'Quién puede hacer qué'],
    ];

    /**
     * Etiqueta de cada acción. Corta a propósito: en la matriz se lee bajo el
     * nombre del dominio, y «Ver» dice lo mismo que «Ver miembros» con la mitad
     * de ruido.
     *
     * @var array<string, string>
     */
    private const ACTIONS = [
        'view' => 'Ver',
        'create' => 'Crear',
        'edit' => 'Editar',
        'archive' => 'Archivar',
        'delete' => 'Archivar',
        'cancel' => 'Anular',
        'operate' => 'Operar',
        'manage' => 'Administrar',
        'use' => 'Usar',
    ];

    /**
     * Aclaración para las acciones cuyo alcance no es obvio por el nombre.
     *
     * @var array<string, string>
     */
    private const HELP = [
        'cash.products.operate' => 'Abrir y cerrar el turno de productos, y registrar ventas.',
        'cash.products.manage' => 'Cerrar turnos de otra persona y registrar el arqueo físico.',
        'cash.gym.operate' => 'Abrir y cerrar el turno del gimnasio, y cobrar membresías en mostrador.',
        'cash.gym.manage' => 'Cerrar turnos de otra persona y registrar el arqueo físico.',
        'members.archive' => 'Retirar la ficha de un socio. No borra su historial.',
        'payments.cancel' => 'Anular un pago ya registrado.',
        'earnings.view' => 'Ver cuánto factura el negocio.',
        'audit.view' => 'Consultar el registro de acciones. Nadie puede escribirlo ni borrarlo.',
        'roles.manage' => 'Crear roles y repartir permisos. Es el permiso más alto del CRM.',
        'users.manage' => 'Crear cuentas del CRM y asignarles rol.',
        'integrations.manage' => 'Conectar y desconectar WhatsApp Business.',
        'moderation.manage' => 'Sancionar, retirar contenido y resolver apelaciones.',
    ];

    /**
     * Todas las llaves, en el orden de los dominios.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(fn (array $p) => $p['key'], self::rows());
    }

    /**
     * Catálogo completo para el CRM: cada permiso con su dominio, su acción y
     * su explicación, ya ordenado y agrupable sin trabajo por parte del cliente.
     *
     * @return list<array{key: string, domain: string, domain_label: string, domain_icon: string, domain_hint: string, action: string, label: string, help: string|null}>
     */
    public static function rows(): array
    {
        $reales = AuthorizationMap::referencedPermissions();
        $porDominio = [];

        foreach ($reales as $clave) {
            [$dominio, $accion] = self::split($clave);
            $porDominio[$dominio][] = ['key' => $clave, 'action' => $accion];
        }

        $filas = [];
        foreach (self::DOMAINS as $dominio => $meta) {
            foreach (self::sortActions($porDominio[$dominio] ?? []) as $p) {
                $filas[] = [
                    'key' => $p['key'],
                    'domain' => $dominio,
                    'domain_label' => $meta['label'],
                    'domain_icon' => $meta['icon'],
                    'domain_hint' => $meta['hint'],
                    'action' => $p['action'],
                    'label' => self::ACTIONS[$p['action']] ?? $p['action'],
                    'help' => self::HELP[$p['key']] ?? null,
                ];
            }
            unset($porDominio[$dominio]);
        }

        // Un dominio nuevo en AuthorizationMap sin entrada en DOMAINS no se
        // pierde: aparece al final con su clave por nombre, y se ve enseguida
        // que falta describirlo.
        foreach ($porDominio as $dominio => $permisos) {
            foreach (self::sortActions($permisos) as $p) {
                $filas[] = [
                    'key' => $p['key'],
                    'domain' => $dominio,
                    'domain_label' => $dominio,
                    'domain_icon' => 'help',
                    'domain_hint' => 'Dominio sin describir en PermissionCatalog',
                    'action' => $p['action'],
                    'label' => self::ACTIONS[$p['action']] ?? $p['action'],
                    'help' => null,
                ];
            }
        }

        return $filas;
    }

    /** Dominios presentes, en orden de presentación. @return list<array<string,string>> */
    public static function domains(): array
    {
        $vistos = [];
        foreach (self::rows() as $f) {
            $vistos[$f['domain']] ??= [
                'key' => $f['domain'],
                'label' => $f['domain_label'],
                'icon' => $f['domain_icon'],
                'hint' => $f['domain_hint'],
            ];
        }

        return array_values($vistos);
    }

    /** `cash.products.operate` → ['cash.products', 'operate'] */
    public static function split(string $clave): array
    {
        $pos = strrpos($clave, '.');

        return [substr($clave, 0, $pos), substr($clave, $pos + 1)];
    }

    /**
     * Ver primero, administrar al final. Leer la matriz de menos a más
     * privilegio es lo que permite entender un rol de un vistazo.
     *
     * @param  list<array{key:string,action:string}>  $permisos
     * @return list<array{key:string,action:string}>
     */
    private static function sortActions(array $permisos): array
    {
        $orden = ['view' => 0, 'use' => 1, 'create' => 2, 'operate' => 2, 'edit' => 3, 'cancel' => 4, 'archive' => 5, 'delete' => 5, 'manage' => 9];
        usort($permisos, fn ($a, $b) => ($orden[$a['action']] ?? 5) <=> ($orden[$b['action']] ?? 5));

        return $permisos;
    }
}
