<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuideEarning;
use App\Models\GuideWithdrawal;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GuideEarningsController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    // Get earnings summary for the authenticated guide
    public function getEarningsSummary()
    {
        try {
            $user = Auth::user();

            if (!$user || !$user->is_guide) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized or not a guide'
                ], 403);
            }

            $summary = $this->paymentService->getGuideEarningsSummary($user->id);

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting guide earnings summary: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting earnings summary: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get detailed earnings list for the authenticated guide
    public function getEarningsList()
    {
        try {
            $user = Auth::user();

            if (!$user || !$user->is_guide) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized or not a guide'
                ], 403);
            }

            $earnings = GuideEarning::with(['trip', 'booking'])
                ->where('guide_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($earning) {
                    return [
                        'id' => $earning->id,
                        'amount' => $earning->amount,
                        'status' => $earning->status,
                        'description' => $earning->description,
                        'created_at' => $earning->created_at,
                        'processed_at' => $earning->processed_at,
                        'trip_id' => $earning->trip_id,
                        'trip_title' => $earning->trip ? $earning->trip->title : null,
                        'booking_id' => $earning->booking_id,
                        'payment_id' => $earning->payment_id
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $earnings
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting guide earnings list: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting earnings list: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get withdrawal history for the authenticated guide
    public function getWithdrawalHistory()
    {
        try {
            $user = Auth::user();

            if (!$user || !$user->is_guide) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized or not a guide'
                ], 403);
            }

            $withdrawals = GuideWithdrawal::where('guide_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $withdrawals
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting guide withdrawal history: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting withdrawal history: ' . $e->getMessage()
            ], 500);
        }
    }

    // Request a withdrawal
    public function requestWithdrawal(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user || !$user->is_guide) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized or not a guide'
                ], 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:50000', // Minimum withdrawal amount
                'bank_name' => 'required|string|max:100',
                'account_number' => 'required|string|max:50',
                'account_name' => 'required|string|max:100',
                'notes' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check available balance
            $summary = $this->paymentService->getGuideEarningsSummary($user->id);

            if ($summary['available_balance'] < $request->amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance',
                    'available_balance' => $summary['available_balance'],
                    'requested_amount' => $request->amount
                ], 400);
            }

            // Create withdrawal request
            $withdrawal = GuideWithdrawal::create([
                'guide_id' => $user->id,
                'amount' => $request->amount,
                'bank_name' => $request->bank_name,
                'account_number' => $request->account_number,
                'account_name' => $request->account_name,
                'notes' => $request->notes,
                'status' => 'pending'
            ]);

            // Process withdrawal with Midtrans (can be done asynchronously)
            $result = $this->paymentService->processGuideWithdrawal($withdrawal);

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted',
                'data' => [
                    'withdrawal' => $withdrawal,
                    'processing_result' => $result
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error requesting guide withdrawal: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'request' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error requesting withdrawal: ' . $e->getMessage()
            ], 500);
        }
    }

    // Check withdrawal status
    public function checkWithdrawalStatus($id)
    {
        try {
            $user = Auth::user();

            if (!$user || !$user->is_guide) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized or not a guide'
                ], 403);
            }

            $withdrawal = GuideWithdrawal::where('id', $id)
                ->where('guide_id', $user->id)
                ->first();

            if (!$withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal not found'
                ], 404);
            }

            // Check status from Midtrans if the withdrawal is being processed
            if ($withdrawal->status === 'processing' && $withdrawal->transaction_id) {
                $result = $this->paymentService->checkWithdrawalStatus($withdrawal);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'withdrawal' => $withdrawal,
                        'midtrans_result' => $result
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'withdrawal' => $withdrawal
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking withdrawal status: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'withdrawal_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error checking withdrawal status: ' . $e->getMessage()
            ], 500);
        }
    }
}
