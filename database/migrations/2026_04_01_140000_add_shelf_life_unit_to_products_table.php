<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('shelf_life_value')->nullable()->after('shelf_life_days');
            $table->string('shelf_life_unit', 16)->nullable()->after('shelf_life_value');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['shelf_life_value', 'shelf_life_unit']);
        });
    }
};
