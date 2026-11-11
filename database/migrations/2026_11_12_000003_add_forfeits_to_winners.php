<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. A WDS winner row mirrors one Verity winner (verity_winners). A forfeited winner is
 * never deleted: it keeps its row, marked forfeited with the public reason category; its replacement
 * is a new row with redraw_number 1, 2 … (Verity's protocol R2 redraws).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winners', function (Blueprint $table) {
            $table->unsignedBigInteger('verity_winner_id')->nullable()->unique();
            $table->unsignedInteger('redraw_number')->nullable();     // null = the original draw
            $table->timestamp('forfeited_at')->nullable();
            $table->string('forfeit_category', 32)->nullable();       // failed_id | no_response | ineligible | other (public)
            $table->string('forfeit_note', 500)->nullable();          // WDS-only note, never sent to Verity or shown publicly
            $table->foreignId('forfeited_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('winners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forfeited_by');
            $table->dropUnique(['verity_winner_id']);
            $table->dropColumn(['verity_winner_id', 'redraw_number', 'forfeited_at', 'forfeit_category', 'forfeit_note']);
        });
    }
};
