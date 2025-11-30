<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripMember;
use App\Models\Transaction;
use App\Models\Settlement;

class TripBalanceService
{
    public function recalculate(Trip $trip): void
    {
        // reset semua balance ke 0
        TripMember::where('trip_id', $trip->id)->update(['balance' => 0]);

        // proses semua transaksi
        $transactions = Transaction::with('splits')
            ->where('trip_id', $trip->id)
            ->get();

        foreach ($transactions as $tx) {
            // +total untuk payer
            TripMember::where('id', $tx->paid_by_member_id)
                ->increment('balance', $tx->total_amount);

            // -amount untuk setiap member yang ikut split
            foreach ($tx->splits as $split) {
                TripMember::where('id', $split->member_id)
                    ->decrement('balance', $split->amount);
            }
        }

        // proses semua settlement yang sudah dikonfirmasi
        $settlements = Settlement::where('trip_id', $trip->id)
            ->where('status', 'confirmed')
            ->get();

        foreach ($settlements as $st) {
            TripMember::where('id', $st->from_member_id)
                ->increment('balance', $st->amount);

            TripMember::where('id', $st->to_member_id)
                ->decrement('balance', $st->amount);
        }
    }
}
