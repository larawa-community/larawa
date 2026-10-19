<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_app_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('verify_token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_app_settings');
    }
};
