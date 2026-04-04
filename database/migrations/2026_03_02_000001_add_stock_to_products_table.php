<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // code تمت إضافتها في migration سابق
            $table->string('category', 100)->nullable()->after('name');
            $table->integer('stock_quantity')->default(0)->after('default_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['category', 'stock_quantity']);
        });
    }
};

