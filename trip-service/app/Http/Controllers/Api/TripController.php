<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
class TripController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mountain_id' => 'required|exists:mountains,id',
            'start_date' => 'required|date|after:today',
            'end_date' => 'required|date|after:start_date',
            'capacity' => 'required|integer|min:1',
            'whatsapp_group' => 'required|url',
            'facilities' => 'required',
            'trip_info' => 'required|string',
            'terms_conditions' => 'required|string',
            'price' => 'required|numeric|min:0',
            'images' => 'required|array',
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()
            ], 422);
        }

        try {
            $facilities = is_string($request->facilities) ?
                json_decode($request->facilities, true) :
                $request->facilities;

            $trip = Trip::create([
                'guide_id' => auth()->user()->guide->id,
                'mountain_id' => $request->mountain_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'capacity' => $request->capacity,
                'whatsapp_group' => $request->whatsapp_group,
                'facilities' => $facilities,
                'trip_info' => $request->trip_info,
                'terms_conditions' => $request->terms_conditions,
                'price' => $request->price,
                'status' => 'open'
            ]);

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('trips', 'public');
                    $trip->images()->create(['image_path' => $path]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Trip berhasil dibuat',
                'data' => $trip->load(['images', 'mountain', 'guide.user'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAllTrips()
    {
        try {
            $trips = Trip::with([
                'images',
                'mountain',
                'guide.user'
            ])
            ->available()
            ->latest('start_date')
            ->get();

            return response()->json([
                'success' => true,
                'data' => $trips
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getTripDetail($id)
    {
        try {
            $trip = Trip::with([
                'images',
                'mountain',
                'guide.user',
                'guide' => function($query) {
                    $query->select('id', 'user_id', 'about', 'whatsapp');
                }
            ])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $trip
            ]);
        } catch (\Exception $e) {
            Log::error('Trip detail error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Trip tidak ditemukan'
            ], 404);
        }
    }

    /**
     * Get trips for the authenticated guide
     */
    public function getGuideTrips()
    {
        try {
            $guideId = auth()->user()->guide->id;

            $trips = Trip::with(['images', 'mountain', 'bookings'])
                ->where('guide_id', $guideId)
                ->latest('start_date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $trips
            ]);
        } catch (\Exception $e) {
            Log::error('Guide trips error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark a trip as finished
     */
    public function finishTrip($id)
    {
        try {
            $trip = Trip::with('bookings.payment')->findOrFail($id);

            // Check if the trip belongs to the authenticated guide
            if ($trip->guide_id != auth()->user()->guide->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - This trip does not belong to you'
                ], 403);
            }

            // Check if the trip end date has passed
            if (now() < $trip->end_date) {
                return response()->json([
                    'success' => false,
                    'message' => 'Trip cannot be finished before the end date'
                ], 400);
            }

            // Update trip status - use 'closed' instead of 'completed' to match the ENUM values
            $trip->status = 'closed';  // Changed from 'completed' to 'closed'
            $trip->completed_at = now();
            $trip->save();

            // Process guide earnings for all confirmed bookings
            $processedBookings = 0;
            foreach ($trip->bookings as $booking) {
                if ($booking->status === 'confirmed' && $booking->payment && $booking->payment->status === 'paid') {
                    // Check if earning already exists
                    $existingEarning = \App\Models\GuideEarning::where('booking_id', $booking->id)->first();

                    if (!$existingEarning) {
                        // Calculate guide's share (e.g., 80% of the payment)
                        $guideSharePercentage = config('payment.guide_share_percentage', 80);
                        $guideAmount = ($booking->payment->amount * $guideSharePercentage) / 100;

                        // Create guide earning record
                        $guideEarning = new \App\Models\GuideEarning([
                            'guide_id' => $trip->guide_id,
                            'trip_id' => $trip->id,
                            'booking_id' => $booking->id,
                            'payment_id' => $booking->payment->id,
                            'amount' => $guideAmount,
                            'platform_fee' => $booking->payment->amount - $guideAmount,
                            'status' => 'processed', // Set as processed immediately
                            'processed_at' => now(),
                            'description' => "Payment for trip {$trip->mountain->nama_gunung} ({$booking->invoice_number})"
                        ]);
                        $guideEarning->save();
                        $processedBookings++;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Trip marked as completed successfully. Processed earnings for $processedBookings bookings.",
                'data' => $trip
            ]);

        } catch (\Exception $e) {
            Log::error('Finish trip error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
