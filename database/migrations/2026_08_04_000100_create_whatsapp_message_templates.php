<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_cloud_configs', function (Blueprint $table) {
            $table->string('app_id')->nullable()->after('phone_number_id');
        });

        Schema::create('whatsapp_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_cloud_config_id')->constrained()->cascadeOnDelete();
            $table->string('meta_template_id')->nullable()->unique();
            $table->string('name', 512);
            $table->string('language', 35);
            $table->string('category', 32);
            $table->string('parameter_format', 32)->nullable();
            $table->json('components');
            $table->string('status', 32)->index();
            $table->string('quality_score', 32)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('remote_created_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['whatsapp_cloud_config_id', 'name', 'language'], 'wa_template_config_name_language_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_templates');

        Schema::table('whatsapp_cloud_configs', function (Blueprint $table) {
            $table->dropColumn('app_id');
        });
    }
};
