<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repurpose the default Laravel users table to also host Telegram-identified
 * users. We keep name/email/password for any future password-auth needs (now
 * nullable), and add Telegram identity + per-user preferences. The canonical
 * identifier in this app is `telegram_user_id` — unique, stringified to match
 * what comes off the Bot API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_user_id', 32)->nullable()->unique()->after('id');
            $table->string('telegram_username', 64)->nullable()->after('telegram_user_id');
            $table->string('telegram_first_name', 128)->nullable()->after('telegram_username');
            $table->string('telegram_last_name', 128)->nullable()->after('telegram_first_name');
            $table->string('telegram_language_code', 8)->nullable()->after('telegram_last_name');
            $table->string('timezone', 64)->default('UTC')->after('telegram_language_code');
            $table->string('default_period', 32)->default('today')->after('timezone');
            $table->unsignedTinyInteger('default_position')->default(1)->after('default_period');
            $table->jsonb('settings')->default('{}')->after('default_position');
            $table->timestampTz('last_seen_at')->nullable()->after('settings');

            // Make legacy Laravel auth fields optional for TG-only users.
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_user_id',
                'telegram_username',
                'telegram_first_name',
                'telegram_last_name',
                'telegram_language_code',
                'timezone',
                'default_period',
                'default_position',
                'settings',
                'last_seen_at',
            ]);
            $table->string('name')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
