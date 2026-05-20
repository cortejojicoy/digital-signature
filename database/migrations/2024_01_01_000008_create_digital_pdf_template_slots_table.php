<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_pdf_template_slots', function (Blueprint $t) {
            $t->id();

            // Pairs with PdfTemplate::key() / SlotDefinition::$key in code.
            // These are app-defined strings, not foreign keys — the table
            // only stores the saved coordinates; the template/slot identity
            // lives in code.
            $t->string('template_key', 64);
            $t->string('slot_key', 64);

            $t->unsignedSmallInteger('page')->default(1);
            $t->float('x');       // PDF points from left
            $t->float('y');       // PDF points from BOTTOM
            $t->float('width');
            $t->float('height');

            $t->timestamps();

            $t->unique(['template_key', 'slot_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_pdf_template_slots');
    }
};
