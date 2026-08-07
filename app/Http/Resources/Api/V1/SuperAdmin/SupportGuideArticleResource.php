<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportGuideArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'support_guide_category_id' => $this->support_guide_category_id,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'image_path' => $this->image_path,
            'suggested_route' => $this->suggested_route,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'module_feature' => $this->module_feature,
            'visible_to' => $this->visible_to,
        ];
    }
}
