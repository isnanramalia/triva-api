<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\TripBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(Request $request, Trip $trip)
    {
        try {

            $user = $request->user();

            if (!$trip->members()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden'
                ], 403);
            }

            $transactions = $trip->transactions()
                ->with(['paidBy', 'splits.member'])
                ->orderByDesc('date')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $transactions
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    public function store(Request $request, Trip $trip, TripBalanceService $balanceService)
    {
        try {

            $user = $request->user();

            // get trip member id for the creator
            $member = $trip->members()->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden'
                ], 403);
            }

            // validate
            $data = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'date' => 'required|date',
                'total_amount' => 'required|numeric|min:0',
                'paid_by_member_id' => 'required|exists:trip_members,id',
                'split_type' => 'required|string',
                'splits' => 'required|array|min:1',
                'splits.*.member_id' => 'required|exists:trip_members,id',
                'splits.*.amount' => 'required|numeric|min:0',
            ]);

            // check split sum
            $sumSplits = collect($data['splits'])->sum('amount');
            if (abs($sumSplits - $data['total_amount']) > 0.01) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Total splits must equal to total amount',
                    'detail' => [
                        'expected' => $data['total_amount'],
                        'actual' => $sumSplits
                    ]
                ], 422);
            }

            $tx = DB::transaction(function () use ($trip, $member, $data, $balanceService) {

                $transaction = Transaction::create([
                    'trip_id' => $trip->id,
                    'created_by_member_id' => $member->id,
                    'paid_by_member_id' => $data['paid_by_member_id'],
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'date' => $data['date'],
                    'total_amount' => $data['total_amount'],
                    'split_type' => $data['split_type'],
                    'meta' => null,
                ]);

                foreach ($data['splits'] as $split) {
                    TransactionSplit::create([
                        'transaction_id' => $transaction->id,
                        'member_id' => $split['member_id'],
                        'amount' => $split['amount'],
                    ]);
                }

                // recalc balance
                $balanceService->recalculate($trip);

                return $transaction;
            });

            return response()->json([
                'status' => 'success',
                'data' => $tx->load(['paidBy', 'splits.member'])
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
