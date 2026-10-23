<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('whatsapp_cloud_configs')
            ->whereIn('whatsapp_session_id', DB::table('whatsapp_sessions')->select('id')->whereNotNull('deleted_at'))
            ->update(['phone_number_id' => null]);
    }

    public function down(): void
    {
        // Released IDs may have been assigned to new sessions and cannot be restored.
    }
};
