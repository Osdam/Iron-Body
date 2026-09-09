<?php

namespace App\Services\Caja;

use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Trainer;

/**
 * Quién puede deber dinero, y cómo se le encuentra.
 *
 * Un único sitio donde vive la traducción entre los cuatro tipos operativos y
 * las tres tablas reales. Sin esto, cada pantalla que necesite un deudor
 * volvería a decidir por su cuenta dónde buscar un entrenador, y bastaría con
 * que dos discreparan para que la misma persona apareciera dos veces.
 *
 * SIEMPRE devuelve un identificador secundario junto al nombre. Fiarle a
 * «Alejandro Casas» sin nada más es lo que hace imposible cobrar tres meses
 * después, cuando hay dos. Se usa el documento cuando existe —socios y
 * entrenadores— y el email cuando no —las cuentas del CRM no guardan
 * documento—: real en ambos casos, nunca inventado.
 */
class DebtorDirectory
{
    /** Cuántos resultados devuelve una búsqueda. */
    public const LIMIT = 20;

    /**
     * Busca personas de UN tipo. Nunca mezcla: el usuario ya eligió el grupo.
     *
     * @return list<array<string, mixed>>
     */
    public function search(DebtorType $type, string $term): array
    {
        $term = trim($term);

        return match ($type) {
            DebtorType::MEMBER => $this->members($term),
            DebtorType::TRAINER => $this->trainers($term),
            DebtorType::ADMIN, DebtorType::RECEPTION => $this->admins($type, $term),
        };
    }

    /**
     * La ficha de un deudor concreto, o null si ya no existe.
     *
     * Es lo que permite responder «¿quién originó esta deuda?» meses después
     * sin depender del nombre que se copió en su momento.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(DebtorType $type, int $id): ?array
    {
        return match ($type) {
            DebtorType::MEMBER => ($m = Member::find($id)) === null ? null : $this->fromMember($m),
            DebtorType::TRAINER => ($t = Trainer::find($id)) === null ? null : $this->fromTrainer($t),
            DebtorType::ADMIN, DebtorType::RECEPTION => $this->resolveAdmin($type, $id),
        };
    }

    /**
     * Resuelve muchos deudores de golpe, agrupando por tipo.
     *
     * Un listado de 300 cuentas haría 300 consultas si cada fila resolviera la
     * suya. Aquí son cuatro como mucho, una por tabla.
     *
     * @param  list<array{0: DebtorType, 1: int}>  $pairs
     * @return array<string, array<string, mixed>>  indexado por "tipo:id"
     */
    public function resolveMany(array $pairs): array
    {
        $porTipo = [];
        foreach ($pairs as [$type, $id]) {
            $porTipo[$type->value][$id] = $id;
        }

        $out = [];

        foreach ($porTipo as $tipo => $ids) {
            $type = DebtorType::from($tipo);
            $ids = array_values($ids);

            $fichas = match ($type) {
                DebtorType::MEMBER => Member::whereKey($ids)->get()
                    ->map(fn (Member $m) => $this->fromMember($m)),
                DebtorType::TRAINER => Trainer::whereKey($ids)->get()
                    ->map(fn (Trainer $t) => $this->fromTrainer($t)),
                DebtorType::ADMIN, DebtorType::RECEPTION => Admin::whereKey($ids)
                    ->whereIn('role', $type->adminRoles())->get()
                    ->map(fn (Admin $a) => $this->fromAdmin($type, $a)),
            };

            foreach ($fichas as $ficha) {
                $out[$tipo.':'.$ficha['id']] = $ficha;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function members(string $term): array
    {
        $q = Member::query()->select(['id', 'full_name', 'document_number', 'phone', 'status']);

        if ($term !== '') {
            $q->where(fn ($s) => $s
                ->whereRaw('lower(full_name) like ?', [$this->needle($term)])
                ->orWhere('document_number', 'like', "%{$term}%"));
        }

        return $q->orderBy('full_name')->limit(self::LIMIT)->get()
            ->map(fn (Member $m) => $this->fromMember($m))->all();
    }

    /** @return list<array<string, mixed>> */
    private function trainers(string $term): array
    {
        $q = Trainer::query()->select(['id', 'full_name', 'document', 'email', 'status']);

        if ($term !== '') {
            $q->where(fn ($s) => $s
                ->whereRaw('lower(full_name) like ?', [$this->needle($term)])
                ->orWhere('document', 'like', "%{$term}%"));
        }

        return $q->orderBy('full_name')->limit(self::LIMIT)->get()
            ->map(fn (Trainer $t) => $this->fromTrainer($t))->all();
    }

    /** @return list<array<string, mixed>> */
    private function admins(DebtorType $type, string $term): array
    {
        $q = Admin::query()
            ->select(['id', 'name', 'email', 'role'])
            ->whereIn('role', $type->adminRoles());

        if ($term !== '') {
            $q->where(fn ($s) => $s
                ->whereRaw('lower(name) like ?', [$this->needle($term)])
                ->orWhereRaw('lower(email) like ?', [$this->needle($term)]));
        }

        return $q->orderBy('name')->limit(self::LIMIT)->get()
            ->map(fn (Admin $a) => $this->fromAdmin($type, $a))->all();
    }

    /** @return array<string, mixed>|null */
    private function resolveAdmin(DebtorType $type, int $id): ?array
    {
        $admin = Admin::whereKey($id)->whereIn('role', $type->adminRoles())->first();

        return $admin === null ? null : $this->fromAdmin($type, $admin);
    }

    /**
     * El patrón de búsqueda, en minúsculas.
     *
     * Se compara `lower(columna)` en vez de usar `ilike`: `ilike` solo existe
     * en PostgreSQL, y `like` a secas distingue mayúsculas allí —buscar «ana»
     * no encontraría a «Ana»—. Así vale igual en producción y en los tests.
     */
    private function needle(string $term): string
    {
        return '%'.mb_strtolower($term).'%';
    }

    /** @return array<string, mixed> */
    private function fromMember(Member $m): array
    {
        return [
            'type' => DebtorType::MEMBER->value,
            'type_label' => DebtorType::MEMBER->label(),
            'id' => $m->id,
            'name' => $m->full_name,
            'document' => $m->document_number,
            // El documento es lo que se enseña; si el socio no lo tiene
            // cargado, al menos queda el teléfono. Ninguno se inventa.
            'contact' => $m->document_number ?: $m->phone,
            'status' => $m->status,
        ];
    }

    /** @return array<string, mixed> */
    private function fromTrainer(Trainer $t): array
    {
        return [
            'type' => DebtorType::TRAINER->value,
            'type_label' => DebtorType::TRAINER->label(),
            'id' => $t->id,
            'name' => $t->full_name,
            'document' => $t->document,
            'contact' => $t->document ?: $t->email,
            'status' => $t->status,
        ];
    }

    /** @return array<string, mixed> */
    private function fromAdmin(DebtorType $type, Admin $a): array
    {
        return [
            'type' => $type->value,
            'type_label' => $type->label(),
            'id' => $a->id,
            'name' => $a->name,
            // `admins` no guarda documento. Se dice con el email, que es su
            // identificador real, en vez de enseñar un guion.
            'document' => null,
            'contact' => $a->email,
            'status' => $a->status ?? null,
        ];
    }
}
