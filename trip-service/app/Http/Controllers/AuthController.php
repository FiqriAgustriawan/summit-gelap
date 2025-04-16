<?php
namespace App\Http\Controllers;

use App\Models\Guide;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function register(Request $request) {
        $request->validate([
            'nama_depan' => 'required|string|max:255',
            'nama_belakang' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users|max:255',
            'password' => 'required|string|confirmed|min:6',
        ]);

        $user = User::create([
            'nama_depan' => $request->nama_depan,
            'nama_belakang' => $request->nama_belakang,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user',
        ]);

        return response()->json([
            'message' => 'User registered successfully!',
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            // Check if user is a guide
            if ($user->role === 'guide') {
                $guide = Guide::where('user_id', $user->id)->first();

                // Check guide status
                if ($guide && $guide->status === 'pending') {
                    Auth::logout();
                    return response()->json([
                        'message' => 'Akun Anda masih dalam tahap peninjauan. Silahkan tunggu persetujuan dari admin.'
                    ], 403);
                }

                if ($guide && $guide->status === 'rejected') {
                    Auth::logout();
                    return response()->json([
                        'message' => 'Akun Anda telah ditolak oleh admin.'
                    ], 403);
                }

                if ($guide && $guide->suspended_until && now()->lt($guide->suspended_until)) {
                    Auth::logout();
                    return response()->json([
                        'message' => 'Akun Anda sedang dalam masa suspensi hingga ' .
                         Carbon::parse($guide->suspended_until)->locale('id')->isoFormat('DD MMMM YYYY')

                    ], 403);
                }
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user
            ]);
        }

        return response()->json([
            'message' => 'Email atau password salah'
        ], 401);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ], 200);
    }

    public function userInfo(Request $request)
    {
        return response()->json($request->user(), 200);
    }
}
