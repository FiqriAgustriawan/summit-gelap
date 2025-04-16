<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    protected $fillable = [
        'guide_id',
        'mountain_id',
        'start_date',
        'end_date',
        'capacity',
        'whatsapp_group',
        'facilities',
        'trip_info',
        'terms_conditions',
        'price',
        'status',
        'completed_at'
    ];

    protected $casts = [
        'facilities' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'price' => 'decimal:2'
    ];

    public function guide()
    {
        return $this->belongsTo(Guide::class);
    }

    public function mountain()
    {
        return $this->belongsTo(Mountain::class);
    }

    public function images()
    {
        return $this->hasMany(TripImage::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'open')
                    ->where('start_date', '>', now());
    }
}
