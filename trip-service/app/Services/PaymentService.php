<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\GuideEarning;
use App\Models\GuideWithdrawal;
use Carbon\Carbon;
use Midtrans\Config;
use Midtrans\Snap;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class PaymentService
{
    public function __construct()
    {
        Config::$serverKey = config('services.midtrans.server_key');
        Config::$clientKey = config('services.midtrans.client_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    public function createPayment(Booking $booking)
    {
        // Set Midtrans configuration
        \Midtrans\Config::$serverKey = config('services.midtrans.server_key');
        \Midtrans\Config::$isProduction = config('services.midtrans.is_production');
        \Midtrans\Config::$isSanitized = true;
        \Midtrans\Config::$is3ds = true;

        $trip = $booking->trip;
        $user = $booking->user;

        // Generate unique invoice number
        $invoiceNumber = 'INV-' . time() . '-' . $booking->id;

        // Create payment record
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'invoice_number' => $invoiceNumber,
            'amount' => $trip->price,
            'status' => 'pending',
            'expired_at' => Carbon::now()->addDay() // Payment expires in 24 hours
        ]);

        // Prepare Midtrans parameters
        $params = [
            'transaction_details' => [
                'order_id' => $payment->invoice_number,
                'gross_amount' => (int) $payment->amount,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
            ],
            'item_details' => [
                [
                    'id' => $trip->id,
                    'price' => (int) $trip->price,
                    'quantity' => 1,
                    'name' => 'Trip to ' . $trip->mountain->nama_gunung,
                ]
            ],
            'expiry' => [
                'unit' => 'day',
                'duration' => 1,
            ]
        ];

        try {
            // Get Snap Payment Page URL
            $snapToken = \Midtrans\Snap::getSnapToken($params);
            $paymentUrl = \Midtrans\Snap::getSnapUrl($params);

            // Update payment with token and URL
            $payment->update([
                'payment_id' => $snapToken,
                'payment_url' => $paymentUrl
            ]);

            return $payment;
        } catch (\Exception $e) {
            // Handle error
            $payment->update(['status' => 'failed']);
            throw $e;
        }
    }

    // Process guide earnings from a successful payment
    public function processGuideEarnings(Payment $payment)
    {
        try {
            // Get the booking and trip details
            $booking = $payment->booking;

            if (!$booking || !$booking->trip || !$booking->trip->guide) {
                Log::error('Cannot process guide earnings: Invalid booking or guide information', [
                    'payment_id' => $payment->id,
                    'booking_id' => $payment->booking_id
                ]);
                return false;
            }

            $trip = $booking->trip;
            $guide = $trip->guide;

            // Calculate guide's earnings (80% of payment amount)
            $guideAmount = ($payment->amount * 80) / 100;

            // Create or update guide earnings record
            $guideEarning = GuideEarning::updateOrCreate(
                [
                    'payment_id' => $payment->id,
                    'guide_id' => $guide->id
                ],
                [
                    'booking_id' => $booking->id,
                    'trip_id' => $trip->id,
                    'amount' => $guideAmount,
                    'status' => 'processed',
                    'description' => "Payment for booking #{$booking->id} - {$trip->title}"
                ]
            );

            Log::info('Guide earnings processed successfully', [
                'guide_id' => $guide->id,
                'payment_id' => $payment->id,
                'amount' => $guideAmount
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error processing guide earnings: ' . $e->getMessage(), [
                'payment_id' => $payment->id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // Get guide earnings summary
    public function getGuideEarningsSummary($guideId)
    {
        try {
            // Get total earnings
            $totalEarnings = GuideEarning::where('guide_id', $guideId)
                ->where('status', 'processed')
                ->sum('amount');

            // Get pending earnings (from trips not yet completed)
            $pendingEarnings = GuideEarning::where('guide_id', $guideId)
                ->where('status', 'processed')
                ->whereHas('trip', function($query) {
                    $query->where('status', '!=', 'completed');
                })
                ->sum('amount');

            // Get total withdrawn amount
            $totalWithdrawn = GuideWithdrawal::where('guide_id', $guideId)
                ->where('status', 'processed')
                ->sum('amount');

            // Calculate available balance
            $availableBalance = $totalEarnings - $totalWithdrawn;

            return [
                'total_earnings' => $totalEarnings,
                'pending_earnings' => $pendingEarnings,
                'total_withdrawn' => $totalWithdrawn,
                'available_balance' => $availableBalance
            ];
        } catch (\Exception $e) {
            Log::error('Error getting guide earnings summary: ' . $e->getMessage(), [
                'guide_id' => $guideId,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'total_earnings' => 0,
                'pending_earnings' => 0,
                'total_withdrawn' => 0,
                'available_balance' => 0
            ];
        }
    }

    // Process guide withdrawal request using Midtrans Payout
    public function processGuideWithdrawal(GuideWithdrawal $withdrawal)
    {
        try {
            // Get guide and check available balance
            $guide = $withdrawal->guide;
            $summary = $this->getGuideEarningsSummary($guide->id);

            if ($summary['available_balance'] < $withdrawal->amount) {
                $withdrawal->status = 'rejected';
                $withdrawal->reject_reason = 'Insufficient balance';
                $withdrawal->save();

                Log::warning('Guide withdrawal rejected: Insufficient balance', [
                    'withdrawal_id' => $withdrawal->id,
                    'guide_id' => $guide->id,
                    'requested_amount' => $withdrawal->amount,
                    'available_balance' => $summary['available_balance']
                ]);

                return [
                    'success' => false,
                    'message' => 'Insufficient balance'
                ];
            }

            // Prepare Midtrans Payout parameters
            $reference = 'WD-' . time() . '-' . $withdrawal->id;

            // Use Midtrans Iris API for payouts
            // When making API calls to process withdrawals, update the URL:
            $url = config('services.midtrans.is_production')
                ? 'https://app.midtrans.com/iris/api/v1/payouts'
                : 'https://app.sandbox.midtrans.com/iris/api/v1/payouts';

            $response = Http::withBasicAuth(config('services.midtrans.server_key'), '')
                ->post($url, [
                    'reference_no' => $reference,
                    'beneficiary_name' => $withdrawal->account_name,
                    'beneficiary_account' => $withdrawal->account_number,
                    'beneficiary_bank' => $withdrawal->bank_name,
                    'beneficiary_email' => $guide->email,
                    'amount' => $withdrawal->amount,
                    'notes' => $withdrawal->notes ?? 'Guide withdrawal'
                ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['payouts'][0]['status'])) {
                $payoutStatus = $responseData['payouts'][0]['status'];
                $payoutId = $responseData['payouts'][0]['payout_id'] ?? null;

                // Update withdrawal record
                $withdrawal->transaction_id = $payoutId;
                $withdrawal->reference_number = $reference;

                if ($payoutStatus === 'queued' || $payoutStatus === 'processing') {
                    $withdrawal->status = 'processing';
                } elseif ($payoutStatus === 'completed') {
                    $withdrawal->status = 'processed';
                    $withdrawal->processed_at = now();
                } else {
                    $withdrawal->status = 'failed';
                    $withdrawal->reject_reason = 'Payout failed: ' . $payoutStatus;
                }

                $withdrawal->save();

                Log::info('Guide withdrawal processed with Midtrans', [
                    'withdrawal_id' => $withdrawal->id,
                    'guide_id' => $guide->id,
                    'amount' => $withdrawal->amount,
                    'status' => $withdrawal->status,
                    'payout_id' => $payoutId
                ]);

                return [
                    'success' => true,
                    'message' => 'Withdrawal processed',
                    'status' => $withdrawal->status,
                    'payout_id' => $payoutId
                ];
            } else {
                // Handle error
                $errorMessage = $responseData['error_message'] ?? 'Unknown error';

                $withdrawal->status = 'failed';
                $withdrawal->reject_reason = 'Payout failed: ' . $errorMessage;
                $withdrawal->save();

                Log::error('Guide withdrawal failed with Midtrans', [
                    'withdrawal_id' => $withdrawal->id,
                    'guide_id' => $guide->id,
                    'amount' => $withdrawal->amount,
                    'error' => $errorMessage,
                    'response' => $responseData
                ]);

                return [
                    'success' => false,
                    'message' => 'Withdrawal failed: ' . $errorMessage
                ];
            }
        } catch (\Exception $e) {
            // Handle exception
            $withdrawal->status = 'failed';
            $withdrawal->reject_reason = 'System error: ' . $e->getMessage();
            $withdrawal->save();

            Log::error('Error processing guide withdrawal: ' . $e->getMessage(), [
                'withdrawal_id' => $withdrawal->id,
                'guide_id' => $withdrawal->guide_id,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    // Check withdrawal status from Midtrans
    public function checkWithdrawalStatus(GuideWithdrawal $withdrawal)
    {
        try {
            if (!$withdrawal->transaction_id) {
                return [
                    'success' => false,
                    'message' => 'No transaction ID found'
                ];
            }

            // Use Midtrans Iris API to check payout status
            $url = config('services.midtrans.is_production')
                ? "https://app.midtrans.com/iris/api/v1/payouts/{$withdrawal->transaction_id}"
                : "https://app.sandbox.midtrans.com/iris/api/v1/payouts/{$withdrawal->transaction_id}";

            $response = Http::withBasicAuth(config('services.midtrans.server_key'), '')
                ->get($url);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['status'])) {
                $payoutStatus = $responseData['status'];

                // Update withdrawal status based on Midtrans status
                if ($payoutStatus === 'completed') {
                    $withdrawal->status = 'processed';
                    $withdrawal->processed_at = now();
                } elseif ($payoutStatus === 'failed') {
                    $withdrawal->status = 'failed';
                    $withdrawal->reject_reason = 'Payout failed at Midtrans';
                } elseif (in_array($payoutStatus, ['queued', 'processing'])) {
                    $withdrawal->status = 'processing';
                }

                $withdrawal->save();

                return [
                    'success' => true,
                    'status' => $withdrawal->status,
                    'midtrans_status' => $payoutStatus,
                    'data' => $responseData
                ];
            } else {
                // Handle error
                $errorMessage = $responseData['error_message'] ?? 'Unknown error';

                Log::error('Error checking withdrawal status from Midtrans', [
                    'withdrawal_id' => $withdrawal->id,
                    'transaction_id' => $withdrawal->transaction_id,
                    'error' => $errorMessage,
                    'response' => $responseData
                ]);

                return [
                    'success' => false,
                    'message' => 'Error checking status: ' . $errorMessage
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception checking withdrawal status: ' . $e->getMessage(), [
                'withdrawal_id' => $withdrawal->id,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    // Update the handleCallback method to process guide earnings
    public function handleCallback($notification)
    {
        $transaction = $notification->transaction_status;
        $type = $notification->payment_type;
        $orderId = $notification->order_id;
        $fraud = $notification->fraud_status;

        $payment = Payment::where('invoice_number', $orderId)->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        if ($transaction == 'capture') {
            if ($type == 'credit_card') {
                if ($fraud == 'challenge') {
                    $payment->status = 'pending';
                } else {
                    $payment->status = 'paid';
                    $payment->paid_at = now();
                    $this->confirmBooking($payment->booking);
                    // Process guide earnings
                    $this->processGuideEarnings($payment);
                }
            }
        } else if ($transaction == 'settlement') {
            $payment->status = 'paid';
            $payment->paid_at = now();
            $this->confirmBooking($payment->booking);
            // Process guide earnings
            $this->processGuideEarnings($payment);
        } else if ($transaction == 'pending') {
            $payment->status = 'pending';
        } else if ($transaction == 'deny') {
            $payment->status = 'failed';
        } else if ($transaction == 'expire') {
            $payment->status = 'expired';
        } else if ($transaction == 'cancel') {
            $payment->status = 'failed';
        }

        $payment->save();

        return response()->json(['success' => true]);
    }

    private function confirmBooking(Booking $booking)
    {
        $booking->status = 'confirmed';
        $booking->save();

        // Check if trip is now full
        $trip = $booking->trip;
        $confirmedBookings = $trip->bookings()->where('status', 'confirmed')->count();

        if ($confirmedBookings >= $trip->capacity) {
            $trip->status = 'full';
            $trip->save();
        }
    }
    // Add this method to your PaymentService class
    public function checkTransactionStatus($orderId)
    {
        try {
            // Set Midtrans configuration
            \Midtrans\Config::$serverKey = config('services.midtrans.server_key');
            \Midtrans\Config::$isProduction = config('services.midtrans.is_production');

            // Get transaction status from Midtrans
            $status = \Midtrans\Transaction::status($orderId);

            Log::info('Midtrans transaction status:', ['status' => $status]);
            return $status;
        } catch (\Exception $e) {
            Log::error('Midtrans status check error: ' . $e->getMessage());
            throw $e;
        }
    }
}
