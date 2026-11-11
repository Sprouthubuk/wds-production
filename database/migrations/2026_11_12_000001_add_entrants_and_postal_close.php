<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only.
 *
 * entrants: one row per normalised email, shared by online, postal and event entries. The draw
 * engine knows an entrant only as the opaque owner reference "entrant:{id}" (never the email),
 * and the per-person maximum counts per entrant.
 *
 * competitions.postal_closes_at: postal entries must be received by then. Null means the
 * default, three days before closes_at (Competition::postalClosesAt()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrants', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();   // normalised lower-case (TicketAllocator::normaliseEmail)
            $table->timestamps();
        });

        foreach (['tickets', 'orders', 'postal_entries'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('entrant_id')->nullable()->constrained('entrants');
            });
        }

        Schema::table('competitions', function (Blueprint $table) {
            $table->timestamp('postal_closes_at')->nullable();
        });

        // Existing rows (test data before launch): one entrant per distinct normalised email.
        $emails = collect(['tickets', 'orders', 'postal_entries'])
            ->flatMap(fn ($t) => DB::table($t)->distinct()->pluck('email'))
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->filter()->unique()->values();
        $now = now();
        foreach ($emails->chunk(500) as $chunk) {
            DB::table('entrants')->insertOrIgnore($chunk->map(fn ($e) => ['email' => $e, 'created_at' => $now, 'updated_at' => $now])->all());
        }
        $ids = DB::table('entrants')->pluck('id', 'email');
        foreach (['tickets', 'orders', 'postal_entries'] as $t) {
            DB::table($t)->whereNull('entrant_id')->orderBy('id')->select('id', 'email')->chunkById(500, function ($rows) use ($t, $ids) {
                foreach ($rows as $row) {
                    $id = $ids[mb_strtolower(trim((string) $row->email))] ?? null;
                    if ($id) {
                        DB::table($t)->where('id', $row->id)->update(['entrant_id' => $id]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $table) => $table->dropColumn('postal_closes_at'));
        foreach (['postal_entries', 'orders', 'tickets'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropConstrainedForeignId('entrant_id'));
        }
        Schema::dropIfExists('entrants');
    }
};
