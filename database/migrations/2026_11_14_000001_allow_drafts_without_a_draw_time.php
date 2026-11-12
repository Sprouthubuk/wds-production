<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A draft can be saved before its dates are confirmed (draw_at empty). Relaxes NOT NULL only: no data
 * changes, nothing dropped. Publishing still requires the closing time and the live draw time
 * (VerityDrawEngine::publish()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->timestamp('draw_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left nullable: putting NOT NULL back would fail on any draft saved without a date.
    }
};
