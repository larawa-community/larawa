<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('workspace_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('created')->index();
            $table->string('phone_number')->nullable();
            $table->text('qr_code')->nullable();
            $table->timestamp('qr_expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16)->index();
            $table->string('key_hash', 64)->unique();
            $table->json('scopes');
            $table->json('ip_allow_list')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wa_message_id')->nullable()->index();
            $table->string('direction')->index();
            $table->string('type')->default('text')->index();
            $table->string('status')->default('queued')->index();
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('url');
            $table->text('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('whatsapp_sessions');
        Schema::dropIfExists('workspace_users');
        Schema::dropIfExists('workspaces');
    }
};
