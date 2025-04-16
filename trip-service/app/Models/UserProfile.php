<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'gender',
        'tanggal_lahir',
        'nik',
        'tempat_tinggal',
        'nomor_telepon',
        'profile_image',
        'is_profile_completed'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}