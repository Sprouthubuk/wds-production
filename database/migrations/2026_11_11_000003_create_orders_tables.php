<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('type', ['percent', 'amount']);
            $table->unsignedInteger('value');             // percent (e.g. 10) or pence
            $table->foreignId('competition_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('access_key', 64);             // lets guests view their order page
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('channel', ['online', 'event'])->default('online');
            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled', 'refunded'])->default('pending')->index();
            $table->unsignedInteger('subtotal_pence');    // before bundles
            $table->unsignedInteger('bundle_saving_pence')->default(0);
            $table->unsignedInteger('discount_pence')->default(0);
            $table->unsignedInteger('credit_used_pence')->default(0);
            $table->unsignedInteger('total_pence');       // charged to card / cash
            $table->foreignId('discount_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method')->nullable(); // card, cash, card_reader, credit
            $table->string('payment_provider')->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('reserved_until')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->constrained();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('unit_price_pence');
            $table->unsignedInteger('line_total_pence');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('discount_codes');
    }
};
