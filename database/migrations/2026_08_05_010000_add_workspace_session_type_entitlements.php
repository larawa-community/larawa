<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('allows_official_cloud_api')->default(true)->after('slug');
            $table->boolean('allows_whatsapp_wrapper')->default(true)->after('allows_official_cloud_api');
        });

        DB::table('workspaces')->update([
            'allows_official_cloud_api' => true,
            'allows_whatsapp_wrapper' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['allows_official_cloud_api', 'allows_whatsapp_wrapper']);
        });
    }
};
