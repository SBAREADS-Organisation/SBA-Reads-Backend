<?php

namespace App\Services\Category;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class CategoryService
{
    /**
     * Create or update a category.
     */
    public function save(array $data, ?Category $category = null): Category
    {
        // Validate parent_id to ensure it doesn't create a circular reference
        if (isset($data['parent_id']) && $data['parent_id']) {
            $parentCategory = Category::find($data['parent_id']);
            if (! $parentCategory) {
                throw new \InvalidArgumentException('Invalid parent_id provided.');
            }

            // Prevent circular reference (a category cannot be its own parent)
            if ($category && $category->id === $data['parent_id']) {
                throw new \InvalidArgumentException('A category cannot be its own parent.');
            }
        }

        // Add the current user's ID to the data for the `created_by` field
        if (! isset($data['created_by'])) {
            $data['created_by'] = Auth::id();
        }

        // Ensure the image field is properly formatted as JSON
        if (isset($data['image']) && is_array($data['image'])) {
            $data['image'] = json_encode($data['image']);
        }

        // Create or update the category
        $category = $category
            ? tap($category)->update($data)
            : Category::create($data);

        // Handle tree reordering if parent_id changes
        if ($category->wasChanged('parent_id')) {
            $this->reorderTree($category);
        }

        return $category;
    }

    /**
     * Handle tree reordering when parent_id changes.
     */
    protected function reorderTree(Category $category): void
    {
        $parent = $category->parent;
        if ($parent) {
            $category->order = $parent->children()->count();
            $category->save();
        }
    }

    /**
     * Return full tree of categories.
     */
    public function allTree(): Collection
    {
        // handle not found
        if (! Category::exists()) {
            return collect([]);
        }

        return Category::with('children')->whereNull('parent_id')->get();
    }
}
