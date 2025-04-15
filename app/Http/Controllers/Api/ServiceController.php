<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    use ResponseTrait;

    public function index()
    {
        try {
            $user = auth()->user();
            
            $services = Service::with(['category:id,name,icon'])
                ->when($user->user_type !== 'admin', function($query) {
                    return $query->where('is_active', true);
                })
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->success($services, 'Services fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch services: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|array',
            'name.en' => 'required|string|max:255',
            'name.ar' => 'required|string|max:255',
            'description' => 'required|array',
            'description.en' => 'required|string',
            'description.ar' => 'required|string',
            'provider_type' => 'required|in:individual,organizational',
            'price' => 'required|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'icon' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'cover_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        try {
            $data = [
                'name' => [
                    'en' => $request->input('name.en'),
                    'ar' => $request->input('name.ar')
                ],
                'description' => [
                    'en' => $request->input('description.en'),
                    'ar' => $request->input('description.ar')
                ],
                'provider_type' => $request->provider_type,
                'price' => $request->price,
                'category_id' => $request->category_id,
                'is_active' => true,
                'added_by' => auth()->id()
            ];

            if ($request->hasFile('icon')) {
                $data['icon'] = $request->file('icon')->store('service_icons', 'public');
            }

            if ($request->hasFile('cover_photo')) {
                $data['cover_photo'] = $request->file('cover_photo')->store('service_covers', 'public');
            }

            $service = Service::create($data);

            return $this->success($service, 'Service created successfully', 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create service: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $service = Service::with(['category:id,name,icon'])->find($id);

            if (!$service) {
                return $this->error('Service not found', 404);
            }

            if (!$service->is_active) {
                return $this->error('Service is not available', 404);
            }

            return $this->success($service, 'Service details fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch service details: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|array',
            'name.en' => 'required_with:name|string|max:255',
            'name.ar' => 'required_with:name|string|max:255',
            'description' => 'sometimes|array',
            'description.en' => 'required_with:description|string',
            'description.ar' => 'required_with:description|string',
            'provider_type' => 'sometimes|in:individual,organizational',
            'price' => 'sometimes|numeric|min:0',
            'category_id' => 'sometimes|exists:categories,id',
            'is_active' => 'sometimes|boolean',
            'icon' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'cover_photo' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        try {
            $service = Service::find($id);
            
            if (!$service) {
                return $this->error('Service not found', 404);
            }

            $data = $request->except(['icon', 'cover_photo']);

            if ($request->hasFile('icon')) {
                if ($service->icon) {
                    Storage::disk('public')->delete($service->icon);
                }
                $data['icon'] = $request->file('icon')->store('service_icons', 'public');
            }

            if ($request->hasFile('cover_photo')) {
                if ($service->cover_photo) {
                    Storage::disk('public')->delete($service->cover_photo);
                }
                $data['cover_photo'] = $request->file('cover_photo')->store('service_covers', 'public');
            }

            $service->update($data);

            return $this->success($service, 'Service updated successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to update service: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $service = Service::find($id);

            if (!$service) {
                return $this->error('Service not found', 404);
            }
            if ($service->icon) {
                Storage::disk('public')->delete($service->icon);
            }

            $service->delete();

            return $this->success(null, 'Service deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete service: ' . $e->getMessage(), 500);
        }
    }
    public function toggleStatus($id)
    {
        try {
            $service = Service::find($id);

            if (!$service) {
                return $this->error('Service not found', 404);
            }

            $service->update([
                'is_active' => !$service->is_active
            ]);

            return $this->success(
                ['is_active' => $service->is_active],
                'Service status updated successfully'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to update service status: ' . $e->getMessage(), 500);
        }
    }
}