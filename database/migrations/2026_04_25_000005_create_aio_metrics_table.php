<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aio_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64)->unique();
            $table->string('name')->nullable()->index();
            $table->string('format')->nullable();
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('raw');
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aio_metrics');
    }
};
