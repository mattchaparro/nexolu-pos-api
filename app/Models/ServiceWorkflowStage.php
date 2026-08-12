<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workflow_id', 'label', 'color', 'sort_order', 'is_initial', 'actions'])]
class ServiceWorkflowStage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_initial' => 'boolean',
            'actions' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ServiceWorkflow::class, 'workflow_id');
    }

    public function hasAction(string $type): bool
    {
        return collect($this->actions ?? [])->contains('type', $type);
    }
}
