<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->string('type')->default('whatsapp_wrapper')->index()->after('name');
            $table->foreignId('fallback_session_id')->nullable()->after('type')->constrained('whatsapp_sessions')->nullOnDelete();
        });

        Schema::create('whatsapp_cloud_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('waba_id');
            $table->string('phone_number_id')->unique();
            $table->text('access_token');
            $table->text('app_secret');
            $table->timestamps();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('transport_session_id')->nullable()->after('whatsapp_session_id')->constrained('whatsapp_sessions')->nullOnDelete();
        });

        Schema::table('message_fallback_attempts', function (Blueprint $table) {
            $table->foreignId('target_whatsapp_session_id')->nullable()->after('whatsapp_session_id')->constrained('whatsapp_sessions')->nullOnDelete();
            $table->string('provider_message_id')->nullable()->index()->after('channel');
        });

        Schema::create('meta_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('payload_hash', 64)->unique();
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_webhook_receipts');

        Schema::table('message_fallback_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_whatsapp_session_id');
            $table->dropColumn('provider_message_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transport_session_id');
        });

        Schema::dropIfExists('whatsapp_cloud_configs');

        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fallback_session_id');
            $table->dropColumn('type');
        });
    }
};
