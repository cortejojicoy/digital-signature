<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which registered device each signature and audit row came from.
 *
 * An alter migration because both tables have already shipped to hosts. On a
 * primary signature, device_id is the device it was created on; on a
 * document-signing row, the device it was used on. Null means no verified
 * device was present — delegated signing, a queue worker, or an install that
 * does not require one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('digital_signatures', 'device_id')) {
            Schema::table('digital_signatures', function (Blueprint $t) {
                $t->foreignId('device_id')
                    ->nullable()
                    ->after('machine_fingerprint')
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('digital_signature_audits', 'device_id')) {
            Schema::table('digital_signature_audits', function (Blueprint $t) {
                $t->foreignId('device_id')
                    ->nullable()
                    ->after('delegation_id')
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['digital_signature_audits', 'digital_signatures'] as $table) {
            if (Schema::hasColumn($table, 'device_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('device_id');
                });
            }
        }
    }
};
