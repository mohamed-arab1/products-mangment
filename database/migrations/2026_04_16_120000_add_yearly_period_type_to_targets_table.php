<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE targets MODIFY period_type ENUM('daily','weekly','monthly','yearly') NOT NULL");

            return;
        }

        // PostgreSQL/SQLite: convert to string column to support extensible period values.
        DB::statement("ALTER TABLE targets ALTER COLUMN period_type TYPE VARCHAR(20) USING period_type::text");
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE targets MODIFY period_type ENUM('daily','weekly','monthly') NOT NULL");

            return;
        }

        // Keep as string for non-MySQL databases to avoid destructive enum recreation.
    }
};
