<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postpone (emergency only): when a live competition's close is moved later (Verity's audited extension),
 * the site shows a notice with the old time, the new time and the public reason. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $t) {
            $t->timestamp('postponed_at')->nullable();
            $t->timestamp('postponed_from')->nullable(); // the closing time before the latest postponement
            $t->string('postponement_reason', 300)->nullable();
        });
    }

    public function down(): void
    {
        // Additive: keep the columns.
    }
};
