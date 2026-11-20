<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy policy: winner ID documents are deleted within 30 days of the prize being handed over.
 * - winners.handed_over_at: when the prize was marked "Delivered" (Admin → Winners → handover).
 * - id_verifications.files_deleted_at: when the ID files were deleted (wds:delete-winner-ids).
 * Additive. Winners already marked delivered get handed_over_at = their last update, the best record there is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winners', fn (Blueprint $t) => $t->timestamp('handed_over_at')->nullable()->after('handover'));
        Schema::table('id_verifications', fn (Blueprint $t) => $t->timestamp('files_deleted_at')->nullable()->after('reviewed_by'));
        DB::table('winners')->where('handover', 'delivered')->whereNull('handed_over_at')->update(['handed_over_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('winners', fn (Blueprint $t) => $t->dropColumn('handed_over_at'));
        Schema::table('id_verifications', fn (Blueprint $t) => $t->dropColumn('files_deleted_at'));
    }
};
