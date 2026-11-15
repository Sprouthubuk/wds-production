<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashflows Hosted Checkout Pages. The order keeps both gateway references (payment job + payment), where its
 * card type came from, and how much has been refunded. Every call to and from the gateway is kept (redacted: no
 * card numbers, no keys) as the order's payment history, and each refund (full or partial) is its own row. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('payment_job_reference', 64)->nullable()->index();
            $t->string('payment_reference', 64)->nullable();
            $t->string('card_type_source', 20)->nullable();   // gateway | bin | assumed (unknown counted as credit) | null
            $t->unsignedInteger('refunded_pence')->default(0);
        });

        Schema::create('payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);                 // created | status | notification | refund | error
            $t->string('status', 40)->nullable();   // the gateway's status (Pending, Paid, Failed, Cancelled, Expired, …)
            $t->string('summary', 500)->nullable();
            $t->json('payload')->nullable();        // redacted
            $t->timestamp('created_at')->useCurrent();
            $t->index(['order_id', 'created_at']);
        });

        Schema::create('order_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('refund_number', 64)->unique();
            $t->unsignedInteger('amount_pence');
            $t->string('reason', 500);
            $t->string('status', 20);               // pending | completed | failed
            $t->string('provider_reference', 64)->nullable();
            $t->string('error_code', 64)->nullable();
            $t->string('error_message', 500)->nullable();
            $t->boolean('automatic')->default(false);
            $t->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('payment_events');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['payment_job_reference']);
            $t->dropColumn(['payment_job_reference', 'payment_reference', 'card_type_source', 'refunded_pence']);
        });
    }
};
