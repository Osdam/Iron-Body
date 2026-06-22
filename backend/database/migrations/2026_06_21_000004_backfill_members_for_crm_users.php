<?php

use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill: crea el registro `Member` para los usuarios creados manualmente
 * desde el CRM que quedaron SIN miembro vinculado. La app inicia sesión por
 * `members.document_number`; sin Member, esos usuarios recibían "Documento no
 * encontrado". El documento se normaliza igual que en el login.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereNotNull('document')
            ->whereDoesntHave('appMember')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $document = Member::normalizeDocumentNumber($user->document);
                    if ($document === null) {
                        continue;
                    }

                    try {
                        $existing = Member::where('document_number', $document)->first();

                        // Ya hay un miembro con ese documento: solo lo enlazamos
                        // al usuario si estaba huérfano (sin user_id).
                        if ($existing) {
                            if ($existing->user_id === null) {
                                $existing->user_id = $user->id;
                                $existing->save();
                            }
                            continue;
                        }

                        $email = $user->email && ! str_ends_with($user->email, '@ironbody.local')
                            ? $user->email
                            : null;

                        Member::create([
                            'user_id' => $user->id,
                            'full_name' => $user->name,
                            'email' => $email,
                            'document_number' => $document,
                            'phone' => $user->phone,
                            'gender' => $user->gender,
                            'birth_date' => $user->birth_date,
                            'status' => Member::STATUS_ACTIVE,
                        ]);
                    } catch (\Throwable $e) {
                        // No abortamos todo el backfill por un registro suelto.
                    }
                }
            });
    }

    public function down(): void
    {
        // No-op: no borramos miembros (podrían tener actividad/datos propios).
    }
};
