<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_landing_fields', function (Blueprint $table) {
            $table->foreignId('tracked_landing_id')->constrained('tracked_landings')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('aio_fields')->cascadeOnDelete();
            $table->timestampsTz();

            $table->primary(['tracked_landing_id', 'field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_landing_fields');
    }
};
