<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un usuario ya vio y cerro una difusion: no se le vuelve a mostrar. */
#[Fillable(['announcement_id', 'user_id', 'dismissed_at'])]
class AnnouncementDismissal extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
