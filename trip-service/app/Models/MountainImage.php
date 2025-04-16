<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MountainImage extends Model
{
    use HasFactory;

    protected $fillable = ['mountain_id', 'image_path'];

    public function mountain()
    {
        return $this->belongsTo(Mountain::class);
    }
}