<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Guide;
use App\Mail\GuideApprovalMail;
use App\Mail\GuideBanMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class GuideController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'nama' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone_number' => 'required|string|max:15',
            'ktp_image' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        try {
            DB::beginTransaction();

            // Handle file upload
            $ktpPath = null;
            if ($request->hasFile('ktp_image')) {
                // When storing the image
                $ktpPath = $request->file('ktp_image')->store('ktp_images', 'public');
                $ktpPath = str_replace('public/', '', $ktpPath);
            }

            // Create user
            $namaParts = explode(' ', $request->nama, 2);
            $user = User::create([
                'nama_depan' => $namaParts[0],
                'nama_belakang' => isset($namaParts[1]) ? $namaParts[1] : '',
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'guide'
            ]);

            // Create guide
            Guide::create([
                'user_id' => $user->id,
                'phone_number' => $request->phone_number,
                'ktp_image' => $ktpPath,
                'status' => 'pending'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Guide registration successful. Waiting for admin approval.'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPendingGuides()
    {
        $guides = Guide::with('user')
            ->where('status', 'pending')
            ->get();

        return response()->json($guides);
    }

    public function getAllGuides()
    {
        $guides = Guide::with('user')
            ->select('guides.*')
            ->join('users', 'guides.user_id', '=', 'users.id')
            ->get()
            ->map(function ($guide) {
                return [
                    'id' => $guide->id,
                    'nama' => $guide->user->nama_depan . ' ' . $guide->user->nama_belakang,
                    'email' => $guide->user->email,
                    'phone_number' => $guide->phone_number,
                    'ktp_image' => $guide->ktp_image,
                    'status' => $guide->suspended_until && now()->lt($guide->suspended_until) ? 'suspended' : $guide->status,
                    'created_at' => $guide->created_at,
                    'suspended_until' => $guide->suspended_until,
                    'suspension_reason' => $guide->suspension_reason
                ];
            });

        return response()->json($guides);
    }

    public function getGuideById($id)
    {
        $guide = Guide::with('user')
            ->where('id', $id)
            ->first();

        if (!$guide) {
            return response()->json(['message' => 'Guide not found'], 404);
        }

        // Format the response data
        $guideData = [
            'id' => $guide->id,
            'nama' => $guide->user->nama_depan . ' ' . $guide->user->nama_belakang,
            'email' => $guide->user->email,
            'phone_number' => $guide->phone_number,
            'ktp_image' => $guide->ktp_image,
            'status' => $guide->suspended_until && now()->lt($guide->suspended_until) ? 'suspended' : $guide->status,
            'created_at' => $guide->created_at,
            'suspended_until' => $guide->suspended_until,
            'suspension_reason' => $guide->suspension_reason,
            'trips_created' => 0,
            'total_customers' => 0,
            'rating' => 0,
            'trips' => []
        ];

        return response()->json($guideData);
    }

    public function approveGuide(Request $request, $id)
    {
        try {
            $guide = Guide::with('user')->findOrFail($id);
            $guide->status = 'approved';
            $guide->save();

            // Send approval email
            Mail::to($guide->user->email)->send(new GuideApprovalMail($guide->user->nama_depan . ' ' . $guide->user->nama_belakang));

            return response()->json([
                'status' => 'success',
                'message' => 'Guide approved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve guide: ' . $e->getMessage()
            ], 500);
        }
    }

    public function rejectGuide(Request $request, $id)
    {
        $guide = Guide::findOrFail($id);
        $guide->status = 'rejected';
        $guide->save();

        return response()->json([
            'message' => 'Guide rejected'
        ]);
    }

    public function banGuide(Request $request, $id)
    {
        try {
            $guide = Guide::with('user')->findOrFail($id);
            $userName = $guide->user->nama_depan . ' ' . $guide->user->nama_belakang;
            $userEmail = $guide->user->email;

            // Store email before deleting
            $emailData = [
                'name' => $userName,
                'email' => $userEmail
            ];

            // Delete guide record
            $guide->delete();

            // Delete associated user
            $guide->user->delete();

            // Send ban notification email
            try {
                Mail::to($emailData['email'])->send(new GuideBanMail($emailData['name']));
                app('log')->info('Ban notification email sent to: ' . $emailData['email']);
            } catch (\Exception $emailError) {
                app('log')->error('Ban email sending failed: ' . $emailError->getMessage());
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Guide has been banned and removed from the system'
            ]);
        } catch (\Exception $e) {
            app('log')->error('Guide ban failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to ban guide: ' . $e->getMessage()
            ], 500);
        }
    }

    // Add these methods to your GuideController class

    public function suspendGuide(Request $request, $id)
    {
        try {
            $request->validate([
                'duration' => 'required|string',
                'reason' => 'required|string|max:255',
            ]);

            $guide = Guide::with('user')->findOrFail($id);

            // Calculate suspension end date
            $suspendedUntil = null;
            switch ($request->duration) {
                case '1_day':
                    $suspendedUntil = now()->addDay();
                    break;
                case '1_week':
                    $suspendedUntil = now()->addWeek();
                    break;
                case '1_month':
                    $suspendedUntil = now()->addMonth();
                    break;
                case '3_months':
                    $suspendedUntil = now()->addMonths(3);
                    break;
                case 'indefinite':
                    $suspendedUntil = now()->addYears(10); // Effectively indefinite
                    break;
                default:
                    throw new \Exception('Invalid suspension duration');
            }

            $guide->suspended_until = $suspendedUntil;
            $guide->suspension_reason = $request->reason;
            $guide->save();

            // You could add email notification here

            return response()->json([
                'status' => 'success',
                'message' => 'Guide has been suspended',
                'data' => [
                    'guide_id' => $guide->id,
                    'suspended_until' => $suspendedUntil
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to suspend guide: ' . $e->getMessage()
            ], 500);
        }
    }

    public function unsuspendGuide(Request $request, $id)
    {
        try {
            $guide = Guide::findOrFail($id);
            $guide->suspended_until = null;
            $guide->suspension_reason = null;
            $guide->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Guide suspension has been lifted'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to unsuspend guide: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getProfile()
    {
        $user = auth()->user();
        $guide = $user->guide;

        if (!$guide) {
            return response()->json([
                'success' => false,
                'message' => 'Guide profile not found'
            ], 404);
        }

        $guide->load('user');

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $guide->user->nama_depan . ' ' . $guide->user->nama_belakang,
                'email' => $guide->user->email,
                'phone_number' => $guide->phone_number,
                'whatsapp' => $guide->whatsapp,
                'instagram' => $guide->instagram,
                'about' => $guide->about,
                'status' => $guide->status
            ]
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();
        $guide = $user->guide;

        if (!$guide) {
            return response()->json([
                'success' => false,
                'message' => 'Guide profile not found'
            ], 404);
        }

        $request->validate([
            'whatsapp' => 'nullable|string|max:255',
            'instagram' => 'nullable|string|max:255',
            'about' => 'nullable|string|max:1000'
        ]);

        $guide->update($request->only(['whatsapp', 'instagram', 'about']));

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $guide
        ]);
    }
}
