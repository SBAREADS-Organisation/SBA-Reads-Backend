<?php

namespace App\Http\Resources\Category;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => $this->image,
            'order' => $this->order,
            'is_active' => $this->is_active,
            // 'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'children_count' => $this->whenLoaded('children_count'),
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
