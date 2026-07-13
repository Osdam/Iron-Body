<?php

namespace App\Services\Members;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Resuelve (o crea) el `User` enlazado a un `Member` — una sola ficha por miembro.
 * Lógica COMPARTIDA por los flujos de pago (único y automático) para no duplicar
 * la estrategia de enlace. Comportamiento idéntico al que vivía inline en
 * WompiPaymentController (extraído sin cambios de lógica).
 */
class MemberUserResolver
{
    public function resolve(Member $member): User
    {
        if ($member->user) {
            return $member->user;
        }

        $user = User::query()->where('document', $member->document_number)->first();
        if (! $user && $member->email) {
            $user = User::query()->where('email', $member->email)->first();
        }
        if (! $user) {
            $email = $member->email ?: "member-{$member->id}@ironbody.local";
            if (User::query()->where('email', $email)->exists()) {
                $email = "member-{$member->id}-{$member->document_number}@ironbody.local";
            }
            $user = User::create([
                'name'     => $member->full_name,
                'email'    => $email,
                'password' => Hash::make(Str::random(40)),
                'document' => $member->document_number,
                'phone'    => $member->phone,
                'status'   => 'pending',
            ]);
        }
        $member->forceFill(['user_id' => $user->id])->save();

        return $user;
    }
}
