<?php

namespace App\Support;

use App\Models\Workspace;
use Illuminate\Support\Str;

class WorkspaceIds
{
    public static function generate(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            do {
                $id = (string) Str::uuid();
            } while (Workspace::withTrashed()->where('slug', $id)->exists());

            return $id;
        }

        do {
            $id = $base.'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Workspace::withTrashed()->where('slug', $id)->exists());

        return $id;
    }

    public static function generateDefault(string $name): string
    {
        return self::generate($name);
    }
}
