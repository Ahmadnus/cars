<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingSessionSkill extends Model
{
    protected $fillable = ['training_session_id', 'training_skill_id', 'rating', 'note'];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(TrainingSkill::class, 'training_skill_id');
    }
}
