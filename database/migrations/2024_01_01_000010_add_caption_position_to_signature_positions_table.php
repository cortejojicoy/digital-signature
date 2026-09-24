<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * caption_position was added to the create_signature_positions_table migration
 * after that migration had already shipped, so any installation that had run it
 * beforehand never got the column — writes fail with "column caption_position
 * of relation signature_positions does not exist". This adds it separately.
 *
 * Guarded by hasColumn because fresh installs get the column from the create
 * migration, and installs made between the two releases already have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('signature_positions', 'caption_position')) {
            return;
        }

        Schema::table('signature_positions', function (Blueprint $t) {
            // Which side of this stamp the provenance caption sits on:
            // bottom | top | left | right. Null falls back to
            // signature.caption.position.
            $t->string('caption_position', 10)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('signature_positions', 'caption_position')) {
            return;
        }

        Schema::table('signature_positions', function (Blueprint $t) {
            $t->dropColumn('caption_position');
        });
    }
};
