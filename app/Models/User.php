<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    // === RELATIONSHIPS ===

    // user sebagai pemilik trip
    public function ownedTrips()
    {
        return $this->hasMany(Trip::class, 'owner_id');
    }

    // user sebagai member banyak trip (via trip_members)
    public function tripMembers()
    {
        return $this->hasMany(TripMember::class);
    }

    // user bisa akses trip yang dia ikuti via relasi hasManyThrough (optional)
    public function trips()
    {
        return $this->hasManyThrough(
            Trip::class,
            TripMember::class,
            'user_id', // FK di trip_members
            'id',      // PK di trips
            'id',      // PK di users
            'trip_id'  // FK di trip_members
        );
    }
}
