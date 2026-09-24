<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The trainee's current level in one skill, rolled forward by each session. */
class TraineeSkillEvaluation extends Model
{
    protected $fillable = [
        'trainee_id', 'training_skill_id', 'level',
        'last_session_id', 'evaluated_by', 'evaluated_at', 'note',
    ];

    protected function casts(): array
    {
        return ['evaluated_at' => 'datetime'];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(TrainingSkill::class, 'training_skill_id');
    }

    public function lastSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'last_session_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    public function score(): int
    {
        return TrainingSkill::levelScore($this->level);
    }
}
