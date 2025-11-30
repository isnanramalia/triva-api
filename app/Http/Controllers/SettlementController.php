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
    /**
     * Suggest simplified settlement instructions (N-Way Split)
     */
    public function suggest(Request $request, Trip $trip)
    {
        try {

            $user = $request->user();

            if (!$trip->members()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden'
                ], 403);
            }

            $members = $trip->members()->get(['id', 'balance']);

            $debtors = [];
            $creditors = [];

            foreach ($members as $m) {
                $balance = (float) $m->balance;

                if ($balance < -0.01) {
                    $debtors[] = [
                        'member_id' => $m->id,
                        'amount' => -$balance
                    ];
                } elseif ($balance > 0.01) {
                    $creditors[] = [
                        'member_id' => $m->id,
                        'amount' => $balance
                    ];
                }
            }

            $instructions = [];

            $i = 0;
            $j = 0;

            while ($i < count($debtors) && $j < count($creditors)) {
                $deb = &$debtors[$i];
                $cred = &$creditors[$j];

                $pay = min($deb['amount'], $cred['amount']);

                if ($pay > 0.01) {
                    $instructions[] = [
                        'from_member_id' => $deb['member_id'],
                        'to_member_id' => $cred['member_id'],
                        'amount' => round($pay, 2)
                    ];

                    $deb['amount'] -= $pay;
                    $cred['amount'] -= $pay;
                }

                if ($deb['amount'] <= 0.01) $i++;
                if ($cred['amount'] <= 0.01) $j++;
            }

            return response()->json([
                'status' => 'success',
                'data' => $instructions
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error.',
                'detail' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    /**
     * Store a confirmed settlement between two members
     */
    public function store(Request $request, Trip $trip, TripBalanceService $balanceService)
    {
        try {

            $user = $request->user();

            $creator = $trip->members()->where('user_id', $user->id)->first();

            if (!$creator) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden'
                ], 403);
            }

            $data = $request->validate([
                'from_member_id' => 'required|exists:trip_members,id',
                'to_member_id' => 'required|exists:trip_members,id|different:from_member_id',
                'amount' => 'required|numeric|min:0.01',
            ]);

            $settlement = DB::transaction(function () use ($trip, $data, $creator, $balanceService) {

                $record = Settlement::create([
                    'trip_id' => $trip->id,
                    'from_member_id' => $data['from_member_id'],
                    'to_member_id' => $data['to_member_id'],
                    'amount' => $data['amount'],
                    'status' => 'confirmed', // MVP langsung confirmed
                    'created_by_member_id' => $creator->id,
                    'confirmed_at' => now(),
                ]);

                $balanceService->recalculate($trip);

                return $record;
            });

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
