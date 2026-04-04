<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_status')->default('pending')->after('sale_date');
            $table->string('payment_method')->nullable()->after('payment_status');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('total');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('sale_type')->nullable()->after('description');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['sale_type', 'discount_amount']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'payment_method', 'discount_amount']);
        });
    }
};

