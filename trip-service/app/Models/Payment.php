<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'payment_id',
        'invoice_number',
        'order_id',           // Add this
        'transaction_id',     // Add this
        'amount',
        'status',
        'payment_method',
        'payment_url',
        'paid_at',
        'expired_at'
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
