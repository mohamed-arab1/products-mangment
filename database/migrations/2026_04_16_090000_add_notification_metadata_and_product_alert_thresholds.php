<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->after('type');
            $table->string('channel', 50)->default('in_app')->after('message');
            $table->index(['user_id', 'type', 'dedupe_key'], 'app_notifications_dedupe_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->default(5)->after('stock_quantity');
            $table->unsignedInteger('expiry_alert_days')->default(7)->after('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex('app_notifications_dedupe_idx');
            $table->dropColumn(['dedupe_key', 'channel']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['low_stock_threshold', 'expiry_alert_days']);
        });
    }
};
