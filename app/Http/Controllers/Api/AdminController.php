<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Admin;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\ProviderDocument;
use App\Models\RequiredDocument;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;


class AdminController extends Controller
{
    use ResponseTrait;

    // Create a new admin or moderator account [super_admin can add admin or moderator, admin can add moderator]
    public function createAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|unique:users',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,mod',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        // Get the authenticated admin
        $admin = JWTAuth::parseToken()->authenticate();

        // Load the admin relationship
        $admin->load('admin');

        // Check if the authenticated user has an admin record
        if (!$admin->admin) {
            return $this->error('You do not have permission to create admins or moderators.', 403);
        }

        // Check if the admin is a super admin or admin
        if (!in_array($admin->admin->role, ['super_admin', 'admin'])) {
            return $this->error('You do not have permission to create admins or moderators.', 403);
        }

        // check if authenticated user is super admin and trying to create another super admin
        if ($admin->admin->role === 'super_admin' && $request->role === 'super_admin') {
            return $this->error('You do not have permission to create super admins.', 403);
        }

        // check if authenticated user is admin and trying to create another admin or super admin
        if ($admin->admin->role === 'admin' && in_array($request->role, ['admin', 'super_admin'])) {
            return $this->error('You do not have permission to create admins or super admins.', 403);
        }

        // check if new email already exists
        $user = User::where('email', $request->email)->first();
        if ($user) {
            return $this->error('Email already exists.', 400);
        }

        // Create the user
        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'user_type' => 'admin',
            'status' => 'active',
        ]);

        // Create the admin
        Admin::create([
            'user_id' => $user->id,
            'role' => $request->role,
        ]);

        return $this->success(null, 'Admin/moderator account created successfully.');
    }

    // Delete an admin or moderator account
    public function deleteAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        // Get the authenticated admin
        $admin = JWTAuth::parseToken()->authenticate();

        // Load the admin relationship
        $admin->load('admin');

        // Check if the authenticated user has an admin record
        if (!$admin->admin) {
            return $this->error('You do not have permission to delete admins or moderators.', 403);
        }

        // Find the user to be deleted
        $user = User::where('email', $request->email)->first();


        // check if user_type = admin
        if ($user->user_type !== 'admin') {
            return $this->error('You can only delete admins or moderators.', 403);
        }

        // Check permissions
        if ($admin->admin->role === 'super_admin') {
            // Super admin can delete admins and moderators
            if (!in_array($user->admin->role, ['admin', 'mod'])) {
                return $this->error('You can only delete admins or moderators.', 403);
            }
        } elseif ($admin->admin->role === 'admin') {
            // Admin can only delete moderators
            if ($user->admin->role !== 'mod') {
                return $this->error('You can only delete moderators.', 403);
            }
        } else {
            return $this->error('You do not have permission to delete users.', 403);
        }

        // Delete the user and admin record
        $user->delete();

        return $this->success(null, 'Admin/moderator account deleted successfully.');
    }

    // Deactivate an admin or moderator account
    public function deactivateAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        // Get the authenticated admin
        $admin = JWTAuth::parseToken()->authenticate();

        // Load the admin relationship
        $admin->load('admin');

        // Check if the authenticated user has an admin record
        if (!$admin->admin) {
            return $this->error('You do not have permission to deactivate admins or moderators.', 403);
        }

        // Find the user to be deactivated
        $user = User::where('email', $request->email)->first();

        // check if user_type = admin
        if ($user->user_type !== 'admin') {
            return $this->error('You can only delete admins or moderators.', 403);
        }

        // Check permissions
        if ($admin->admin->role === 'super_admin') {
            // Super admin can deactivate admins and moderators
            if (!in_array($user->admin->role, ['admin', 'mod'])) {
                return $this->error('You can only deactivate admins or moderators.', 403);
            }
        } elseif ($admin->admin->role === 'admin') {
            // Admin can only deactivate moderators
            if ($user->admin->role !== 'mod') {
                return $this->error('You can only deactivate moderators.', 403);
            }
        } else {
            return $this->error('You do not have permission to deactivate users.', 403);
        }

        // Deactivate the user
        $user->update(['status' => 'de-active']);

        return $this->success(null, 'Admin/moderator account deactivated successfully.');
    }

}
