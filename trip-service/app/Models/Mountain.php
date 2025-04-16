<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mountain extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama_gunung',
        'lokasi',
        'link_map',
        'ketinggian',
        'status_gunung',
        'status_pendakian',
        'deskripsi',
        'peraturan'
    ];

    protected $casts = [
        'peraturan' => 'array'
    ];

    public function images()
    {
        return $this->hasMany(MountainImage::class);
    }
}
