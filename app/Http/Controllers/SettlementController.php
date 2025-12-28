<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripMember;
use App\Models\Settlement;
use App\Services\TripBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettlementController extends Controller
{
    public function suggest(Request $request, Trip $trip)
    {
        // ... (auth check) ...
        $user = $request->user();
        if (!$trip->members()->where('user_id', $user->id)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $allDebts = (new TripBalanceService())->calculateTripBalances($trip->id);

        // Filter: Hanya ambil yang statusnya 'unpaid' (remaining > 0)
        $activeDebts = collect($allDebts)->filter(function ($d) {
            return $d['remaining_amount'] > 1; // Filter sisa hutang > 1 perak
        });

        $involvedMemberIds = $activeDebts->flatMap(fn($d) => [$d['from_member_id'], $d['to_member_id']])->unique();
        $members = TripMember::with('user')->whereIn('id', $involvedMemberIds)->get()->keyBy('id');

        $suggestions = $activeDebts->map(function ($d) use ($members) {
            $from = $members[$d['from_member_id']] ?? null;
            $to = $members[$d['to_member_id']] ?? null;

            return [
                'from_member_id' => $d['from_member_id'],
                'from_name' => $from?->user ? $from->user->name : $from?->guest_name,
                'to_member_id' => $d['to_member_id'],
                'to_name' => $to?->user ? $to->user->name : $to?->guest_name,
                'amount' => $d['remaining_amount'] // Suggest sisa hutang
            ];
        })->values(); // Reset keys

        return response()->json([
            'status' => 'success',
            'data' => $suggestions
        ]);
    }

    /**
     * Store confirmed settlement
     */
    public function store(Request $request, Trip $trip)
    {
        try {
            $data = $request->validate([
                'from_member_id' => 'required|exists:trip_members,id',
                'to_member_id' => 'required|exists:trip_members,id',
                'amount' => 'required|numeric|min:0.01',
            ]);

            $user = $request->user();

            // 1. Validasi: Apakah User adalah Member Trip ini?
            if (!$trip->members()->where('user_id', $user->id)->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            // 2. Ambil Data Member Pengirim (Debtor)
            $fromMember = TripMember::find($data['from_member_id']);

            // 3. LOGIC ACCESS CONTROL (UPDATED) ✅
            // Akses diberikan jika:
            // - User adalah Trip Owner (Admin)
            // - ATAU User adalah orang yang berhutang (Debtor)
            // - ATAU User adalah orang yang dihutangi (Creditor) -> Opsional, biasanya Creditor yang confirm, tapi di sini kita pakai logic "Create Settlement"

            $isTripOwner = $trip->owner_id === $user->id;
            $isDebtor = $fromMember->user_id === $user->id; // Hati-hati, Guest user_id-nya null

            if (!$isTripOwner && !$isDebtor) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only the debtor or trip admin can mark this as paid.'
                ], 403);
            }

            // Validasi kedua member milik trip
            $memberIds = $trip->members()->pluck('id')->toArray();
            if (
                !in_array($data['from_member_id'], $memberIds, true) ||
                !in_array($data['to_member_id'], $memberIds, true)
            ) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Members do not belong to this trip'
                ], 422);
            }

            $settlement = Settlement::create([
                'trip_id' => $trip->id,
                'from_member_id' => $data['from_member_id'],
                'to_member_id' => $data['to_member_id'],
                'amount' => $data['amount'],
                'status' => 'confirmed',
                'created_by_member_id' => $user->tripMembers()->where('trip_id', $trip->id)->first()->id, // Record siapa yang klik
                'confirmed_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $settlement->load(['fromMember', 'toMember'])
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
