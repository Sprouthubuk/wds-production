<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid competitions ask a multiple-choice skill question at checkout, alongside the free postal route.
 *  - competitions.skill_question: {"question": "...", "options": ["...", "...", "..."], "correct": 1} (null = none;
 *    free competitions never have one). Locked once published, with the other terms.
 *  - order_lines.skill_answer: the answer the customer gave (checked server-side before the order is created).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->json('skill_question')->nullable();
        });
        Schema::table('order_lines', function (Blueprint $table) {
            $table->string('skill_answer', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn('skill_answer'));
        Schema::table('competitions', fn (Blueprint $table) => $table->dropColumn('skill_question'));
    }
};
