<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->text('path');
            $table->longText('body')->nullable();
            $table->boolean('truncated')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response')->nullable();
            $table->json('routing')->nullable();
            $table->foreignId('receipt_id')->nullable()->constrained('meta_webhook_receipts')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_webhook_logs');
    }
};
