<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only.
 *
 * postal_entries.received_at: the moment the envelope was received (date and time), because the
 * postal deadline is now exact: received at least 48 hours before closes_at
 * (Competition::postalClosesAt()). received_on stays as the date part. Older rows keep
 * received_at null and are read as the start of received_on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postal_entries', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('postal_entries', fn (Blueprint $table) => $table->dropColumn('received_at'));
    }
};
