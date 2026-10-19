<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_app_settings', function (Blueprint $table) {
            $table->string('app_id', 120)->nullable();
            $table->text('app_secret')->nullable();
            $table->boolean('credentials_initialized')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('meta_app_settings', fn (Blueprint $table) => $table->dropColumn(['app_id', 'app_secret', 'credentials_initialized']));
    }
};
