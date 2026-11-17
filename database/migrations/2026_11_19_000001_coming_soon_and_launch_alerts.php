<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pre-launch: "coming soon" competitions and launch alerts.
 *
 *  - competitions.status gains "coming_soon" (published on Verity, terms locked, not open until opens_at).
 *    Rows that were "scheduled" (the old name for the same thing) become "coming_soon". "scheduled" stays
 *    allowed in the column so nothing written by an older release can fail; the app no longer writes it.
 *  - newsletter_subscribers become the launch alert list: competition_id (null = site-wide), consent_at,
 *    source, an unsubscribe token and list_key (one row per email per list). Every existing "Get first
 *    access" sign-up stays subscribed, site-wide: its wording ("One email at launch…") covers launch emails.
 *  - launch_alert_sends: every launch email queued or sent, so nobody gets the same one twice.
 *  - competition_events: Admin's history of status changes (publish, open now, opened at opens_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->enum('status', ['draft', 'scheduled', 'coming_soon', 'live', 'closed', 'drawn'])->default('draft')->change();
        });
        DB::table('competitions')->where('status', 'scheduled')->update(['status' => 'coming_soon']);

        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->foreignId('competition_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('competition_id')->constrained()->nullOnDelete();
            $table->timestamp('consent_at')->nullable()->after('user_id');
            $table->string('consent_text', 300)->nullable()->after('consent_at');
            $table->string('source', 40)->nullable()->after('consent_text');
            $table->string('unsubscribe_token', 64)->nullable()->unique()->after('source');
            // "<competition id or 0>:<email>": one row per email per list. Replaces the unique email (below).
            $table->string('list_key', 220)->nullable()->unique()->after('unsubscribe_token');
        });

        DB::table('newsletter_subscribers')->orderBy('id')->each(function ($row) {
            DB::table('newsletter_subscribers')->where('id', $row->id)->update([
                'email' => mb_strtolower(trim($row->email)),
                'consent_at' => $row->created_at,
                'consent_text' => 'Be first to know when entries open. One email at launch, then live draw reminders. No spam.',
                'source' => 'first_access',
                'unsubscribe_token' => Str::random(48),
                'list_key' => '0:'.mb_strtolower(trim($row->email)),
            ]);
        });

        // The same email may now be on the site-wide list and on a competition's list (list_key is unique instead).
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->index('email');
        });

        Schema::create('launch_alert_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 190);
            $table->foreignId('newsletter_subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('launch'); // launch | test
            // "launch:<competition id>:<email>" for a launch email: unique, so a second send can't be queued.
            // Empty for a test send (those can repeat).
            $table->string('dedupe_key', 240)->nullable()->unique();
            $table->string('status', 20)->default('queued'); // queued | sent | failed | skipped
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['competition_id', 'status']);
        });

        Schema::create('competition_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = the scheduler
            $table->string('action', 40);
            $table->string('summary', 500);
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_events');
        Schema::dropIfExists('launch_alert_sends');
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropConstrainedForeignId('competition_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropUnique(['unsubscribe_token']);
            $table->dropUnique(['list_key']);
            $table->dropColumn(['consent_at', 'consent_text', 'source', 'unsubscribe_token', 'list_key']);
        });
        DB::table('competitions')->where('status', 'coming_soon')->update(['status' => 'scheduled']);
    }
};
