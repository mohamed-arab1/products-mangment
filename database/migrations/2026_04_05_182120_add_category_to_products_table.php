<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No-op: category was added in an earlier migration.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op to avoid dropping a shared, already-existing column.
    }
};
