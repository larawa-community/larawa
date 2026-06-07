<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('remember_token');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('slug');
            $table->softDeletes();
        });

        DB::table('workspace_users')
            ->where('role', 'owner')
            ->update(['role' => 'site_admin']);

        Schema::table('workspace_users', function (Blueprint $table) {
            $table->string('role')->default('workspace_user')->change();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_users', function (Blueprint $table) {
            $table->string('role')->default('owner')->change();
        });

        DB::table('workspace_users')
            ->where('role', 'site_admin')
            ->update(['role' => 'owner']);

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('suspended_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
