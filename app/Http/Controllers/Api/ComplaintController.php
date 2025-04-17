<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Services\FirebaseService;



class ComplaintController extends Controller
{
    use ResponseTrait;

    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function index()
    {
        try {
            // return all complaints
            $complaints = Complaint::with([
                'request.service:id,name',
                'request.assignedProvider.user:id,first_name,last_name',
                'user:id,first_name,last_name'
            ])->get();
            // $complaints = $complaints->get();
            return $this->success($complaints, 'Complaints fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch complaints: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $complaint = Complaint::with([
                'request.service',
                'request.customer.user',
                'request.assignedProvider.user',
                'user:id,first_name,last_name',
                'assignedAdmin:id,user_id'
            ])->find($id);

            if (!$complaint) {
                return $this->error('Complaint not found', 404);
            }

            return $this->success($complaint, 'Complaint details fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch complaint details: ' . $e->getMessage(), 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $complaint = Complaint::with(['user'])->find($id);
            if (!$complaint) {
                return $this->error('Complaint not found', 404);
            }

            $validator = Validator::make($request->all(), [
                'status' => ['required', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
                'response' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return $this->error($validator->errors(), 422);
            }

            // Update complaint status and response
            $complaint->status = $request->status;
            $complaint->response = $request->response;

            if ($request->status === 'resolved') {
                $complaint->resolved_at = now();
            }

            $complaint->save();

            // Send notification to the user who created the complaint
            if ($complaint->user->fcm_token) {
                $this->firebaseService->sendToDevice(
                    $complaint->user->fcm_token,
                    'Complaint Status Updated',
                    "Your complaint status has been updated to: {$request->status}",
                    [
                        'type' => 'complaint_status_update',
                        'complaint_id' => $complaint->id,
                        'new_status' => $request->status
                    ]
                );
            }

            return $this->success($complaint, 'Complaint status updated successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to update complaint status: ' . $e->getMessage(), 500);
        }
    }
}
