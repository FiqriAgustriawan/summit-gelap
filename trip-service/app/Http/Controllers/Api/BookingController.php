<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Trip;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function bookTrip(Request $request, $tripId)
    {
        try {
            $trip = Trip::findOrFail($tripId);

            // Check existing booking
            $existingBooking = Booking::where('user_id', auth()->id())
                ->where('trip_id', $trip->id)
                ->with('payment')
                ->first();

            if ($existingBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a booking for this trip',
                    'data' => [
                        'booking_id' => $existingBooking->id,
                        'payment_url' => $existingBooking->payment?->payment_url,
                        'status' => $existingBooking->status
                    ]
                ], 400);
            }

            // Create new booking
            $booking = Booking::create([
                'user_id' => auth()->id(),
                'trip_id' => $trip->id,
                'status' => 'pending'
            ]);

            // Create payment
            $payment = $this->paymentService->createPayment($booking);

            return response()->json([
                'success' => true,
                'message' => 'Booking created successfully',
                'data' => [
                    'booking_id' => $booking->id,
                    'payment_url' => $payment->payment_url
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Booking error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error processing booking: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getUserBookings()
    {
        try {
            $bookings = Booking::with([
                'trip.mountain',
                'trip.guide.user',
                'trip.images',  // Add images relation
                'payment'
            ])
                ->where('user_id', auth()->id())
                ->latest()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $bookings
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get bookings for a guide's trip
     */
    public function getGuideBookings(Request $request)
    {
        try {
            $guideId = auth()->user()->guide->id;
            $tripId = $request->query('trip_id');

            $query = Booking::with([
                'user',
                'payment',
                'trip.mountain'
            ])
            ->whereHas('trip', function($query) use ($guideId) {
                $query->where('guide_id', $guideId);
            });

            if ($tripId) {
                $query->where('trip_id', $tripId);
            }

            $bookings = $query->latest()->get();

            // Transform the bookings data to include calculated fields
            $transformedBookings = $bookings->map(function($booking) {
                $totalPrice = 0;
                if ($booking->payment && $booking->payment->amount) {
                    $totalPrice = $booking->payment->amount;
                } elseif ($booking->trip && $booking->participants) {
                    $totalPrice = $booking->trip->price * $booking->participants;
                }

                return [
                    'id' => $booking->id,
                    'user' => $booking->user,
                    'trip' => $booking->trip,
                    'status' => $booking->status,
                    'participants' => $booking->participants ?? 1,
                    'created_at' => $booking->created_at,
                    'payment' => $booking->payment,
                    'total_price' => $totalPrice,
                    'invoice_number' => $booking->payment ? $booking->payment->invoice_number : null
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformedBookings
            ]);
        } catch (\Exception $e) {
            Log::error('Guide bookings error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    // Add new method to check payment status
    public function checkPaymentStatus($bookingId)
    {
        try {
            $booking = Booking::with('payment')
                ->where('id', $bookingId)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            if (!$booking->payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'No payment found for this booking'
                ], 404);
            }

            // Get the invoice number
            $invoiceNumber = $booking->payment->invoice_number;

            // Check status from Midtrans
            $status = $this->paymentService->checkTransactionStatus($invoiceNumber);

            Log::info('Payment status check for booking', [
                'booking_id' => $bookingId,
                'invoice_number' => $invoiceNumber,
                'status' => $status
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $status->transaction_status ?? 'unknown',
                    'booking_status' => $booking->status,
                    'payment_status' => $booking->payment->status,
                    'invoice_number' => $invoiceNumber
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error checking payment status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error checking payment status: ' . $e->getMessage()
            ], 500);
        }
    }

    // Add method to get payment URL
    public function getPaymentUrl($bookingId)
    {
        try {
            $booking = Booking::with('payment')
                ->where('id', $bookingId)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            if (!$booking->payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'No payment found for this booking'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_url' => $booking->payment->payment_url,
                    'invoice_number' => $booking->payment->invoice_number
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error getting payment URL: ' . $e->getMessage()
            ], 500);
        }
    }

    // Add method to update payment status after verification
    public function updatePaymentStatus($bookingId, Request $request)
    {
        try {
            $booking = Booking::with('payment')
                ->where('id', $bookingId)
                ->firstOrFail();

            $status = $request->input('status');

            if ($status === 'paid') {
                $booking->status = 'confirmed';
                $booking->save();

                if ($booking->payment) {
                    $booking->payment->status = 'paid';
                    $booking->payment->paid_at = now();
                    $booking->payment->save();
                }

                Log::info('Payment status updated manually', [
                    'booking_id' => $bookingId,
                    'status' => $status
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => [
                    'booking_status' => $booking->status,
                    'payment_status' => $booking->payment ? $booking->payment->status : null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating payment status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating payment status: ' . $e->getMessage()
            ], 500);
        }
    }
}
