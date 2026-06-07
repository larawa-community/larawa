<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Support\WorkspaceIds;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Workspace::firstOrCreate(
            ['name' => config('larawa.default_workspace')],
            ['slug' => WorkspaceIds::generateDefault((string) config('larawa.default_workspace'))]
        );
    }
}
