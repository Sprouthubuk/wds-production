<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draw methods (docs/draw-methods-plan.md): "verity" (Verity draws on drand; "Close & draw now" on stream)
 * or "external" (Verity seals the entries; WDS draws on the livestream and records the ticket). Additive.
 * An external draw names its random number tool (name + https link): sent to Verity at publish, changeable
 * until entries close, then sealed with the entry fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $t) {
            $t->string('draw_method', 16)->default('verity')->after('event_sales_enabled');
            $t->string('draw_tool_name', 80)->nullable()->after('draw_method');
            $t->string('draw_tool_url', 300)->nullable()->after('draw_tool_name');
        });
        Schema::table('draws', function (Blueprint $t) {
            $t->string('method', 16)->default('verity')->after('engine');
            $t->string('livestream_url', 300)->nullable()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('draws', fn (Blueprint $t) => $t->dropColumn(['method', 'livestream_url']));
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn(['draw_method', 'draw_tool_name', 'draw_tool_url']));
    }
};
