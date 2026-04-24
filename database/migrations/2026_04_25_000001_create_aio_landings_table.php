<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aio_landings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64)->unique();
            $table->integer('human_id')->nullable()->index();
            $table->string('name', 500);
            $table->string('landing_type_uuid', 64)->nullable()->index();
            $table->string('landing_type_name')->nullable();
            $table->string('owner_uuid', 64)->nullable()->index();
            $table->string('owner_name')->nullable();
            $table->jsonb('countries')->default('[]');
            $table->boolean('is_archived')->default(false);
            $table->jsonb('mvt_settings')->nullable();
            $table->jsonb('raw');
            $table->timestampTz('aio_created_at')->nullable();
            $table->timestampTz('synced_at')->useCurrent();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aio_landings');
    }
};
