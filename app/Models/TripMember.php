<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TripMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'user_id',
        'guest_name',
        'guest_contact',
        'role',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    // === RELATIONSHIPS ===

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // transaksi yang dia input (bukan yang dia bayar)
    public function createdTransactions()
    {
        return $this->hasMany(Transaction::class, 'created_by_member_id');
    }

    // transaksi yang dia bayar (paid_by)
    public function paidTransactions()
    {
        return $this->hasMany(Transaction::class, 'paid_by_member_id');
    }

    // split yang menjadi tanggungan dia
    public function splits()
    {
        return $this->hasMany(TransactionSplit::class, 'member_id');
    }

    // settlement di mana dia sebagai pengirim
    public function settlementsFrom()
    {
        return $this->hasMany(Settlement::class, 'from_member_id');
    }

    // settlement di mana dia sebagai penerima
    public function settlementsTo()
    {
        return $this->hasMany(Settlement::class, 'to_member_id');
    }

    // settlement yang dia buat (misal admin yang klik "mark as paid")
    public function settlementsCreated()
    {
        return $this->hasMany(Settlement::class, 'created_by_member_id');
    }

    // helper: nama tampilan (user name atau guest_name)
    public function display_name(): string
    {
        if ($this->user) {
            return $this->user->name;
        }

        return $this->guest_name ?? 'Guest #'.$this->id;
    }
}
