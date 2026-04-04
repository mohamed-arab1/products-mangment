<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }

    public function down(): void
    {
        // Intentionally empty — table was removed with WhatsApp Business API integration.
    }
};
