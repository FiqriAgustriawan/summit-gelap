<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Guide extends Model
{
    protected $fillable = [
        'user_id',
        'phone_number',
        'ktp_image',
        'status',
        'whatsapp',
        'instagram',
        'about',
        'suspended_until',
        'suspension_reason'
    ];

    protected $casts = [
        'suspended_until' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Add trips relationship
    public function trips()
    {
        return $this->hasMany(Trip::class);
    }

    public function isSuspended()
    {
        return $this->suspended_until !== null && $this->suspended_until > now();
    }
}
