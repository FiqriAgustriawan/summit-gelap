<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuideEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'guide_id',
        'payment_id',
        'booking_id',
        'trip_id',
        'amount',
        'status',
        'description',
        'processed_at'
    ];

    protected $casts = [
        'amount' => 'float',
        'processed_at' => 'datetime'
    ];

    // Relationships
    public function guide()
    {
        return $this->belongsTo(User::class, 'guide_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }
}
