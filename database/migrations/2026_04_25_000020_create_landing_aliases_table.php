<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 64);
            $table->string('landing_uuid', 64);
            $table->unsignedTinyInteger('position')->default(1);
            $table->string('created_by_user_id', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique('alias');
            $table->index(['landing_uuid', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_aliases');
    }
};
