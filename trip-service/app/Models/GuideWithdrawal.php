<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuideWithdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'guide_id',
        'amount',
        'bank_name',
        'account_number',
        'account_name',
        'status',
        'transaction_id',
        'reference_number',
        'notes',
        'reject_reason',
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
}
