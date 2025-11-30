<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TransactionSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'member_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // === RELATIONSHIPS ===

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function member()
    {
        return $this->belongsTo(TripMember::class, 'member_id');
    }
}
