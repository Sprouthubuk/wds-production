<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-person maximum is optional: null means no cap (the BMW and the coilovers). Verity treats a null
 * max_per_person as no limit too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $t) {
            $t->unsignedInteger('max_per_person')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Additive: leave the column nullable.
    }
};
