<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Settlement;

class TripBalanceService
{
    /**
     * Menghitung status hutang (Total vs Terbayar).
     * Output mencakup yang SUDAH LUNAS (Paid).
     */
    public function calculateTripBalances(int $tripId): array
    {
        // 1. Tampung Data
        $debts = [];    // [creditor][debtor] = total_hutang
        $payments = []; // [creditor][debtor] = total_sudah_dibayar

        // 2. HITUNG TOTAL HUTANG (Dari Transaksi)
        $transactions = Transaction::with(['splits:transaction_id,member_id,amount'])
            ->where('trip_id', $tripId)
            ->get(['id', 'paid_by_member_id']);

        foreach ($transactions as $tx) {
            $payerId = $tx->paid_by_member_id;

            foreach ($tx->splits as $split) {
                $debtorId = $split->member_id;
                $amount = (float) $split->amount;

                if ($payerId === $debtorId)
                    continue;

                if (!isset($debts[$payerId][$debtorId])) {
                    $debts[$payerId][$debtorId] = 0;
                }
                $debts[$payerId][$debtorId] += $amount;
            }
        }

        // 3. HITUNG TOTAL PEMBAYARAN (Dari Settlement)
        $settlements = Settlement::where('trip_id', $tripId)
            ->where('status', 'confirmed')
            ->get(['from_member_id', 'to_member_id', 'amount']);

        foreach ($settlements as $s) {
            $creditorId = $s->to_member_id; // Penerima Uang
            $debtorId = $s->from_member_id; // Pengirim Uang
            $amount = (float) $s->amount;

            if (!isset($payments[$creditorId][$debtorId])) {
                $payments[$creditorId][$debtorId] = 0;
            }
            $payments[$creditorId][$debtorId] += $amount;
        }

        // 4. GABUNGKAN DATA & TENTUKAN STATUS
        $results = [];

        foreach ($debts as $creditorId => $debtorList) {
            foreach ($debtorList as $debtorId => $totalDebt) {

                $totalPaid = $payments[$creditorId][$debtorId] ?? 0;
                $remaining = $totalDebt - $totalPaid;

                // Tentukan status
                // Toleransi floating point 0.01
                if ($remaining <= 0.01) {
                    $status = 'paid';
                    $remaining = 0; // Rapikan minus kecil
                } else {
                    $status = 'unpaid';
                }

                $results[] = [
                    'from_member_id' => $debtorId,
                    'to_member_id' => $creditorId,
                    'total_amount' => $totalDebt,  // Hutang Awal
                    'paid_amount' => $totalPaid,  // Sudah Dibayar
                    'remaining_amount' => $remaining,  // Sisa
                    'status' => $status      // 'paid' | 'unpaid'
                ];
            }
        }

        return $results;
    }
}