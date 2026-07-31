<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_cloud_configs', function (Blueprint $table) {
            $table->string('waba_id')->nullable()->change();
            $table->string('phone_number_id')->nullable()->change();
            $table->text('access_token')->nullable()->change();
            $table->text('app_secret')->nullable()->change();
        });

        $now = now();
        DB::table('whatsapp_sessions')
            ->where('type', 'official_cloud_api')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('whatsapp_cloud_configs')
                    ->whereColumn('whatsapp_cloud_configs.whatsapp_session_id', 'whatsapp_sessions.id');
            })
            ->orderBy('id')
            ->eachById(function ($session) use ($now) {
                DB::table('whatsapp_cloud_configs')->insert([
                    'whatsapp_session_id' => $session->id,
                    'waba_id' => null,
                    'phone_number_id' => null,
                    'access_token' => null,
                    'app_secret' => null,
                    'verify_token' => Crypt::encryptString(Str::random(64)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('whatsapp_cloud_configs')->whereNull('waba_id')->update(['waba_id' => '']);
        DB::table('whatsapp_cloud_configs')->whereNull('phone_number_id')->update(['phone_number_id' => '']);
        DB::table('whatsapp_cloud_configs')->whereNull('access_token')->update(['access_token' => '']);
        DB::table('whatsapp_cloud_configs')->whereNull('app_secret')->update(['app_secret' => '']);

        Schema::table('whatsapp_cloud_configs', function (Blueprint $table) {
            $table->string('waba_id')->nullable(false)->change();
            $table->string('phone_number_id')->nullable(false)->change();
            $table->text('access_token')->nullable(false)->change();
            $table->text('app_secret')->nullable(false)->change();
        });
    }
};
