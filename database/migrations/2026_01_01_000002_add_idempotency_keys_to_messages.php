<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('wa_message_id');
            $table->unique(['workspace_id', 'idempotency_key'], 'messages_workspace_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_workspace_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
