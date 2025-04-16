<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckRole
{
    public function handle(Request $request, Closure $next, $role)
    {
        if (!$request->user()) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Special handling for admin role
        if ($role === 'admin') {
            // Izinkan akses jika user memiliki role admin ATAU flag is_admin true
            if ($request->user()->role === 'admin' || $request->user()->is_admin) {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Admin privileges required'
            ], 403);
        }
        // For other roles, check the role field
        else if ($request->user()->role !== $role) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorized - {$role} privileges required"
            ], 403);
        }

        return $next($request);
    }
}
