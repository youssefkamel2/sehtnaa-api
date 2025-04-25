<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Admin;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    use ResponseTrait;

    private function checkSuperAdmin()
    {
        $admin = JWTAuth::parseToken()->authenticate();
        $admin->load('admin');

        if (!$admin->admin || $admin->admin->role !== 'super_admin') {
            return false;
        }

        return true;
    }

    public function index()
    {
        if (!$this->checkSuperAdmin()) {
            return $this->error('Only super admins can access this resource.', 403);
        }

        try {
            $admins = User::with('admin')
                ->where('user_type', 'admin')
                ->get();

            return $this->success($admins, 'Admins fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch admins: ' . $e->getMessage(), 500);
        }
    }

    public function createAdmin(Request $request)
    {
        if (!$this->checkSuperAdmin()) {
            return $this->error('Only super admins can create new admins.', 403);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|unique:users',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        try {
            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'user_type' => 'admin',
                'status' => 'active',
            ]);

            Admin::create([
                'user_id' => $user->id,
                'role' => 'admin',
            ]);

            return $this->success($user->load('admin'), 'Admin account created successfully.', 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create admin: ' . $e->getMessage(), 500);
        }
    }

    public function updateAdmin(Request $request)
    {
        if (!$this->checkSuperAdmin()) {
            return $this->error('Only super admins can update admins.', 403);
        }

        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'first_name' => 'sometimes|string',
                'last_name' => 'sometimes|string',
                'phone' => 'sometimes|string|unique:users,phone,' . optional(User::where('email', $request->email)->first())->id,
                'password' => 'sometimes|string|min:6',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return $this->error('Admin not found.', 404);
            }

            if ($user->user_type !== 'admin') {
                return $this->error('You can only update admin accounts.', 403);
            }

            if ($user->admin->role === 'super_admin') {
                return $this->error('Super admin accounts cannot be modified.', 403);
            }

            $updateData = array_filter([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'phone' => $request->phone,
            ]);

            if ($request->has('password')) {
                $updateData['password'] = Hash::make($request->password);
            }

            $user->update($updateData);

            return $this->success($user->load('admin'), 'Admin updated successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to update admin: ' . $e->getMessage(), 500);
        }
    }

    public function deleteAdmin(Request $request)
    {
        if (!$this->checkSuperAdmin()) {
            return $this->error('Only super admins can delete admins.', 403);
        }

        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return $this->error('Admin not found.', 404);
            }

            if ($user->user_type !== 'admin') {
                return $this->error('You can only delete admin accounts.', 403);
            }

            if ($user->admin->role === 'super_admin') {
                return $this->error('Super admin accounts cannot be deleted.', 403);
            }

            // Delete the admin record first (this will trigger the boot method to delete the user)
            $user->admin->delete();

            return $this->success(null, 'Admin account deleted successfully.');
        } catch (\Exception $e) {
            return $this->error('Failed to delete admin: ' . $e->getMessage(), 500);
        }
    }

    public function toggleStatus(Request $request)
    {
        if (!$this->checkSuperAdmin()) {
            return $this->error('Only super admins can manage admin status.', 403);
        }

        try {

            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors()->first(), 400);
            }
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return $this->error('Admin not found.', 404);
            }

            if ($user->user_type !== 'admin') {
                return $this->error('You can only manage admin accounts.', 403);
            }

            if ($user->admin->role === 'super_admin') {
                return $this->error('Super admin accounts cannot be modified.', 403);
            }

            $user->update([
                'status' => $user->status === 'active' ? 'de-active' : 'active'
            ]);

            $token = JWTAuth::fromUser($user);

            return ($token);die;
            // Immediately invalidate it
            JWTAuth::setToken($token)->invalidate();

            return $this->success(
                ['status' => $user->status],
                'Admin status updated successfully'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to update admin status: ' . $e->getMessage(), 500);
        }
    }
}
