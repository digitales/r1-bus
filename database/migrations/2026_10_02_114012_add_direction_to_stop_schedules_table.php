<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stop_schedules', function (Blueprint $table) {
            $table->string('direction')->default('outward')->after('slot');
            $table->dropUnique(['slot']);
            $table->unique(['slot', 'direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('stop_schedules')->where('direction', '!=', 'outward')->delete();

        Schema::table('stop_schedules', function (Blueprint $table) {
            $table->dropUnique(['slot', 'direction']);
            $table->dropColumn('direction');
            $table->unique('slot');
        });
    }
};
