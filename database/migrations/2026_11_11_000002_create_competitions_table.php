<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('strapline', 500)->nullable();
            $table->enum('category', ['cars', 'parts', 'cash', 'merch'])->default('cars');
            $table->string('badge')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'live', 'closed', 'drawn'])->default('draft')->index();
            $table->json('images')->nullable();        // [{src, alt}]
            $table->json('description')->nullable();   // [paragraph, ...]
            $table->json('specs')->nullable();         // [{label, value}]
            $table->unsignedInteger('ticket_price_pence');
            $table->json('bundles')->nullable();       // [{qty, price_pence}]
            $table->unsignedInteger('total_tickets');
            $table->unsignedInteger('max_per_person');
            // Next ticket number = tickets_allocated + 1. Only ever changed inside TicketAllocator under a row lock.
            $table->unsignedInteger('tickets_allocated')->default(0);
            $table->unsignedInteger('cash_alternative_pence')->nullable();
            $table->string('build_video_url')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('draw_at');
            $table->boolean('postal_entry_enabled')->default(true); // always true in practice; kept for audit
            $table->boolean('event_sales_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
