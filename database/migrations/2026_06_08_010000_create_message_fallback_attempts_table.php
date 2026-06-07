<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_fallback_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plugin_id')->nullable()->index();
            $table->string('provider_key');
            $table->string('channel')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->string('trigger_source')->nullable()->index();
            $table->json('original_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->string('exception_class')->nullable();
            $table->text('exception_message')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'provider_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_fallback_attempts');
    }
};
