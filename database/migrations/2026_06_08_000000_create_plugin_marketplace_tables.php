<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('dashboard_locale', 16)->default('en')->after('password');
        });

        Schema::create('installed_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_id')->unique();
            $table->string('name');
            $table->string('version');
            $table->string('type')->index();
            $table->text('description')->nullable();
            $table->string('required_core_version')->default('*');
            $table->boolean('license_required')->default(false);
            $table->string('status')->default('disabled')->index();
            $table->string('license_status')->default('active')->index();
            $table->text('manifest_path')->nullable();
            $table->text('base_path')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('last_discovered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('plugin_settings', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_id');
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['plugin_id', 'key']);
            $table->foreign('plugin_id')->references('plugin_id')->on('installed_plugins')->cascadeOnDelete();
        });

        Schema::create('plugin_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_id')->unique();
            $table->text('license_key')->nullable();
            $table->string('status')->default('invalid')->index();
            $table->text('message')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->foreign('plugin_id')->references('plugin_id')->on('installed_plugins')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_licenses');
        Schema::dropIfExists('plugin_settings');
        Schema::dropIfExists('installed_plugins');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_locale');
        });
    }
};
