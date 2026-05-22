<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the landing-alias concept. Phase K of the redesign — /stats now takes
 * the AIO primitive (country code, source, campaign, …) directly, so pretty
 * names for landings became dead weight.
 *
 * The reverse migration recreates the table empty — no attempt to backfill
 * data on rollback, since the surrounding code that wrote aliases is also
 * being deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('landing_aliases');
    }

    public function down(): void
    {
        Schema::create('landing_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 64);
            $table->string('landing_uuid', 64);
            $table->unsignedTinyInteger('position')->default(1);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique('alias');
            $table->index(['landing_uuid', 'position']);
        });
    }
};
