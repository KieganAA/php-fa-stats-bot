<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert landing_aliases.created_by_user_id (stringly-typed telegram id) into
 * a real FK on users.id. Existing rows: upsert the User by telegram_user_id,
 * then swap. Aliases stay globally visible (this is just attribution) — the
 * hybrid sharing model lets the whole team reuse a single vocabulary.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill: for any existing alias, make sure a User row exists with
        //    that telegram_user_id, then we can fk to it.
        $telegramIds = DB::table('landing_aliases')
            ->whereNotNull('created_by_user_id')
            ->pluck('created_by_user_id')
            ->unique()
            ->all();

        $idMap = [];
        foreach ($telegramIds as $tgId) {
            $user = User::query()->firstOrCreate(
                ['telegram_user_id' => (string) $tgId],
                ['settings' => '{}'],
            );
            $idMap[(string) $tgId] = $user->id;
        }

        // 2. Add the new FK column.
        Schema::table('landing_aliases', function (Blueprint $table) {
            $table->foreignId('created_by_id')
                ->nullable()
                ->after('position')
                ->constrained('users')
                ->nullOnDelete();
        });

        // 3. Migrate the data.
        foreach ($idMap as $tgId => $userId) {
            DB::table('landing_aliases')
                ->where('created_by_user_id', $tgId)
                ->update(['created_by_id' => $userId]);
        }

        // 4. Drop the legacy column.
        Schema::table('landing_aliases', function (Blueprint $table) {
            $table->dropColumn('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('landing_aliases', function (Blueprint $table) {
            $table->string('created_by_user_id', 32)->nullable()->after('position');
        });

        // Reverse the FK→tg-id mapping where we can.
        DB::statement('
            UPDATE landing_aliases la
            SET created_by_user_id = u.telegram_user_id
            FROM users u
            WHERE la.created_by_id = u.id
        ');

        Schema::table('landing_aliases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
        });
    }
};
