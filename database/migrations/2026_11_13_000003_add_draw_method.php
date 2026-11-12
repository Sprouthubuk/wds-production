<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draw methods (docs/draw-methods-plan.md): "verity" (Verity draws on drand; "Close & draw now" on stream)
 * or "external" (Verity seals the entries; WDS draws on the livestream and records the ticket). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $t) {
            $t->string('draw_method', 16)->default('verity')->after('event_sales_enabled');
        });
        Schema::table('draws', function (Blueprint $t) {
            $t->string('method', 16)->default('verity')->after('engine');
            $t->string('livestream_url', 300)->nullable()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('draws', fn (Blueprint $t) => $t->dropColumn(['method', 'livestream_url']));
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('draw_method'));
    }
};
