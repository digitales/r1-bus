<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stop_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('slot')->unique();
            $table->string('naptan_id');
            $table->string('name');
            $table->string('stop_letter')->nullable();
            $table->string('towards')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stop_schedules');
    }
};
