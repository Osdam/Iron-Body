<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('members')
            ->where('status', 'created')
            ->update(['status' => 'pending_registration']);

        DB::table('members')
            ->whereIn('status', ['identity_verified', 'identity_review', 'legal_accepted', 'signed'])
            ->update(['status' => 'incomplete']);

        DB::table('members')
            ->where('status', 'biometric_registered')
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        DB::table('members')
            ->where('status', 'pending_registration')
            ->update(['status' => 'created']);
    }
};
