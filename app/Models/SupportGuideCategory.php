<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'title', 'description', 'icon', 'sort_order', 'is_active', 'visible_to', 'show_in_superadmin_help'])]
class SupportGuideCategory extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_in_superadmin_help' => 'boolean',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(SupportGuideArticle::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
