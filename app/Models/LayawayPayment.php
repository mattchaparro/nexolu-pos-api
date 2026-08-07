<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\NormalizesPaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LayawayPayment extends Model
{
    use BelongsToBusiness, HasFactory, NormalizesPaymentMethod;

    protected $fillable = [
        'layaway_id',
        'business_id',
        'amount',
        'payment_method',
        'notes',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function layaway(): BelongsTo
    {
        return $this->belongsTo(Layaway::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
