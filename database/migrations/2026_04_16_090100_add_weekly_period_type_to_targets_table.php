<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE targets MODIFY period_type ENUM('daily','weekly','monthly') NOT NULL");

            return;
        }

        // PostgreSQL/SQLite fallback (rebuild enum column safely).
        Schema::table('targets', function (Blueprint $table) {
            $table->string('period_type_tmp')->nullable()->after('period_type');
        });

        DB::table('targets')->update(['period_type_tmp' => DB::raw('period_type')]);

        Schema::table('targets', function (Blueprint $table) {
            $table->dropColumn('period_type');
        });

        Schema::table('targets', function (Blueprint $table) {
            $table->enum('period_type', ['daily', 'weekly', 'monthly'])->default('daily')->after('target_amount');
        });

        DB::table('targets')->update(['period_type' => DB::raw('period_type_tmp')]);

        Schema::table('targets', function (Blueprint $table) {
            $table->dropColumn('period_type_tmp');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE targets MODIFY period_type ENUM('daily','monthly') NOT NULL");

            return;
        }

        Schema::table('targets', function (Blueprint $table) {
            $table->string('period_type_tmp')->nullable()->after('period_type');
        });

        DB::table('targets')->where('period_type', 'weekly')->update(['period_type' => 'daily']);
        DB::table('targets')->update(['period_type_tmp' => DB::raw('period_type')]);

        Schema::table('targets', function (Blueprint $table) {
            $table->dropColumn('period_type');
        });

        Schema::table('targets', function (Blueprint $table) {
            $table->enum('period_type', ['daily', 'monthly'])->default('daily')->after('target_amount');
        });

        DB::table('targets')->update(['period_type' => DB::raw('period_type_tmp')]);

        Schema::table('targets', function (Blueprint $table) {
            $table->dropColumn('period_type_tmp');
        });
    }
};
