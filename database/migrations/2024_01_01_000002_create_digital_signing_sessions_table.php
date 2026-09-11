<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signing session owns one document through N signatures.
 *
 * The key column is `base_document_path`: the PDF is rendered ONCE when the
 * session opens and frozen there. Without that, every signatory would sign a
 * freshly-rendered PDF and orphan the stamps of everyone before them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_signing_sessions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();

            // The host-app record being signed (implements Signable).
            $t->morphs('signable');

            $t->string('template_key', 64);

            // Rendered once at session open; never re-rendered.
            $t->string('base_document_path');
            $t->string('base_document_hash', 64)->nullable();

            // Advances with each signature; equals base_document_path until
            // the first signatory signs.
            $t->string('current_document_path')->nullable();
            $t->string('current_document_hash', 64)->nullable();

            // open | complete | cancelled | expired
            $t->string('status', 16)->default('open');

            // sequential | parallel — sequential honours SlotDefinition::$order.
            $t->string('sequence_mode', 16)->default('sequential');

            // progressive | incremental — see config('signature.multi_signature.mode').
            $t->string('signing_mode', 16)->default('progressive');

            $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index(['signable_type', 'signable_id', 'status'], 'dss_signable_status_idx');
            $t->index(['template_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_signing_sessions');
    }
};
