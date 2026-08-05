<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'type', 'to_email', 'subject', 'status', 'error'])]
class EmailLog extends Model
{
    use HasFactory;

    const STATUS_SENT = 'sent';

    const STATUS_FAILED = 'failed';

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
