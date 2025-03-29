<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class CategoryController extends Controller
{
    use ResponseTrait;
    public function index(Request $request)
    {
        try {
            $categories = Category::with('addedBy')
                ->ordered()
                ->when(!$request->user() || !$request->user()->hasRole('admin'), function ($query) {
                    return $query->active();
                })
                ->get();

            return $this->success($categories, 'Categories fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch categories', 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|array',
            'name.en' => 'required|string|max:255',
            'name.ar' => 'required|string|max:255',
            'description' => 'sometimes|array',
            'description.en' => 'sometimes|string',
            'description.ar' => 'sometimes|string',
            'icon' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'order' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        try {
            $iconPath = $request->file('icon')->store('public/category_icons');
            $iconUrl = Storage::url($iconPath);

            $category = Category::create([
                'name' => [
                    'en' => $request->input('name.en'),
                    'ar' => $request->input('name.ar')
                ],
                'description' => [
                    'en' => $request->input('description.en', ''),
                    'ar' => $request->input('description.ar', '')
                ],
                'icon' => $iconUrl,
                'order' => $request->order ?? 0,
                'added_by' => auth()->id(),
                'is_active' => true
            ]);

            return $this->success($category, 'Category created successfully', 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create category', 500);
        }
    }

    public function show($id)
    {
        try {
            $category = Category::with(['services', 'addedBy'])->find($id);

            if (!$category) {
                return $this->error('Category not found', 404);
            }

            return $this->success($category);
        } catch (\Exception $e) {
            return $this->error('Failed to fetch categories', 500);
        }
    }

    public function update(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return $this->error('Category not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|array',
            'name.en' => 'sometimes|string|max:255',
            'name.ar' => 'sometimes|string|max:255',
            'description' => 'sometimes|array',
            'description.en' => 'sometimes|string',
            'description.ar' => 'sometimes|string',
            'icon' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        try {
            $updateData = [
                'name' => [
                    'en' => $request->input('name.en', $category->name['en']),
                    'ar' => $request->input('name.ar', $category->name['ar'])
                ],
                'description' => [
                    'en' => $request->input('description.en', $category->description['en']),
                    'ar' => $request->input('description.ar', $category->description['ar'])
                ],
                'order' => $request->input('order', $category->order),
                'is_active' => $request->input('is_active', $category->is_active)
            ];

            if ($request->hasFile('icon')) {
                $oldIconPath = str_replace('/storage', 'public', $category->icon);
                Storage::delete($oldIconPath);

                $iconPath = $request->file('icon')->store('public/category_icons');
                $updateData['icon'] = Storage::url($iconPath);
            }

            $category->update($updateData);

            return $this->success($category, 'Category updated successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to update category', 500);
        }
    }

    public function destroy($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->error('Category not found', 404);
            }

            if ($category->icon) {
                $iconPath = str_replace('/storage', 'public', $category->icon);
                Storage::delete($iconPath);
            }

            $category->delete();

            return $this->success(null, 'Category deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete category', 500);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->error('Category not found', 404);
            }

            $category->update([
                'is_active' => !$category->is_active
            ]);

            return $this->success(
                ['is_active' => $category->is_active],
                'Category status updated'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to change category status', 500);
        }
    }


}