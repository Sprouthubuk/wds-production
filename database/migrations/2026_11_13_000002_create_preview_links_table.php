<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only.
 *
 * preview_links: a private link that lets the client see a DRAFT competition on the real site as if
 * it were live (Admin → competition → Preview link). Looked up by the token's SHA-256; the token
 * itself is kept encrypted (APP_KEY) only so the admin can copy the link again. Expires after
 * 14 days; regenerating revokes the previous link. Entries stay impossible: every entry endpoint
 * checks the competition itself, never the preview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['competition_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_links');
    }
};
