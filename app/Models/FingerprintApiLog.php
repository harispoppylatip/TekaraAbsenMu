<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FingerprintApiLog extends Model
{
    protected $fillable = [
        'action',
        'device_id',
        'user_identifier',
        'matched_user_id',
        'template_length',
        'template_nonzero_bytes',
        'template_hash',
        'result_status',
        'message',
        'http_status',
    ];

    public function matchedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_user_id');
    }
}
