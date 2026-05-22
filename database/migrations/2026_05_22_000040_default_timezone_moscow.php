<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The team actually works in UTC+3. Default for new users + retro-bump the
 * users who got the previous UTC default but never touched their timezone.
 *
 * Only rewrites UTC → Europe/Moscow — anyone who explicitly picked a TZ
 * (different from UTC) is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Europe/Moscow')->change();
        });

        DB::table('users')->where('timezone', 'UTC')->update(['timezone' => 'Europe/Moscow']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->change();
        });

        DB::table('users')->where('timezone', 'Europe/Moscow')->update(['timezone' => 'UTC']);
    }
};
