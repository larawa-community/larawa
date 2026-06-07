<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'database' => 'unreachable',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'database' => 'ok',
            'connection' => config('database.default'),
        ]);
    }
}
