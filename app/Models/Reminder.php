<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * due_date es "cuando vence esta vez" - se puede posponer sin mas
 * consecuencia. series_anchor_date es el ancla de la recurrencia: solo
 * ReminderService::complete() la mueve, nunca postpone(). Asi, posponer una
 * ocurrencia no corre la cadencia de las siguientes - ver ReminderService.
 */
#[Fillable([
    'business_id',
    'created_by_user_id',
    'title',
    'notes',
    'due_date',
    'notify_time',
    'notify_whatsapp',
    'last_notified_on',
    'series_anchor_date',
    'recurrence',
    'end_date',
    'status',
    'completed_at',
    'last_completed_at',
    'remindable_type',
    'remindable_id',
])]
class Reminder extends Model
{
    use BelongsToBusiness, HasFactory;

    const RECURRENCES = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

    const STATUS_PENDING = 'pending';

    const STATUS_DONE = 'done';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'series_anchor_date' => 'date',
            'end_date' => 'date',
            'notify_whatsapp' => 'boolean',
            'last_notified_on' => 'date',
            'completed_at' => 'datetime',
            'last_completed_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== 'none';
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->due_date->isPast() && ! $this->due_date->isToday();
    }
}
