<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * postal_entries.status gains "held": an envelope logged while its competition is coming soon. It gets its
 * ticket number when the competition opens (PostalEntryService::allocateHeld, from CompetitionLauncher::open).
 * Additive: the existing values stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postal_entries', function (Blueprint $table) {
            $table->enum('status', ['allocated', 'rejected', 'held'])->change(); // the existing index on status stays
        });
    }

    public function down(): void
    {
        // Additive only: "held" stays (rows may use it).
    }
};
