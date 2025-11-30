<?php

namespace Database\Seeders;

use App\Models\Trip;
use App\Models\TripMember;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\TripBalanceService;
use Illuminate\Database\Seeder;

class DemoTrivaSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Buat 3 user beneran: 1 owner + 2 member
        $owner = User::factory()->create([
            'name'  => 'Owner Triva',
            'email' => 'owner@triva.test',
        ]);

        $friend1 = User::factory()->create([
            'name'  => 'Andi',
            'email' => 'andi@triva.test',
        ]);

        $friend2 = User::factory()->create([
            'name'  => 'Budi',
            'email' => 'budi@triva.test',
        ]);

        // 2) Buat 1 Trip
        $trip = Trip::create([
            'owner_id'      => $owner->id,
            'name'          => 'Bali Trip 2025',
            'description'   => 'Liburan bareng geng kampus ke Bali',
            'currency_code' => 'IDR',
            'start_date'    => '2025-12-01',
            'end_date'      => '2025-12-05',
            'status'        => 'ongoing',
        ]);

        // 3) Daftarkan 5 orang jadi trip_members:
        //    1 owner, 2 user member, 2 guest

        // Owner → admin
        $ownerMember = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $owner->id,
            'role'    => 'admin',
            'balance' => 0,
        ]);

        // Member registered user
        $andiMember = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $friend1->id,
            'role'    => 'member',
            'balance' => 0,
        ]);

        $budiMember = TripMember::create([
            'trip_id' => $trip->id,
            'user_id' => $friend2->id,
            'role'    => 'member',
            'balance' => 0,
        ]);

        // Guest 1
        $pamanBob = TripMember::create([
            'trip_id'      => $trip->id,
            'guest_name'   => 'Paman Bob',
            'guest_contact'=> '081234567890',
            'role'         => 'member',
            'balance'      => 0,
        ]);

        // Guest 2
        $tanteSari = TripMember::create([
            'trip_id'      => $trip->id,
            'guest_name'   => 'Tante Sari',
            'guest_contact'=> '089876543210',
            'role'         => 'member',
            'balance'      => 0,
        ]);

        /**
         * STORY DUMMY:
         *
         * Trip: Bali Trip 2025
         * Orang:
         *  - Owner Triva (owner)      → login utama kamu
         *  - Andi (user)
         *  - Budi (user)
         *  - Paman Bob (guest)
         *  - Tante Sari (guest)
         *
         * Transaksi:
         *  1. Sewa Villa (equal split 5 orang)
         *  2. Makan Seafood (cuma 3 orang: Owner, Andi, Bob)
         *  3. Sewa Motor (Owner + Budi)
         */

        // 4) Transaksi 1: Sewa Villa 1.500.000 dibayar Owner, split rata 5 orang
        $tx1 = Transaction::create([
            'trip_id'              => $trip->id,
            'created_by_member_id' => $ownerMember->id,
            'paid_by_member_id'    => $ownerMember->id,
            'title'                => 'Sewa Villa',
            'description'          => 'Villa 1 malam di Kuta',
            'date'                 => '2025-12-01 14:00:00',
            'total_amount'         => 1500000,
            'split_type'           => 'equal',
            'meta'                 => null,
        ]);

        $shareVilla = 1500000 / 5; // 300.000 per orang

        TransactionSplit::insert([
            [
                'transaction_id' => $tx1->id,
                'member_id'      => $ownerMember->id,
                'amount'         => $shareVilla,
            ],
            [
                'transaction_id' => $tx1->id,
                'member_id'      => $andiMember->id,
                'amount'         => $shareVilla,
            ],
            [
                'transaction_id' => $tx1->id,
                'member_id'      => $budiMember->id,
                'amount'         => $shareVilla,
            ],
            [
                'transaction_id' => $tx1->id,
                'member_id'      => $pamanBob->id,
                'amount'         => $shareVilla,
            ],
            [
                'transaction_id' => $tx1->id,
                'member_id'      => $tanteSari->id,
                'amount'         => $shareVilla,
            ],
        ]);

        // 5) Transaksi 2: Makan Seafood 450.000, dibayar Andi, yang ikut:
        //    Owner, Andi, Paman Bob → masing-masing 150.000
        $tx2 = Transaction::create([
            'trip_id'              => $trip->id,
            'created_by_member_id' => $andiMember->id,
            'paid_by_member_id'    => $andiMember->id,
            'title'                => 'Makan Seafood Jimbaran',
            'description'          => 'Makan malam di tepi pantai',
            'date'                 => '2025-12-01 20:00:00',
            'total_amount'         => 450000,
            'split_type'           => 'equal',
            'meta'                 => null,
        ]);

        $shareSeafood = 450000 / 3; // 150.000

        TransactionSplit::insert([
            [
                'transaction_id' => $tx2->id,
                'member_id'      => $ownerMember->id,
                'amount'         => $shareSeafood,
            ],
            [
                'transaction_id' => $tx2->id,
                'member_id'      => $andiMember->id,
                'amount'         => $shareSeafood,
            ],
            [
                'transaction_id' => $tx2->id,
                'member_id'      => $pamanBob->id,
                'amount'         => $shareSeafood,
            ],
        ]);

        // 6) Transaksi 3: Sewa Motor 200.000, dibayar Budi,
        //    yang pakai hanya Budi dan Owner → 100.000 per orang
        $tx3 = Transaction::create([
            'trip_id'              => $trip->id,
            'created_by_member_id' => $budiMember->id,
            'paid_by_member_id'    => $budiMember->id,
            'title'                => 'Sewa Motor',
            'description'          => 'Sewa 2 motor untuk keliling',
            'date'                 => '2025-12-02 09:00:00',
            'total_amount'         => 200000,
            'split_type'           => 'equal',
            'meta'                 => null,
        ]);

        $shareMotor = 200000 / 2; // 100.000

        TransactionSplit::insert([
            [
                'transaction_id' => $tx3->id,
                'member_id'      => $ownerMember->id,
                'amount'         => $shareMotor,
            ],
            [
                'transaction_id' => $tx3->id,
                'member_id'      => $budiMember->id,
                'amount'         => $shareMotor,
            ],
        ]);

        // 7) Hitung ulang balance berdasarkan semua transaksi di atas
        /** @var TripBalanceService $balanceService */
        $balanceService = app(TripBalanceService::class);
        $balanceService->recalculate($trip);
    }
}
