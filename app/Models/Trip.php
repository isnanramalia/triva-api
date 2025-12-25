<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'emoji',
        'cover_url',
        'currency_code',
        'start_date',
        'end_date',
        'public_summary_token',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // === RELATIONSHIPS ===

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->hasMany(TripMember::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }
}
