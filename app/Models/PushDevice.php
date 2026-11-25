<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class PushDevice extends Model
{
    protected $hidden = ['token', 'token_hash', 'personal_access_token_id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'personal_access_token_id' => 'integer',
            'token' => 'encrypted',
        ];
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }
}
