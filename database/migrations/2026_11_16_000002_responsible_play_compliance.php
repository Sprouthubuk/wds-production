<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DCMS Voluntary Code / Responsible Play Policy. Additive:
 *  - users.closed_at: permanent, self-service account closure;
 *  - responsible_play_events: limit hits, breaks, self-exclusions, closures, card changes (harm monitoring);
 *  - harm_flags: the admin review queue those events raise, with notes and actions;
 *  - orders.card_ref: the provider's reference for the card used (to spot frequent payment-method changes);
 *  - winners.contacted_at / contact_reminder_sent_at: the 48-hour contact reminder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('closed_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->string('card_ref', 100)->nullable(); // provider card token / fingerprint; never card numbers
        });
        Schema::table('winners', function (Blueprint $t) {
            $t->timestamp('contacted_at')->nullable();
            $t->timestamp('contact_reminder_sent_at')->nullable();
        });
        // Winners that already exist: no reminder emails about them; ones already past "not started" count as contacted.
        DB::table('winners')->update(['contact_reminder_sent_at' => now()]);
        DB::table('winners')->where('handover', '!=', 'not_started')->update(['contacted_at' => DB::raw('updated_at')]);
        Schema::create('responsible_play_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email')->index();
            $t->string('type', 40)->index(); // limit_hit, break, self_exclusion, closure, card_change
            $t->json('details')->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
        Schema::create('harm_flags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email')->index();
            $t->string('reason', 40);        // repeated_limit_hits, payment_method_changes, break_tools
            $t->string('summary', 500);
            $t->string('status', 20)->default('open')->index(); // open, contacted, limit_lowered, blocked, closed
            $t->text('notes')->nullable();
            $t->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('actioned_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Additive: keep everything.
    }
};
