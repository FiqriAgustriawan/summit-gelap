<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\GuideEarning; // Add this import
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\EmptyRequestCheck;
class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function callback(Request $request)
    {
        try {
            $notification = json_decode($request->getContent());
            Log::info('Midtrans callback received:', ['notification' => $notification]);

            // Find payment by order_id (invoice_number)
            $payment = Payment::where('invoice_number', $notification->order_id)->first();

            // If payment not found, try to find by transaction_id
            if (!$payment) {
                Log::warning('Payment not found by invoice_number, trying transaction_id', [
                    'order_id' => $notification->order_id
                ]);
                $payment = Payment::where('transaction_id', $notification->transaction_id ?? null)->first();
            }

            if (!$payment) {
                Log::error('Payment not found for callback', [
                    'order_id' => $notification->order_id,
                    'transaction_id' => $notification->transaction_id ?? null
                ]);
                return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
            }

            Log::info('Payment found for callback', [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id ?? null,
                'status' => $notification->transaction_status
            ]);

            // Handle various transaction statuses
            if (in_array($notification->transaction_status, ['settlement', 'capture', 'success'])) {
                // Process payment and distribute funds to guide
                $result = $this->handleSuccessfulPayment($payment, $notification);

                // Log the result of guide earnings processing
                Log::info('Guide earnings processing result:', ['success' => $result, 'payment_id' => $payment->id]);

                // Check if guide earnings were created
                $guideEarning = GuideEarning::where('payment_id', $payment->id)->first();
                if (!$guideEarning) {
                    Log::warning('Guide earnings not created after successful payment', ['payment_id' => $payment->id]);
                } else {
                    Log::info('Guide earnings created successfully', [
                        'earning_id' => $guideEarning->id,
                        'guide_id' => $guideEarning->guide_id,
                        'amount' => $guideEarning->amount
                    ]);
                }
            } elseif ($notification->transaction_status === 'pending') {
                // Handle pending status
                $payment->status = 'pending';
                $payment->transaction_id = $notification->transaction_id ?? $payment->transaction_id;
                $payment->save();

                Log::info('Payment status updated to pending', ['payment_id' => $payment->id]);
            } elseif (in_array($notification->transaction_status, ['deny', 'cancel', 'expire', 'failure'])) {
                // Handle failed transactions
                $payment->status = 'failed';
                $payment->transaction_id = $notification->transaction_id ?? $payment->transaction_id;
                $payment->save();

                Log::info('Payment status updated to failed', [
                    'payment_id' => $payment->id,
                    'transaction_status' => $notification->transaction_status
                ]);
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Callback error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function verifyPaymentStatus($orderId)
    {
        try {
            Log::info('Starting payment verification', ['received_order_id' => $orderId]);

            // Get stored booking ID from session or request
            $bookingId = session('current_booking_id') ?? request()->get('booking_id');
            Log::info('Checking with booking ID:', ['booking_id' => $bookingId]);

            // Try to find payment using multiple approaches
            $payment = Payment::with(['booking' => function($query) {
                $query->with(['trip', 'user']);
            }])
            ->where(function($query) use ($orderId, $bookingId) {
                $query->where('invoice_number', 'LIKE', '%' . $orderId . '%')
                      ->orWhere('order_id', 'LIKE', '%' . $orderId . '%')
                      ->orWhere('transaction_id', 'LIKE', '%' . $orderId . '%')
                      ->orWhere('booking_id', $bookingId);
            })
            ->latest()
            ->first();

            if (!$payment && $bookingId) {
                // Try to find the most recent payment for this booking
                $payment = Payment::with(['booking' => function($query) {
                    $query->with(['trip', 'user']);
                }])
                ->where('booking_id', $bookingId)
                ->latest()
                ->first();
            }

            if (!$payment) {
                Log::warning('Payment not found, checking recent payments');
                // Try to find any recent pending payment
                $payment = Payment::with(['booking' => function($query) {
                    $query->with(['trip', 'user']);
                }])
                ->where('status', 'pending')
                ->latest()
                ->first();
            }

            if (!$payment) {
                Log::error('Payment not found after all attempts', [
                    'order_id' => $orderId,
                    'booking_id' => $bookingId
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found',
                    'show_buttons' => true,
                    'debug_info' => [
                        'order_id' => $orderId,
                        'booking_id' => $bookingId
                    ]
                ], 404);
            }

            // Check Midtrans status
            try {
                $status = $this->paymentService->checkTransactionStatus($payment->invoice_number);
                Log::info('Midtrans status received:', ['status' => $status]);

                if (isset($status->transaction_status) &&
                    in_array($status->transaction_status, ['settlement', 'capture', 'success'])) {

                    // Update payment status
                    $payment->status = 'paid';
                    $payment->paid_at = now();
                    $payment->save();

                    // Update booking status
                    if ($payment->booking) {
                        $payment->booking->status = 'confirmed';
                        $payment->booking->save();
                    }

                    return response()->json([
                        'success' => true,
                        'data' => [
                            'status' => 'paid',
                            'booking_status' => 'confirmed',
                            'payment_status' => 'paid',
                            'show_buttons' => false,
                            'redirect_url' => '/dashboard'
                        ]
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'status' => $status->transaction_status ?? 'pending',
                        'booking_status' => $payment->booking->status,
                        'payment_status' => $payment->status,
                        'show_buttons' => true
                    ]
                ]);

            } catch (\Exception $midtransError) {
                Log::error('Midtrans check failed:', ['error' => $midtransError->getMessage()]);

                // Return local payment status if Midtrans check fails
                return response()->json([
                    'success' => true,
                    'data' => [
                        'status' => $payment->status,
                        'booking_status' => $payment->booking->status,
                        'payment_status' => $payment->status,
                        'show_buttons' => true
                    ]
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Payment verification failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed: ' . $e->getMessage(),
                'show_buttons' => true
            ], 500);
        }
    }

    public function getPaymentStatus($invoiceNumber)
    {
        try {
            $payment = Payment::with('booking.trip')
                ->where('invoice_number', $invoiceNumber)
                ->firstOrFail();

            // Check if user is authorized to view this payment
            if (auth()->id() != $payment->booking->user_id &&
                (!auth()->user()->guide || auth()->user()->guide->id != $payment->booking->trip->guide_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $payment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found'
            ], 404);
        }
    }

    // Add method to handle return from payment page
    public function handlePaymentReturn(Request $request)
    {
        try {
            $orderId = $request->order_id;
            $status = $request->transaction_status;

            Log::info('Payment return handler called', [
                'order_id' => $orderId,
                'status' => $status,
                'request' => $request->all()
            ]);

            if (!$orderId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid order ID'
                ], 400);
            }

            // Find payment by invoice_number or order_id
            $payment = Payment::where('invoice_number', $orderId)
                ->orWhere('order_id', $orderId)
                ->first();

            if (!$payment) {
                Log::warning('Payment not found in return handler', ['order_id' => $orderId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], 404);
            }

            if ($status && in_array($status, ['settlement', 'capture', 'success'])) {
                $payment->status = 'paid';
                $payment->paid_at = now();
                $payment->save();

                $booking = $payment->booking;
                if ($booking) {
                    $booking->status = 'confirmed';
                    $booking->save();

                    Log::info('Payment and booking updated successfully in return handler', [
                        'payment_id' => $payment->id,
                        'booking_id' => $booking->id
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Payment completed successfully',
                    'redirect_url' => '/dashboard'
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated',
                'status' => $status ?? 'pending'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process payment return: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment return: ' . $e->getMessage()
            ], 500);
        }
    }

    // Handle successful payment and distribute funds to guide
    protected function handleSuccessfulPayment($payment, $notification = null)
    {
        try {
            // Update payment status
            $payment->status = 'paid';
            $payment->paid_at = now();
            if ($notification) {
                $payment->transaction_id = $notification->transaction_id ?? $payment->transaction_id;
            }
            $payment->save();

            // Update booking status
            $booking = $payment->booking;
            if (!$booking) {
                Log::warning('Booking not found for payment', ['payment_id' => $payment->id]);
                return false;
            }

            $booking->status = 'confirmed';
            $booking->save();

            // Process guide payment
            $trip = $booking->trip;
            if (!$trip || !$trip->guide) {
                Log::warning('Trip or guide not found for booking', [
                    'booking_id' => $booking->id,
                    'trip_id' => $trip->id ?? null,
                    'guide_id' => $trip->guide_id ?? null
                ]);
                return true;
            }

            // Calculate guide's share (80% of the payment)
            $guideSharePercentage = 80; // You can make this configurable
            $guideAmount = ($payment->amount * $guideSharePercentage) / 100;

            // Check if earning record already exists
            $existingEarning = \App\Models\GuideEarning::where([
                'guide_id' => $trip->guide_id,
                'booking_id' => $booking->id,
                'payment_id' => $payment->id
            ])->first();

            if (!$existingEarning) {
                // Create new earning record
                $guideEarning = new \App\Models\GuideEarning([
                    'guide_id' => $trip->guide_id,
                    'trip_id' => $trip->id,
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                    'amount' => $guideAmount,
                    'platform_fee' => $payment->amount - $guideAmount,
                    'status' => 'processed',
                    'description' => "Payment for trip {$trip->mountain->nama_gunung} ({$booking->invoice_number})"
                ]);
                $guideEarning->save();

                Log::info('Guide earning recorded', [
                    'guide_id' => $trip->guide_id,
                    'amount' => $guideAmount,
                    'earning_id' => $guideEarning->id,
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to process guide payment: ' . $e->getMessage(), [
                'payment_id' => $payment->id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // Guide earnings methods
    public function getGuideEarnings()
    {
        $guide = auth()->user()->guide;

        if (!$guide) {
            return response()->json([
                'success' => false,
                'message' => 'Guide profile not found'
            ], 404);
        }

        // Get detailed earnings with related data
        $earnings = \App\Models\GuideEarning::where('guide_id', $guide->id)
            ->with(['trip.mountain', 'booking', 'payment'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($earning) {
                return [
                    'id' => $earning->id,
                    'amount' => $earning->amount,
                    'platform_fee' => $earning->platform_fee,
                    'status' => $earning->status,
                    'trip_name' => $earning->trip->mountain->nama_gunung ?? 'Unknown Trip',
                    'booking_date' => $earning->booking->created_at ?? null,
                    'processed_at' => $earning->processed_at,
                    'description' => $earning->description
                ];
            });

        // Get withdrawal history
        $withdrawals = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($withdrawal) {
                return [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->amount,
                    'status' => $withdrawal->status,
                    'bank_name' => $withdrawal->bank_name,
                    'account_number' => $withdrawal->account_number,
                    'created_at' => $withdrawal->created_at,
                    'processed_at' => $withdrawal->processed_at
                ];
            });

        // Calculate summary
        $totalEarnings = $earnings->where('status', 'processed')->sum('amount');
        $pendingEarnings = $earnings->where('status', 'pending')->sum('amount');
        $withdrawnAmount = $withdrawals->whereIn('status', ['processed', 'pending'])->sum('amount');
        $availableBalance = max(0, $totalEarnings - $withdrawnAmount);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_earnings' => $totalEarnings,
                    'pending_earnings' => $pendingEarnings,
                    'withdrawn_amount' => $withdrawnAmount,
                    'available_balance' => $availableBalance
                ],
                'recent_earnings' => $earnings,
                'withdrawal_history' => $withdrawals
            ]
        ]);
    }

    public function getEarningsSummary()
{
    $guide = auth()->user()->guide;

    if (!$guide) {
        return response()->json([
            'success' => false,
            'message' => 'Guide profile not found'
        ], 404);
    }

    // Calculate total earnings
    $totalEarnings = \App\Models\GuideEarning::where('guide_id', $guide->id)
        ->where('status', 'processed')
        ->sum('amount');

    // Calculate pending earnings
    $pendingEarnings = \App\Models\GuideEarning::where('guide_id', $guide->id)
        ->where('status', 'pending')
        ->sum('amount');

    // Calculate withdrawn amount
    $withdrawnAmount = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
        ->where('status', 'processed')
        ->sum('amount');

    // Calculate pending withdrawals
    $pendingWithdrawals = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
        ->where('status', 'pending')
        ->sum('amount');

    // Calculate available balance
    $availableBalance = max(0, $totalEarnings - $withdrawnAmount - $pendingWithdrawals);

    return response()->json([
        'success' => true,
        'data' => [
            'total_earnings' => $totalEarnings,
            'pending_earnings' => $pendingEarnings,
            'available_balance' => $availableBalance
        ]
    ]);
}

    // Move this method outside of getEarningsSummary
    public function getGuideBalanceForAdmin($guideId)
    {
        // Check if user is admin
        if (!auth()->user()->is_admin && auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $guide = \App\Models\Guide::findOrFail($guideId);

        // Calculate balance
        $totalEarnings = \App\Models\GuideEarning::where('guide_id', $guide->id)
            ->where('status', 'processed')
            ->sum('amount');

        $withdrawnAmount = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
            ->where('status', 'processed')
            ->sum('amount');

        $pendingWithdrawals = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
            ->where('status', 'pending')
            ->sum('amount');

        $availableBalance = max(0, $totalEarnings - $withdrawnAmount - $pendingWithdrawals);

        return response()->json([
            'success' => true,
            'data' => [
                'guide_id' => $guide->id,
                'guide_name' => $guide->user->name ?? 'Unknown',
                'total_earnings' => $totalEarnings,
                'withdrawn_amount' => $withdrawnAmount,
                'pending_withdrawals' => $pendingWithdrawals,
                'available_balance' => $availableBalance
            ]
        ]);
    }

    public function requestWithdrawal(EmptyRequestCheck $request)
    {
        // Check if request body is empty
        if ($request->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Request body is empty',
                'errors' => [
                    'amount' => ['The amount field is required'],
                    'bank_name' => ['The bank name field is required'],
                    'account_number' => ['The account number field is required'],
                    'account_name' => ['The account name field is required']
                ]
            ], 422);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . config('payment.minimum_withdrawal', 50000),
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $guide = auth()->user()->guide;
        if (!$guide) {
            return response()->json([
                'success' => false,
                'message' => 'Guide profile not found'
            ], 404);
        }

        // Improved balance calculation
        $totalEarnings = \App\Models\GuideEarning::where('guide_id', $guide->id)
            ->where('status', 'processed')
            ->sum('amount');

        $withdrawnAmount = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
            ->whereIn('status', ['processed', 'pending'])
            ->sum('amount');

        // Ensure balance is not negative
        $availableBalance = max(0, $totalEarnings - $withdrawnAmount);

        if ($request->amount > $availableBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. Available: Rp ' . number_format($availableBalance, 0, ',', '.'),
                'available_balance' => $availableBalance
            ], 400);
        }

        // Create withdrawal request
        $withdrawal = new \App\Models\GuideWithdrawal([
            'guide_id' => $guide->id,
            'amount' => $request->amount,
            'status' => 'pending',
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_name' => $request->account_name,
            'notes' => $request->notes
        ]);
        $withdrawal->save();

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request submitted successfully',
            'data' => $withdrawal
        ]);
    }

    public function getWithdrawalHistory()
    {
        $guide = auth()->user()->guide;

        if (!$guide) {
            return response()->json([
                'success' => false,
                'message' => 'Guide profile not found'
            ], 404);
        }

        $withdrawals = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $withdrawals
        ]);
    }

    // 2. Masalah Akses Admin untuk Penarikan Dana

    // Untuk memperbaiki masalah 403 Forbidden pada endpoint admin, kita perlu memastikan bahwa:

    // 1. Middleware 'role:admin' berfungsi dengan benar
    // 2. User yang login memiliki flag is_admin = true

    public function getPendingWithdrawals()
    {
        $user = auth()->user();

        // Check if user is authenticated
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Periksa apakah user memiliki role admin atau flag is_admin
        if ($user->role === 'admin' || $user->is_admin) {
            // User adalah admin, izinkan akses
            return $this->getAllWithdrawals();
        }

        // Jika bukan admin, tolak akses
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Admin privileges required'
        ], 403);
    }

    // Helper method to get all withdrawals
    private function getAllWithdrawals()
    {
        // Get all withdrawals with guide information
        $withdrawals = \App\Models\GuideWithdrawal::with(['guide.user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $withdrawals
        ]);
    }

    // Add method to process withdrawal (for admin)
    // Update the processWithdrawal method
    public function processWithdrawal(Request $request, $id)
    {
        // Check if user is admin
        if (!auth()->user()->is_admin && auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $request->validate([
            'reference_number' => 'required|string'
        ]);

        $withdrawal = \App\Models\GuideWithdrawal::findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending withdrawals can be processed'
            ], 400);
        }

        $withdrawal->status = 'processed';
        $withdrawal->processed_at = now();
        $withdrawal->processed_by = auth()->id();
        $withdrawal->reference_number = $request->reference_number;
        $withdrawal->save();

        // Get guide balance after processing
        $guide = $withdrawal->guide;
        $totalEarnings = \App\Models\GuideEarning::where('guide_id', $guide->id)
            ->where('status', 'processed')
            ->sum('amount');

        $withdrawnAmount = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
            ->where('status', 'processed')
            ->sum('amount');

        $pendingWithdrawals = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
            ->where('status', 'pending')
            ->sum('amount');

        $availableBalance = max(0, $totalEarnings - $withdrawnAmount - $pendingWithdrawals);

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal processed successfully',
            'data' => [
                'withdrawal' => $withdrawal,
                'guide' => [
                    'id' => $guide->id,
                    'name' => $guide->user->name ?? 'Unknown'
                ],
                'balance' => [
                    'before_withdrawal' => $availableBalance + $withdrawal->amount,
                    'withdrawn_amount' => $withdrawal->amount,
                    'after_withdrawal' => $availableBalance,
                    'total_earnings' => $totalEarnings,
                    'total_withdrawn' => $withdrawnAmount
                ]
            ]
        ]);
    }

    // Add method to reject withdrawal (for admin)
    public function rejectWithdrawal(Request $request, $id)
    {
        // Check if user is admin
        if (!auth()->user()->is_admin && auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $request->validate([
            'reason' => 'required|string'
        ]);

        $withdrawal = \App\Models\GuideWithdrawal::findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending withdrawals can be rejected'
            ], 400);
        }

        $withdrawal->status = 'rejected';
        $withdrawal->rejected_at = now();
        $withdrawal->rejected_by = auth()->id();
        $withdrawal->reject_reason = $request->reason;
        $withdrawal->save();

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal rejected successfully',
            'data' => $withdrawal
        ]);
    }

    // 3. Tambahkan Metode untuk Memproses Semua Pendapatan yang Pending

        // Add this method to check guide balance after withdrawal
        public function checkGuideBalanceAfterWithdrawal($withdrawalId)
        {
            // Check if user is admin
            if (!auth()->user()->is_admin && auth()->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $withdrawal = \App\Models\GuideWithdrawal::with('guide.user')->findOrFail($withdrawalId);
            $guide = $withdrawal->guide;

            if (!$guide) {
                return response()->json([
                    'success' => false,
                    'message' => 'Guide not found'
                ], 404);
            }

            // Calculate balance
            $totalEarnings = \App\Models\GuideEarning::where('guide_id', $guide->id)
                ->where('status', 'processed')
                ->sum('amount');

            $withdrawnAmount = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
                ->where('status', 'processed')
                ->sum('amount');

            $pendingWithdrawals = \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
                ->where('status', 'pending')
                ->sum('amount');

            $availableBalance = max(0, $totalEarnings - $withdrawnAmount - $pendingWithdrawals);

            // Get transaction history
            $recentTransactions = [
                'earnings' => \App\Models\GuideEarning::where('guide_id', $guide->id)
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get(),
                'withdrawals' => \App\Models\GuideWithdrawal::where('guide_id', $guide->id)
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get()
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'withdrawal' => $withdrawal,
                    'guide' => [
                        'id' => $guide->id,
                        'name' => $guide->user->name ?? 'Unknown',
                        'email' => $guide->user->email ?? 'No email'
                    ],
                    'balance' => [
                        'total_earnings' => $totalEarnings,
                        'withdrawn_amount' => $withdrawnAmount,
                        'pending_withdrawals' => $pendingWithdrawals,
                        'available_balance' => $availableBalance
                    ],
                    'recent_transactions' => $recentTransactions
                ]
            ]);
        }

public function processPaymentToGuideEarnings($paymentId)
{
    try {
        $payment = Payment::findOrFail($paymentId);
        $booking = $payment->booking;

        if (!$booking || !$booking->trip || !$booking->trip->guide) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid booking or guide information'
            ], 400);
        }

        // Calculate guide's earnings (80% of payment)
        $guideAmount = ($payment->amount * 80) / 100;

        // Create or update guide earnings record
        $guideEarning = GuideEarning::updateOrCreate(
            [
                'payment_id' => $payment->id,
                'guide_id' => $booking->trip->guide_id
            ],
            [
                'booking_id' => $booking->id,
                'trip_id' => $booking->trip_id,
                'amount' => $guideAmount,
                'status' => 'processed',
                'description' => "Payment for booking #{$booking->id}"
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'guide_earning' => $guideEarning,
                'payment' => $payment
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error processing guide earnings: ' . $e->getMessage()
        ], 500);
    }
}

public function checkPaymentAndEarnings($paymentId)
{
    try {
        // Try to find payment by multiple possible identifiers
        $payment = Payment::with(['booking.trip.guide'])
            ->where('id', $paymentId)
            ->orWhere('invoice_number', $paymentId)
            ->orWhere('invoice_number', 'LIKE', "%$paymentId%")
            ->orWhere('order_id', $paymentId)
            ->orWhere('transaction_id', $paymentId)
            ->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => "Payment not found with ID: $paymentId"
            ], 404);
        }

        $guideEarning = GuideEarning::where('payment_id', $payment->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'payment_id' => $payment->id,
                'invoice_number' => $payment->invoice_number,
                'payment_status' => $payment->status,
                'payment_amount' => $payment->amount,
                'guide_earning' => $guideEarning ? [
                    'amount' => $guideEarning->amount,
                    'status' => $guideEarning->status,
                    'created_at' => $guideEarning->created_at
                ] : null
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error checking payment and earnings: ' . $e->getMessage()
        ], 500);
    }
}
// Add a new method to manually process guide earnings for a specific payment
public function processGuideEarningsForPayment($paymentId)
{
    try {
        // Check if user is admin
        if (!auth()->user()->is_admin && auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Find payment by ID or invoice number
        $payment = Payment::with(['booking.trip.guide'])
            ->where('id', $paymentId)
            ->orWhere('invoice_number', $paymentId)
            ->orWhere('invoice_number', 'LIKE', "%$paymentId%")
            ->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => "Payment not found with ID: $paymentId"
            ], 404);
        }

        // Check if payment is already paid
        if ($payment->status !== 'paid') {
            return response()->json([
                'success' => false,
                'message' => "Payment must be in 'paid' status to process guide earnings"
            ], 400);
        }

        // Process the guide earnings
        $result = $this->handleSuccessfulPayment($payment);

        if ($result) {
            // Get the created guide earning
            $guideEarning = GuideEarning::where('payment_id', $payment->id)->first();

            return response()->json([
                'success' => true,
                'message' => 'Guide earnings processed successfully',
                'data' => [
                    'payment' => [
                        'id' => $payment->id,
                        'invoice_number' => $payment->invoice_number,
                        'amount' => $payment->amount,
                        'status' => $payment->status
                    ],
                    'guide_earning' => $guideEarning ? [
                        'id' => $guideEarning->id,
                        'amount' => $guideEarning->amount,
                        'status' => $guideEarning->status,
                        'guide_id' => $guideEarning->guide_id,
                        'created_at' => $guideEarning->created_at
                    ] : null
                ]
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process guide earnings'
            ], 500);
        }
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error processing guide earnings: ' . $e->getMessage()
        ], 500);
    }
}
}
