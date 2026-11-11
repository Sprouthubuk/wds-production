<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained();
            $table->string('engine');                       // e.g. verity, commit-reveal
            $table->enum('status', ['prepared', 'drawn', 'published', 'void'])->default('prepared');
            $table->unsignedInteger('entries_count');
            $table->string('entries_hash', 128);            // hash of the locked ticket list
            $table->string('commitment', 128)->nullable();  // published before the draw
            $table->text('proof')->nullable();              // engine-specific JSON (seed reveal, signatures…)
            $table->unsignedInteger('winning_number')->nullable();
            $table->foreignId('winning_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->string('certificate_path')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('drawn_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('draw_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name');
            $table->string('prize');
            $table->string('image')->nullable();
            $table->enum('verification', ['awaiting_id', 'to_review', 'approved', 'denied'])->default('awaiting_id');
            $table->enum('handover', ['not_started', 'contacted', 'booked', 'delivered'])->default('not_started');
            $table->boolean('show_on_site')->default(false);
            $table->timestamp('drawn_at')->nullable();
            $table->timestamps();
        });

        Schema::create('id_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_path');      // stored on a private disk
            $table->string('selfie_path');
            $table->enum('status', ['to_review', 'approved', 'denied'])->default('to_review');
            $table->string('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount_pence');      // + credit added, − credit spent
            $table->string('reason');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('order_reference')->nullable();
            $table->text('message');
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'contact_messages', 'newsletter_subscribers', 'credit_transactions', 'id_verifications', 'winners', 'draws'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
