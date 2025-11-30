<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TransactionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'name',
        'unit_price',
        'qty',
        'raw_data',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'raw_data'   => 'array',
    ];

    // === RELATIONSHIPS ===

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
