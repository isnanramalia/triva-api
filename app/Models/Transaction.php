<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'created_by_member_id',
        'paid_by_member_id',
        'title',
        'description',
        'date',
        'total_amount',
        'split_type',
        'meta',
    ];

    protected $casts = [
        'date'        => 'datetime',
        'total_amount'=> 'decimal:2',
        'meta'        => 'array',
    ];

    // === RELATIONSHIPS ===

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(TripMember::class, 'created_by_member_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(TripMember::class, 'paid_by_member_id');
    }

    public function items()
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function splits()
    {
        return $this->hasMany(TransactionSplit::class);
    }
}
