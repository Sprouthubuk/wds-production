<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit card cap (DCMS Voluntary Code): the card type the payment provider confirms is stored on the order
 * (credit / debit / prepaid / unknown), and a refused payment keeps a customer-facing explanation. Competitions
 * get an instant-win flag (none exist yet): credit cards are never accepted on those. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('card_type', 10)->nullable()->index(); // credit, debit, prepaid, unknown; null = not a card payment
            $t->string('status_note', 500)->nullable();       // shown on the order page, e.g. why it was refunded
        });
        Schema::table('competitions', function (Blueprint $t) {
            $t->boolean('instant_win')->default(false);
        });
    }

    public function down(): void
    {
        // Additive: keep the columns.
    }
};
