<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('address');
            $table->date('date_of_birth');
            $table->string('email')->index();
            $table->string('phone')->nullable();
            $table->date('received_on');
            $table->enum('status', ['allocated', 'rejected'])->index();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained();
            $table->unsignedInteger('number');
            $table->enum('channel', ['online', 'postal', 'event']);
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('postal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();   // normalised lower-case; used for per-person limits
            $table->string('name');
            $table->timestamps();
            $table->unique(['competition_id', 'number']);
            $table->index(['competition_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('postal_entries');
    }
};
