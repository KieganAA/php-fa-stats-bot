<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aio_fields', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64)->unique();
            $table->string('data_source')->nullable();
            $table->string('group')->nullable();
            $table->string('field')->nullable();
            $table->string('format')->nullable();
            $table->string('slug')->nullable()->index();
            $table->string('ch_column')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('access_type')->nullable();
            $table->jsonb('raw');
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aio_fields');
    }
};
