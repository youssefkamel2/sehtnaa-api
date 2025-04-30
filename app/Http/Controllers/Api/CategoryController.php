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
            $user = auth()->user();

            $categories = Category::with('addedBy')
                ->ordered()
                ->when($user->user_type !== 'admin', function ($query) {
                    return $query->where('is_active', true);
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
            'is_multiple' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        try {
            $iconPath = $request->file('icon')->store('category_icons', 'public');;

            $category = Category::create([
                'name' => [
                    'en' => $request->input('name.en'),
                    'ar' => $request->input('name.ar')
                ],
                'description' => [
                    'en' => $request->input('description.en', ''),
                    'ar' => $request->input('description.ar', '')
                ],
                'icon' => $iconPath,
                'order' => $request->order ?? 0,
                'added_by' => auth()->id(),
                'is_active' => true,
                'is_multiple' => $request->is_multiple ?? false,
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
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|array',
            'name.en' => 'sometimes|string|max:255',
            'name.ar' => 'sometimes|string|max:255',
            'description' => 'sometimes|array',
            'description.en' => 'sometimes|string',
            'description.ar' => 'sometimes|string',
            'icon' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'is_multiple' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->error('Category not found', 404);
            }

            $updateData = [];

            if ($request->has('name')) {
                $updateData['name'] = [
                    'en' => $request->input('name.en', $category->name['en']),
                    'ar' => $request->input('name.ar', $category->name['ar'])
                ];
            }

            if ($request->has('description')) {
                $updateData['description'] = [
                    'en' => $request->input('description.en', $category->description['en'] ?? ''),
                    'ar' => $request->input('description.ar', $category->description['ar'] ?? '')
                ];
            }

            if ($request->hasFile('icon')) {
                // Delete old icon if exists
                if ($category->icon) {
                    $oldIconPath = str_replace('/storage', 'public', $category->icon);
                    Storage::delete($oldIconPath);
                }

                $iconPath = $request->file('icon')->store('category_icons', 'public');;
                $updateData['icon'] = $iconPath;
            }

            if ($request->has('order')) {
                $updateData['order'] = $request->order;
            }

            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->is_active;
            }

            if ($request->has('is_multiple')) {
                $updateData['is_multiple'] = $request->is_multiple;
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
                Storage::disk('public')->delete($category->icon);
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

    // get category services
    public function getCategoryServices(Request $request, $id)
    {
        try {
            $category = Category::with(['services.requirements'])->find($id);

            if (!$category) {
                return $this->error('Category not found', 404);
            }

            $services = $category->services()
                ->where('is_active', true)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'name' => $service->name,
                        'description' => $service->description,
                        'price' => $service->price,
                        'status' => $service->is_active ? 'Active' : 'Inactive',
                        'icon' => $service->icon ? $service->icon : null,
                        'cover_photo' => $service->cover_photo ? $service->cover_photo : null,
                        'requirements' => $service->requirements->map(function ($requirement) {
                            return [
                                'id' => $requirement->id,
                                'name' => $requirement->name,
                                'type' => $requirement->type,
                            ];
                        })
                    ];
                });

            return $this->success([
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name
                ],
                'services' => $services
            ], 'Category services with requirements fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch category services: ' . $e->getMessage(), 500);
        }
    }
}
