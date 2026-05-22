<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user override for the Anthropic Claude credentials. Empty/NULL means
 * fall back to the env-level keys. Stored as text because Laravel's encrypted
 * cast emits a ciphertext blob much longer than the raw key, and the model
 * name might one day be model strings of arbitrary length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('anthropic_api_key')->nullable()->after('settings');
            $table->string('anthropic_model', 128)->nullable()->after('anthropic_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['anthropic_api_key', 'anthropic_model']);
        });
    }
};
