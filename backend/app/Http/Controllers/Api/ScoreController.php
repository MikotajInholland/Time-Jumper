<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyScore;
use App\Services\ProceduralMapGenerator;
use App\Services\ReplayValidator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScoreController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'replay' => 'required|array',
                'replay.inputs' => 'required|array',
                'replay.inputs.*' => 'array|min:2|max:3',
                'replay.inputs.*.0' => 'required_with:replay.inputs.*|integer|min:0',
                'replay.inputs.*.1' => ['required_with:replay.inputs.*', 'string', Rule::in(['F', 'B', 'L', 'R', 'J', 'T', 'SL', 'SR', 'SC', 'M'])],
                'replay.inputs.*.2' => 'nullable|numeric',
                'replay.claimedTimeMs' => 'required|integer|min:1|max:600000',
                'replay.frameCount' => 'sometimes|nullable|integer|min:1|max:120000',
                'replay.frameBoundaryMs' => 'sometimes|nullable|array|max:120000',
                'replay.frameBoundaryMs.*' => 'integer|min:0|max:600000',
                'replay.frameStepMs' => 'sometimes|nullable|array|max:120000',
                'replay.frameStepMs.*' => 'numeric|min:0|max:600000',
                'seed' => 'required|integer',
            ]);
        } catch (ValidationException $e) {
            Log::warning('scores.store Laravel validation failed', [
                'user_id' => $request->user()?->id,
                'errors' => $e->errors(),
                'payload_summary' => [
                    'seed' => $request->input('seed'),
                    'claimedTimeMs' => $request->input('replay.claimedTimeMs'),
                    'input_count' => is_array($request->input('replay.inputs')) ? count($request->input('replay.inputs')) : null,
                    'frameCount' => $request->input('replay.frameCount'),
                ],
            ]);
            throw $e;
        }

        $seed = (int) $request->input('seed');
        $date = Carbon::today();
        $expectedSeed = ProceduralMapGenerator::dateSeed($date);
        if ($seed !== $expectedSeed) {
            return response()->json(['message' => 'Invalid seed for today\'s daily.'], 422);
        }

        $generator = new ProceduralMapGenerator($seed);
        $level = $generator->generate();
        $levelForValidation = [
            'past' => $level['past'],
            'future' => $level['future'],
            'start' => $level['start'],
        ];

        $replayPayload = $request->input('replay');
        $replayResult = (new ReplayValidator())->validateDetailed($levelForValidation, $replayPayload);
        if (!$replayResult['ok']) {
            Log::warning('scores.store replay simulation failed', [
                'user_id' => $request->user()?->id,
                'seed' => $seed,
                'code' => $replayResult['code'],
                'message' => $replayResult['message'],
                'context' => $replayResult['context'],
                'replay_summary' => [
                    'input_count' => is_array($replayPayload['inputs'] ?? null) ? count($replayPayload['inputs']) : null,
                    'claimedTimeMs' => $replayPayload['claimedTimeMs'] ?? null,
                    'frameCount' => $replayPayload['frameCount'] ?? null,
                ],
            ]);

            return response()->json([
                'message' => sprintf(
                    'Replay validation failed: %s — %s',
                    $replayResult['code'],
                    $replayResult['message']
                ),
                'replay_error' => [
                    'code' => $replayResult['code'],
                    'message' => $replayResult['message'],
                    'context' => $replayResult['context'],
                ],
            ], 422);
        }

        $user = $request->user();
        $claimedTimeMs = (int) $request->input('replay.claimedTimeMs');

        $existing = DailyScore::where('user_id', $user->id)->whereDate('date', $date)->first();
        if ($existing && $existing->completion_time_ms <= $claimedTimeMs) {
            return response()->json([
                'message' => 'You already have a better or equal time.',
                'score' => $existing->completion_time_ms,
            ], 200);
        }

        $replayHash = hash('sha256', json_encode($request->input('replay')));

        if ($existing) {
            $existing->update([
                'completion_time_ms' => $claimedTimeMs,
                'replay_hash' => $replayHash,
            ]);
            $score = $existing;
        } else {
            $score = DailyScore::create([
                'user_id' => $user->id,
                'date' => $date,
                'seed' => $seed,
                'completion_time_ms' => $claimedTimeMs,
                'replay_hash' => $replayHash,
            ]);
        }

        return response()->json([
            'message' => 'Score saved.',
            'completion_time_ms' => $score->completion_time_ms,
        ], 201);
    }
}
