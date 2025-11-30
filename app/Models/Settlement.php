<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'from_member_id',
        'to_member_id',
        'amount',
        'status',
        'created_by_member_id',
        'confirmed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    // === RELATIONSHIPS ===

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function fromMember()
    {
        return $this->belongsTo(TripMember::class, 'from_member_id');
    }

    public function toMember()
    {
        return $this->belongsTo(TripMember::class, 'to_member_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(TripMember::class, 'created_by_member_id');
    }
}
