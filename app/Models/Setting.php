<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'slug', 'value', 'description', 'editable'])]
class Setting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'editable' => 'boolean',
        ];
    }

    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
