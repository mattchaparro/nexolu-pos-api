<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use BelongsToBranch, BelongsToBusiness, HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'client_id',
        'product_id',
        'user_id',
        'client_name',
        'client_phone',
        'client_email',
        'starts_at',
        'ends_at',
        'status',
        'notes',
        'reminder_sent',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminder_sent' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function serviceOrder(): HasOne
    {
        return $this->hasOne(ServiceOrder::class);
    }

    public static function statuses(): array
    {
        return ['pending', 'confirmed', 'completed', 'cancelled'];
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pendiente',
            'confirmed' => 'Confirmada',
            'completed' => 'Completada',
            'cancelled' => 'Cancelada',
            default => $this->status,
        };
    }
}
