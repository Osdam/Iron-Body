<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            if (! Schema::hasColumn('members', 'user_id')) {
                $table->foreignId('user_id')->nullable()->unique()->after('id');
            }
        });

        $members = DB::table('members')
            ->whereNull('user_id')
            ->orderBy('id')
            ->get();

        foreach ($members as $member) {
            $user = DB::table('users')
                ->where('document', $member->document_number)
                ->first();

            if (! $user && $member->email) {
                $user = DB::table('users')
                    ->where('email', $member->email)
                    ->first();
            }

            if (! $user) {
                $email = $member->email ?: 'member-' . $member->id . '@ironbody.local';

                if (DB::table('users')->where('email', $email)->exists()) {
                    $email = 'member-' . $member->id . '-' . time() . '@ironbody.local';
                }

                $userId = DB::table('users')->insertGetId([
                    'name' => $member->full_name,
                    'email' => $email,
                    'password' => Hash::make('default-password'),
                    'document' => $member->document_number,
                    'phone' => $member->phone,
                    'status' => $member->status === 'active' ? 'active' : 'pending',
                    'created_at' => $member->created_at,
                    'updated_at' => now(),
                ]);
            } else {
                $userId = $user->id;

                DB::table('users')
                    ->where('id', $userId)
                    ->update([
                        'name' => $member->full_name,
                        'document' => $member->document_number,
                        'phone' => $member->phone,
                        'status' => $member->status === 'active' ? 'active' : 'pending',
                        'updated_at' => now(),
                    ]);
            }

            DB::table('members')
                ->where('id', $member->id)
                ->update(['user_id' => $userId]);
        }
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            if (Schema::hasColumn('members', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });
    }
};
