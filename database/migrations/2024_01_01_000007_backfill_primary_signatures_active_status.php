<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Primary (reusable) signatures — those with no signable_id — used to be
 * persisted as status='pending'. That status only makes sense for an
 * in-progress document-signing event; a registered signature image is just
 * "available for use". This migration retags those rows as 'active' so the
 * displayed status matches the record's actual meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('signatures')
            ->whereNull('signable_id')
            ->where('status', 'pending')
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        DB::table('signatures')
            ->whereNull('signable_id')
            ->where('status', 'active')
            ->update(['status' => 'pending']);
    }
};
