<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aio_users', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->jsonb('raw');
            $table->timestampTz('aio_created_at')->nullable();
            $table->timestampTz('synced_at')->useCurrent();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aio_users');
    }
};
