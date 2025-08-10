<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Http\Resources\Category\CategoryResource;
use App\Models\Category;
use App\Services\Category\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    protected CategoryService $service;

    public function __construct(CategoryService $service)
    {
        // $this->middleware('auth:sanctum');
        // $this->middleware('role:admin')->except(['index','show']);
        $this->service = $service;
    }

    // GET /categories
    public function index()
    {
        try {
            $tree = $this->service->allTree();

            if ($tree->isEmpty()) {
                return $this->error('No categories found', 404, null);
            }

            return $this->success(
                CategoryResource::collection($tree),
                'Categories retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve categories', 500, null, $e);
        }
    }

    // GET /categories/{id}
    public function show(Category $category)
    {
        try {
            if (! $category) {
                return $this->error('Category not found', 404, null);
            }

            $category->loadCount('children');
            $category->load('children');

            return $this->success(
                new CategoryResource($category),
                'Category retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve category', 500, null, $e);
        }
    }

    // POST /categories
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'categories' => 'required|array',
                'categories.*.name' => 'required|string|unique:categories,name',
                'categories.*.parent_id' => 'nullable|exists:categories,id',
                'categories.*.description' => 'required|string',
                'categories.*.image' => 'nullable|array',
                'categories.*.image.url' => 'nullable|url',
                'categories.*.image.public_id' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            $categories = [];
            foreach ($request->categories as $data) {
                $data['slug'] = str_replace(' ', '-', strtolower($data['name']));
                $categories[] = $this->service->save($data);
            }

            return $this->success(
                CategoryResource::collection($categories),
                'Categories created successfully',
                201
            );
        } catch (\Exception $e) {
            return $this->error('Failed to create categories', 500, null, $e);
        }
    }

    // PUT /categories/{id}
    public function update(Request $request, Category $category)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => "required|string|unique:categories,name,{$category->id}",
                'parent_id' => 'nullable|exists:categories,id',
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            $data = $validator->validated();
            $category = $this->service->save($data, $category);

            return $this->success(
                new CategoryResource($category),
                'Category updated successfully',
                200
            );
        } catch (\Exception $e) {
            return $this->error('Failed to update category', 500, null, $e);
        }
    }

    // DELETE /categories/{id}
    public function destroy(Category $category)
    {
        try {
            $category->delete();

            return $this->success(
                null,
                'Category deleted successfully',
                204
            );
        } catch (\Exception $e) {
            return $this->error('Failed to delete category', 500, null, $e);
        }
    }
}
