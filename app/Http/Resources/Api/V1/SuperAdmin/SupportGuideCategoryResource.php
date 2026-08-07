<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportGuideCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'visible_to' => $this->visible_to,
            'show_in_superadmin_help' => $this->show_in_superadmin_help,
            'articles' => SupportGuideArticleResource::collection($this->whenLoaded('articles')),
        ];
    }
}
