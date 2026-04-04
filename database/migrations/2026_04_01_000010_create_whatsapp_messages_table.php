<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 10); // inbound | outbound
            $table->string('customer_phone', 40)->index();
            $table->string('customer_name')->nullable();
            $table->string('whatsapp_message_id')->nullable()->index();
            $table->text('body')->nullable();
            $table->string('status', 40)->nullable(); // sent | delivered | read | failed
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
