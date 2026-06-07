<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('messages')
            ->select('workspace_id', 'wa_message_id')
            ->whereNotNull('wa_message_id')
            ->groupBy('workspace_id', 'wa_message_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('messages')
                ->where('workspace_id', $duplicate->workspace_id)
                ->where('wa_message_id', $duplicate->wa_message_id)
                ->orderBy('id')
                ->pluck('id')
                ->skip(1)
                ->values();

            if ($ids->isNotEmpty()) {
                DB::table('messages')->whereIn('id', $ids)->update(['wa_message_id' => null]);
            }
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['wa_message_id']);
            $table->unique(['workspace_id', 'wa_message_id'], 'messages_workspace_wa_message_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_workspace_wa_message_unique');
            $table->index('wa_message_id');
        });
    }
};
