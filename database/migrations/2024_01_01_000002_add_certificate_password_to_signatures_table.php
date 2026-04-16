<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $t) {
            $t->text('certificate_password')->nullable()->after('certificate_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $t) {
            $t->dropColumn('certificate_password');
        });
    }
};
