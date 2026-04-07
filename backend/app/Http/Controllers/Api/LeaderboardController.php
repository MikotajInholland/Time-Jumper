<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyScore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    
     // Top scores for the daily level. ?date=YYYY-MM-DD optional.
     
    public function index(Request $request): JsonResponse
    {
        $date = $request->has('date')
            ? Carbon::parse($request->query('date'))
            : Carbon::today();

        $rows = DailyScore::query()
            ->whereDate('date', $date)
            ->with('user:id,name')
            ->orderBy('completion_time_ms')
            ->limit(100)
            ->get();

        $leaderboard = [];
        $rank = 1;
        foreach ($rows as $s) {
            $leaderboard[] = [
                'rank' => $rank++,
                'user_name' => $s->user->name,
                'completion_time_ms' => $s->completion_time_ms,
            ];
        }

        return response()->json([
            'date' => $date->toDateString(),
            'leaderboard' => $leaderboard,
        ]);
    }
}
