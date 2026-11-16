<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['course_id', 'actor_id', 'action', 'version', 'demande_ids', 'command_key', 'created_at'];

    protected function casts(): array
    {
        return ['demande_ids' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
