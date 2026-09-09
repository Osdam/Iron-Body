<?php

namespace App\Enums;

/**
 * Qué clase de persona debe dinero.
 *
 * POR QUÉ CUATRO Y NO UNA IDENTIDAD COMÚN
 * ---------------------------------------
 * Se auditó antes de decidirlo. `Identity` agrupa a socios y entrenadores —son
 * la misma persona cuando lo son— pero NO alcanza a las cuentas del CRM: un
 * `Admin` es un login de panel (nombre, email, rol), no está colgado de ninguna
 * identidad y ni siquiera guarda documento. En producción hay 8 cuentas de CRM,
 * 5 entrenadores y 3.793 socios, y el marcador `members.is_staff` está a cero en
 * los 3.793: nadie lo usa.
 *
 * Así que no había una identidad única que reutilizar. Forzar una habría
 * significado inventar filas de socio para el personal del gimnasio, y entonces
 * el histórico diría que la recepcionista es socia, que no lo es.
 *
 * ADMIN y RECEPTION son la MISMA tabla (`admins`) separadas por `role`: es una
 * distinción operativa, no de esquema. Se separan porque quien está en el
 * mostrador necesita saber a quién le está fiando, y «Administrador» y
 * «Recepción» no son lo mismo a la hora de reclamar una deuda.
 */
enum DebtorType: string
{
    /** Socio del gimnasio. Tabla `members`, con documento. */
    case MEMBER = 'member';

    /** Cuenta del CRM con rol administrativo. Tabla `admins`, SIN documento. */
    case ADMIN = 'admin';

    /** Cuenta del CRM en recepción. Tabla `admins`, SIN documento. */
    case RECEPTION = 'reception';

    /** Entrenador. Tabla `trainers`, con documento. */
    case TRAINER = 'trainer';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $t) => $t->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::MEMBER => 'Miembro',
            self::ADMIN => 'Administrador',
            self::RECEPTION => 'Recepción',
            self::TRAINER => 'Entrenador',
        };
    }

    /** Los roles de `admins` que caen bajo este tipo, si es de esa tabla. */
    public function adminRoles(): array
    {
        return match ($this) {
            self::RECEPTION => ['Recepción'],
            // Todo lo administrativo. Se excluye «Entrenador» a propósito: esa
            // cuenta es el puente a un entrenador, y el entrenador se busca en
            // su propia tabla, donde sí tiene documento.
            self::ADMIN => ['Super Admin', 'Administrador', 'Administrativo'],
            default => [],
        };
    }
}
