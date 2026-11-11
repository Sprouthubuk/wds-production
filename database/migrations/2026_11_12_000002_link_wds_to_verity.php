<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Links WDS's own records to the Verity engine's (the verity_* tables, created by the
 * package's migration). WDS keeps tickets, draws and winners as a mirror of what Verity decided;
 * nothing here can change a result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->string('verity_ref', 64)->nullable()->unique();   // VTY-…: set once, at publish
            $table->string('verity_status', 16)->nullable();          // live | closed | drawn | void (Verity's)
            $table->timestamp('published_at')->nullable();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('verity_entry_id')->nullable()->unique();
            $table->timestamp('withdrawn_at')->nullable();             // refunded while open: out of the draw
        });

        Schema::table('draws', function (Blueprint $table) {
            $table->string('verity_ref', 64)->nullable()->index();
            $table->unsignedBigInteger('beacon_round')->nullable();    // the drand round committed at close
            $table->timestamp('beacon_round_at')->nullable();          // when that round is published
        });
    }

    public function down(): void
    {
        Schema::table('draws', fn (Blueprint $t) => $t->dropColumn(['verity_ref', 'beacon_round', 'beacon_round_at']));
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropUnique(['verity_entry_id']);
            $t->dropColumn(['verity_entry_id', 'withdrawn_at']);
        });
        Schema::table('competitions', function (Blueprint $t) {
            $t->dropUnique(['verity_ref']);
            $t->dropColumn(['verity_ref', 'verity_status', 'published_at']);
        });
    }
};
