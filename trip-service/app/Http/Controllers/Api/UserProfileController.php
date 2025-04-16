<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Log;

class UserProfileController extends Controller
{
    public function show()
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'User not found'], 404);
            }

            $profile = UserProfile::where('user_id', $user->id)->first();

            if (!$profile) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'user_id' => $user->id,
                        'nama_depan' => $user->nama_depan,
                        'nama_belakang' => $user->nama_belakang,
                        'email' => $user->email,
                        'profile_exists' => false,
                        'is_profile_completed' => false
                    ]
                ]);
            }

            // In the show method, add updated_at to the response
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $profile->id,
                    'user_id' => $user->id,
                    'nama_depan' => $user->nama_depan,
                    'nama_belakang' => $user->nama_belakang,
                    'email' => $user->email,
                    'gender' => $profile->gender,
                    'tanggal_lahir' => $profile->tanggal_lahir,
                    'nik' => $profile->nik,
                    'tempat_tinggal' => $profile->tempat_tinggal,
                    'nomor_telepon' => $profile->nomor_telepon,
                    'profile_image' => $profile->profile_image ? url('storage/' . $profile->profile_image) : null,
                    'is_profile_completed' => $profile->is_profile_completed,
                    'profile_exists' => true,
                    'updated_at' => $profile->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Profile show error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'User not found'], 404);
            }

            $profile = UserProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'gender' => null,
                    'tanggal_lahir' => null,
                    'nik' => null,
                    'tempat_tinggal' => null,
                    'nomor_telepon' => null,
                    'is_profile_completed' => false
                ]
            );

            // Update only provided fields with proper validation
            if ($request->has('gender')) {
                $profile->gender = $request->gender ?: null;
            }
            if ($request->has('tanggal_lahir')) {
                $profile->tanggal_lahir = $request->tanggal_lahir ?: null;
            }
            if ($request->has('nik')) {
                $profile->nik = $request->nik ?: null;
            }
            if ($request->has('tempat_tinggal')) {
                $profile->tempat_tinggal = $request->tempat_tinggal ?: null;
            }
            if ($request->has('nomor_telepon')) {
                $profile->nomor_telepon = $request->nomor_telepon ?: null;
            }

            // Check if profile is complete
            $profile->is_profile_completed =
                $profile->gender &&
                $profile->tanggal_lahir &&
                $profile->nik &&
                $profile->tempat_tinggal &&
                $profile->nomor_telepon;

            $profile->save();

            // In the update method, add updated_at to the response
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $profile->id,
                    'user_id' => $user->id,
                    'nama_depan' => $user->nama_depan,
                    'nama_belakang' => $user->nama_belakang,
                    'email' => $user->email,
                    'gender' => $profile->gender,
                    'tanggal_lahir' => $profile->tanggal_lahir,
                    'nik' => $profile->nik,
                    'tempat_tinggal' => $profile->tempat_tinggal,
                    'nomor_telepon' => $profile->nomor_telepon,
                    'profile_image' => $profile->profile_image ? url('storage/' . $profile->profile_image) : null,
                    'is_profile_completed' => $profile->is_profile_completed,
                    'updated_at' => $profile->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Profile update error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateProfileImage(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'User not found'], 404);
            }

            $request->validate([
                'profile_image' => 'required|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $profile = UserProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'gender' => null,
                    'tanggal_lahir' => null,
                    'nik' => null,
                    'tempat_tinggal' => null,
                    'nomor_telepon' => null,
                    'is_profile_completed' => false
                ]
            );

            if ($request->hasFile('profile_image')) {
                // Handle image update logic
                if ($profile->profile_image) {
                    Storage::disk('public')->delete($profile->profile_image);
                }

                $path = $request->file('profile_image')->store('profile_images', 'public');
                $profile->profile_image = $path;
                $profile->save();

                // In the updateProfileImage method, add updated_at to the response
                return response()->json([
                    'success' => true,
                    'message' => 'Profile image updated successfully',
                    'data' => [
                        'profile_image' => url('storage/' . $path),
                        'updated_at' => $profile->updated_at
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No image file provided'
            ], 400);

        } catch (\Exception $e) {
            Log::error('Profile image update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }
}