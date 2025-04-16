<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'nama_depan',
        'nama_belakang',
        'email',
        'password',
        'is_admin',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
    ];

    // Tambahkan accessor untuk memastikan is_admin selalu boolean
    public function getIsAdminAttribute($value)
    {
        return (bool) $value;
    }

    // Tambahkan method untuk cek role
    public function hasRole($role)
    {
        if ($role === 'admin') {
            return (bool) $this->is_admin;
        }

        if ($role === 'guide') {
            return $this->guide()->exists();
        }

        return false;
    }

    public function guide()
    {
        return $this->hasOne(Guide::class);
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
