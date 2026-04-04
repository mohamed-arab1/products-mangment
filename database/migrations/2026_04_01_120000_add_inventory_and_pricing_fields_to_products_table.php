<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('purchase_price', 12, 2)->nullable()->after('default_price');
            $table->date('production_date')->nullable()->after('purchase_price');
            $table->unsignedInteger('shelf_life_days')->nullable()->after('production_date');
            $table->date('expiry_date')->nullable()->after('shelf_life_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['purchase_price', 'production_date', 'shelf_life_days', 'expiry_date']);
        });
    }
};
