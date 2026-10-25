<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        try {
            $row = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'database' => 'unavailable',
                'detail' => $exception->getMessage(),
            ], 503);
        }

        if ($row === null) {
            return response()->json(['status' => 'error', 'database' => 'ok', 'vector' => 'missing'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'database' => 'ok',
            'vector' => $row->extversion,
        ]);
    }
}
