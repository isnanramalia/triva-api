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
    /**
     * List transaksi 1 trip (pagination bawaan).
     * GET /api/trips/{trip}/transactions?per_page=20&page=1
     */
    public function index(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            if (!$trip->members()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            $perPage = (int) $request->query('per_page', 20);
            if ($perPage > 100) {
                $perPage = 100;
            } elseif ($perPage < 1) {
                $perPage = 20;
            }

            $transactions = $trip->transactions()
                ->with(['paidBy', 'splits.member'])
                ->orderByDesc('date')
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data'   => $transactions, // paginator object
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create transaksi baru.
     */
    public function store(Request $request, Trip $trip, TripBalanceService $balanceService)
    {
        try {
            $user = $request->user();

            $member = $trip->members()->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            $data = $request->validate([
                'title'              => 'required|string|max:255',
                'description'        => 'nullable|string',
                'date'               => 'required|date',
                'total_amount'       => 'required|numeric|min:0',
                'paid_by_member_id'  => 'required|exists:trip_members,id',
                'split_type'         => 'required|string',
                'splits'             => 'required|array|min:1',
                'splits.*.member_id' => 'required|exists:trip_members,id',
                'splits.*.amount'    => 'required|numeric|min:0',
            ]);

            // Validasi: paid_by + semua splits harus bagian dari trip
            $memberIds = $trip->members()->pluck('id')->toArray();

            if (!in_array($data['paid_by_member_id'], $memberIds, true)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'paid_by_member_id does not belong to this trip',
                ], 422);
            }

            $splitMemberIds = collect($data['splits'])->pluck('member_id')->unique()->all();
            foreach ($splitMemberIds as $mid) {
                if (!in_array($mid, $memberIds, true)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'One or more split members do not belong to this trip',
                    ], 422);
                }
            }

            $sumSplits = collect($data['splits'])->sum('amount');
            if (abs($sumSplits - $data['total_amount']) > 0.01) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Total splits must equal to total amount',
                    'detail'  => [
                        'expected' => $data['total_amount'],
                        'actual'   => $sumSplits,
                    ],
                ], 422);
            }

            $tx = DB::transaction(function () use ($trip, $member, $data, $balanceService) {
                $transaction = Transaction::create([
                    'trip_id'              => $trip->id,
                    'created_by_member_id' => $member->id,
                    'paid_by_member_id'    => $data['paid_by_member_id'],
                    'title'                => $data['title'],
                    'description'          => $data['description'] ?? null,
                    'date'                 => $data['date'],
                    'total_amount'         => $data['total_amount'],
                    'split_type'           => $data['split_type'],
                    'meta'                 => null,
                ]);

                foreach ($data['splits'] as $split) {
                    TransactionSplit::create([
                        'transaction_id' => $transaction->id,
                        'member_id'      => $split['member_id'],
                        'amount'         => $split['amount'],
                    ]);
                }

                $balanceService->recalculate($trip);

                return $transaction;
            });

            return response()->json([
                'status' => 'success',
                'data'   => $tx->load(['paidBy', 'splits.member']),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error.',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update transaksi.
     */
    public function update(
        Request $request,
        Trip $trip,
        Transaction $transaction,
        TripBalanceService $balanceService
    ) {
        try {
            $user = $request->user();

            if ($transaction->trip_id !== $trip->id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Transaction does not belong to this trip',
                ], 404);
            }

            $member = $trip->members()->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            $data = $request->validate([
                'title'              => 'sometimes|required|string|max:255',
                'description'        => 'nullable|string',
                'date'               => 'sometimes|required|date',
                'total_amount'       => 'sometimes|required|numeric|min:0',
                'paid_by_member_id'  => 'sometimes|required|exists:trip_members,id',
                'split_type'         => 'sometimes|required|string',
                'splits'             => 'sometimes|required|array|min:1',
                'splits.*.member_id' => 'required_with:splits|exists:trip_members,id',
                'splits.*.amount'    => 'required_with:splits|numeric|min:0',
            ]);

            $memberIds = $trip->members()->pluck('id')->toArray();

            if (array_key_exists('paid_by_member_id', $data) &&
                !in_array($data['paid_by_member_id'], $memberIds, true)
            ) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'paid_by_member_id does not belong to this trip',
                ], 422);
            }

            if (isset($data['splits'])) {
                $splitMemberIds = collect($data['splits'])->pluck('member_id')->unique()->all();
                foreach ($splitMemberIds as $mid) {
                    if (!in_array($mid, $memberIds, true)) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'One or more split members do not belong to this trip',
                        ], 422);
                    }
                }

                $newTotal  = $data['total_amount'] ?? $transaction->total_amount;
                $sumSplits = collect($data['splits'])->sum('amount');

                if (abs($sumSplits - $newTotal) > 0.01) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Total splits must equal total amount',
                        'detail'  => [
                            'expected' => $newTotal,
                            'actual'   => $sumSplits,
                        ],
                    ], 422);
                }
            }

            DB::transaction(function () use ($trip, $transaction, $data, $balanceService) {

                $transaction->fill([
                    'title'             => $data['title']        ?? $transaction->title,
                    'description'       => array_key_exists('description', $data) ? $data['description'] : $transaction->description,
                    'date'              => $data['date']         ?? $transaction->date,
                    'total_amount'      => $data['total_amount'] ?? $transaction->total_amount,
                    'split_type'        => $data['split_type']   ?? $transaction->split_type,
                    'paid_by_member_id' => $data['paid_by_member_id'] ?? $transaction->paid_by_member_id,
                ]);

                $transaction->save();

                if (isset($data['splits'])) {
                    $transaction->splits()->delete();

                    foreach ($data['splits'] as $split) {
                        TransactionSplit::create([
                            'transaction_id' => $transaction->id,
                            'member_id'      => $split['member_id'],
                            'amount'         => $split['amount'],
                        ]);
                    }
                }

                $balanceService->recalculate($trip);
            });

            return response()->json([
                'status' => 'success',
                'data'   => $transaction->load(['paidBy', 'splits.member']),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error.',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete transaksi.
     */
    public function destroy(
        Request $request,
        Trip $trip,
        Transaction $transaction,
        TripBalanceService $balanceService
    ) {
        try {
            $user = $request->user();

            if ($transaction->trip_id !== $trip->id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Transaction does not belong to this trip',
                ], 404);
            }

            $member = $trip->members()->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            DB::transaction(function () use ($trip, $transaction, $balanceService) {
                $transaction->splits()->delete();
                $transaction->delete();

                $balanceService->recalculate($trip);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Transaction deleted',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error.',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
