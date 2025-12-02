<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\TripBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

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

        } catch (ValidationException $e) {

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

        } catch (ValidationException $e) {

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

        /**
     * Smart Add - kirim gambar + konteks trip ke n8n (OCR + LLM).
     * Endpoint: POST /api/trips/{trip}/transactions/prepare-ai
     */
    public function prepareAi(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            // Pastikan user adalah member trip
            $member = $trip->members()->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            // Validasi input
            $data = $request->validate([
                'note'  => 'nullable|string',
                'image' => 'required|image|max:5120', // max 5MB
            ]);

            // Ambil URL webhook n8n dari config/env
            $webhookUrl = config('services.n8n.smart_add_url', env('N8N_SMART_ADD_URL'));
            if (!$webhookUrl) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Smart Add service is not configured (N8N_SMART_ADD_URL).',
                ], 500);
            }

            // Encode gambar ke base64 untuk dikirim ke n8n
            $file = $request->file('image');
            $imageBase64 = base64_encode(file_get_contents($file->getRealPath()));

            // draft_id untuk trace AI request (bisa dipakai di save-ai)
            $draftId = 'ai-' . uniqid('', true);

            // Kirim juga konteks trip + daftar member ke n8n
            $members = $trip->members()->with('user')->get()->map(function ($m) {
                return [
                    'id'   => $m->id,
                    'name' => $m->user?->name ?? $m->guest_name,
                    'type' => $m->user_id ? 'user' : 'guest',
                ];
            })->values()->all();

            $payload = [
                'draft_id'               => $draftId,
                'trip' => [
                    'id'            => $trip->id,
                    'name'          => $trip->name,
                    'currency_code' => $trip->currency_code,
                ],
                'requested_by_member_id' => $member->id,
                'note'                   => $data['note'] ?? null,
                'image_base64'           => $imageBase64,
                'members'                => $members,
            ];

            $response = Http::timeout(60)->post($webhookUrl, $payload);

            if ($response->failed()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Failed to call Smart Add service.',
                    'detail'  => config('app.debug') ? $response->body() : null,
                ], 502);
            }

            $aiData = $response->json();

            // Normalisasi: pastikan draft_id juga dikembalikan ke Flutter
            if (is_array($aiData)) {
                $aiData['draft_id'] = $draftId;
            } else {
                $aiData = [
                    'draft_id' => $draftId,
                    'raw'      => $aiData,
                ];
            }

            return response()->json([
                'status' => 'success',
                'data'   => $aiData,
            ]);

        } catch (ValidationException $e) {

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
     * Smart Add - simpan transaksi dari hasil AI + mapping user.
     * Endpoint: POST /api/trips/{trip}/transactions/save-ai
     */
    public function saveAi(
        Request $request,
        Trip $trip,
        TripBalanceService $balanceService
    ) {
        try {
            $user = $request->user();

            // Pastikan user adalah member trip
            $creatorMember = $trip->members()->where('user_id', $user->id)->first();
            if (!$creatorMember) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            $data = $request->validate([
                'draft_id'           => 'required|string',
                'title'              => 'required|string|max:255',
                'description'        => 'nullable|string',
                'date'               => 'required|date',
                'paid_by_member_id'  => 'required|integer',
                'items'              => 'required|array|min:1',

                'items.*.name'                => 'required|string',
                'items.*.total'               => 'required|numeric|min:0',
                'items.*.assigned_member_ids' => 'required|array|min:1',
                'items.*.assigned_member_ids.*' => 'required|integer',

                'tax'              => 'nullable|numeric|min:0',
                'service_charge'   => 'nullable|numeric|min:0',
                'tax_split_mode'   => 'nullable|in:proportional,equal',
            ]);

            // Pastikan semua member_id yang dipakai bener-bener milik trip ini
            $tripMemberIds = $trip->members()->pluck('id')->toArray();

            if (!in_array($data['paid_by_member_id'], $tripMemberIds, true)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payer is not a member of this trip.',
                ], 422);
            }

            foreach ($data['items'] as $item) {
                foreach ($item['assigned_member_ids'] as $mid) {
                    if (!in_array($mid, $tripMemberIds, true)) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'One or more assigned members do not belong to this trip.',
                        ], 422);
                    }
                }
            }

            $tax            = $data['tax']            ?? 0;
            $serviceCharge  = $data['service_charge'] ?? 0;
            $taxSplitMode   = $data['tax_split_mode'] ?? 'proportional';

            // Cek apakah draft_id sudah pernah disimpan → idempotent
            $existing = Transaction::where('trip_id', $trip->id)
                ->where('meta->draft_id', $data['draft_id'])
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'data'   => $existing->load(['paidBy', 'splits.member']),
                    'message' => 'Transaction already saved for this draft_id.',
                ], 200);
            }

            // Hitung subtotal per member dari items
            $memberTotals = [];

            foreach ($data['items'] as $item) {
                $totalItem = (float) $item['total'];
                $assigned  = $item['assigned_member_ids'];
                $countAssigned = count($assigned);

                if ($countAssigned < 1 || $totalItem <= 0) {
                    continue;
                }

                $share = $totalItem / $countAssigned;

                foreach ($assigned as $mid) {
                    if (!isset($memberTotals[$mid])) {
                        $memberTotals[$mid] = 0;
                    }
                    $memberTotals[$mid] += $share;
                }
            }

            // Tambahkan tax + service charge
            $extraTotal = $tax + $serviceCharge;

            if ($extraTotal > 0 && count($memberTotals) > 0) {
                $memberIds = array_keys($memberTotals);

                if ($taxSplitMode === 'equal') {
                    $shareExtra = $extraTotal / count($memberIds);
                    foreach ($memberIds as $mid) {
                        $memberTotals[$mid] += $shareExtra;
                    }
                } else {
                    // proportional (default)
                    $subtotalSum = array_sum($memberTotals);
                    if ($subtotalSum <= 0) {
                        // fallback ke equal kalau subtotal 0 (kasus aneh)
                        $shareExtra = $extraTotal / count($memberIds);
                        foreach ($memberIds as $mid) {
                            $memberTotals[$mid] += $shareExtra;
                        }
                    } else {
                        foreach ($memberTotals as $mid => $sub) {
                            $portion = $sub / $subtotalSum;
                            $memberTotals[$mid] += $extraTotal * $portion;
                        }
                    }
                }
            }

            $totalAmount = array_sum($memberTotals);

            if ($totalAmount <= 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Computed total amount must be greater than zero.',
                ], 422);
            }

            $transaction = DB::transaction(function () use (
                $trip,
                $creatorMember,
                $data,
                $memberTotals,
                $totalAmount,
                $tax,
                $serviceCharge,
                $taxSplitMode,
                $balanceService
            ) {
                $tx = Transaction::create([
                    'trip_id'              => $trip->id,
                    'created_by_member_id' => $creatorMember->id,
                    'paid_by_member_id'    => $data['paid_by_member_id'],
                    'title'                => $data['title'],
                    'description'          => $data['description'] ?? null,
                    'date'                 => $data['date'],
                    'total_amount'         => $totalAmount,
                    'split_type'           => 'itemized_ai',
                    'meta'                 => [
                        'draft_id'        => $data['draft_id'],
                        'tax'             => $tax,
                        'service_charge'  => $serviceCharge,
                        'tax_split_mode'  => $taxSplitMode,
                    ],
                ]);

                foreach ($memberTotals as $mid => $amount) {
                    if ($amount <= 0) {
                        continue;
                    }

                    TransactionSplit::create([
                        'transaction_id' => $tx->id,
                        'member_id'      => $mid,
                        'amount'         => $amount,
                    ]);
                }

                // Recalculate balances
                $balanceService->recalculate($trip);

                return $tx;
            });

            return response()->json([
                'status' => 'success',
                'data'   => $transaction->load(['paidBy', 'splits.member']),
            ], 201);

        } catch (ValidationException $e) {

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
}
