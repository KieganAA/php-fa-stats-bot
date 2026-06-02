<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user API token for the Chrome extension (Phase W).
 *
 * Stored as a SHA-256 hash, never plaintext — the bot returns the raw token
 * once on generation; subsequent lookups compare hashes. Same threat model
 * as a Sanctum personal access token, just bolted on to the existing users
 * table instead of pulling in a whole separate package for one column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('extension_token_hash', 64)->nullable()->unique()->after('anthropic_model');
            $table->timestampTz('extension_token_created_at')->nullable()->after('extension_token_hash');
            $table->timestampTz('extension_token_used_at')->nullable()->after('extension_token_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['extension_token_hash', 'extension_token_created_at', 'extension_token_used_at']);
        });
    }
};
