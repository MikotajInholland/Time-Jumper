<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyScore extends Model
{
    protected $fillable = ['user_id', 'date', 'seed', 'completion_time_ms', 'replay_hash'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'completion_time_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
