<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_app_settings', fn (Blueprint $table) => $table->text('provider_access_token')->nullable());
        Schema::table('whatsapp_cloud_configs', fn (Blueprint $table) => $table->boolean('use_shared_token')->default(false));
    }

    public function down(): void
    {
        Schema::table('whatsapp_cloud_configs', fn (Blueprint $table) => $table->dropColumn('use_shared_token'));
        Schema::table('meta_app_settings', fn (Blueprint $table) => $table->dropColumn('provider_access_token'));
    }
};
