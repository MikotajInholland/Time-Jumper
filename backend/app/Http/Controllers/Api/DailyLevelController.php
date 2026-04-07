<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProceduralMapGenerator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyLevelController extends Controller
{
    
     // Returns the daily level (same seed for everyone by date). Optional ?seed= for custom seed.
    
    public function show(Request $request): JsonResponse
    {
        try {
            $seed = $request->has('seed')
                ? (int) $request->query('seed')
                : ProceduralMapGenerator::dateSeed(Carbon::today());

            $generator = new ProceduralMapGenerator($seed);
            $data = $generator->generate();

            return response()->json([
                'seed' => $seed,
                'date' => Carbon::today()->toDateString(),
                'past' => $data['past'],
                'future' => $data['future'],
                'start' => $data['start'],
                'checkpoints' => $data['checkpoints'],
                'goldenPath' => $data['goldenPath'],
            ]);
        } catch (\Throwable $e) {
            \Log::error('Daily level error: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to generate daily level.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
