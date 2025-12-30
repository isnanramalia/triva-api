<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\Transaction;
use App\Models\TransactionSplit;
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
                    'status' => 'error',
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
                'data' => $transactions, // paginator object
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create transaksi baru.
     */
    public function store(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            $member = $trip->members()->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            $data = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'emoji' => 'nullable|string',
                'date' => 'required|date',
                'total_amount' => 'required|numeric|min:0',
                'paid_by_member_id' => 'required|exists:trip_members,id',
                'split_type' => 'required|string',
                'splits' => 'required|array|min:1',
                'splits.*.member_id' => 'required|exists:trip_members,id',
                'splits.*.amount' => 'required|numeric|min:0',
            ]);

            // Validasi: paid_by + semua splits harus bagian dari trip
            $memberIds = $trip->members()->pluck('id')->toArray();

            if (!in_array($data['paid_by_member_id'], $memberIds, true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'paid_by_member_id does not belong to this trip',
                ], 422);
            }

            $splitMemberIds = collect($data['splits'])->pluck('member_id')->unique()->all();
            foreach ($splitMemberIds as $mid) {
                if (!in_array($mid, $memberIds, true)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'One or more split members do not belong to this trip',
                    ], 422);
                }
            }

            $sumSplits = collect($data['splits'])->sum('amount');
            if (abs($sumSplits - $data['total_amount']) > 0.01) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Total splits must equal to total amount',
                    'detail' => [
                        'expected' => $data['total_amount'],
                        'actual' => $sumSplits,
                    ],
                ], 422);
            }

            $tx = DB::transaction(function () use ($trip, $member, $data) {
                $transaction = Transaction::create([
                    'trip_id' => $trip->id,
                    'created_by_member_id' => $member->id,
                    'paid_by_member_id' => $data['paid_by_member_id'],
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'emoji' => $data['emoji'] ?? null,
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

                // $balanceService->recalculate($trip);

                return $transaction;
            });

            return response()->json([
                'status' => 'success',
                'data' => $tx->load(['paidBy', 'splits.member']),
            ], 201);

        } catch (ValidationException $e) {

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

    /**
     * Update transaksi.
     */
    public function update(
        Request $request,
        Trip $trip,
        Transaction $transaction
    ) {
        try {
            $user = $request->user();

            if ($transaction->trip_id !== $trip->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction does not belong to this trip',
                ], 404);
            }

            $member = $trip->members()->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            $data = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'emoji' => 'nullable|string',
                'date' => 'sometimes|required|date',
                'total_amount' => 'sometimes|required|numeric|min:0',
                'paid_by_member_id' => 'sometimes|required|exists:trip_members,id',
                'split_type' => 'sometimes|required|string',
                'splits' => 'sometimes|required|array|min:1',
                'splits.*.member_id' => 'required_with:splits|exists:trip_members,id',
                'splits.*.amount' => 'required_with:splits|numeric|min:0',
            ]);

            $memberIds = $trip->members()->pluck('id')->toArray();

            if (
                array_key_exists('paid_by_member_id', $data) &&
                !in_array($data['paid_by_member_id'], $memberIds, true)
            ) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'paid_by_member_id does not belong to this trip',
                ], 422);
            }

            if (isset($data['splits'])) {
                $splitMemberIds = collect($data['splits'])->pluck('member_id')->unique()->all();
                foreach ($splitMemberIds as $mid) {
                    if (!in_array($mid, $memberIds, true)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'One or more split members do not belong to this trip',
                        ], 422);
                    }
                }

                $newTotal = $data['total_amount'] ?? $transaction->total_amount;
                $sumSplits = collect($data['splits'])->sum('amount');

                if (abs($sumSplits - $newTotal) > 0.01) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Total splits must equal total amount',
                        'detail' => [
                            'expected' => $newTotal,
                            'actual' => $sumSplits,
                        ],
                    ], 422);
                }
            }

            DB::transaction(function () use ($trip, $transaction, $data) {

                $transaction->fill([
                    'title' => $data['title'] ?? $transaction->title,
                    'description' => array_key_exists('description', $data) ? $data['description'] : $transaction->description,
                    'emoji' => array_key_exists('emoji', $data) ? $data['emoji'] : $transaction->emoji,
                    'date' => $data['date'] ?? $transaction->date,
                    'total_amount' => $data['total_amount'] ?? $transaction->total_amount,
                    'split_type' => $data['split_type'] ?? $transaction->split_type,
                    'paid_by_member_id' => $data['paid_by_member_id'] ?? $transaction->paid_by_member_id,
                ]);

                $transaction->save();

                if (isset($data['splits'])) {
                    $transaction->splits()->delete();

                    foreach ($data['splits'] as $split) {
                        TransactionSplit::create([
                            'transaction_id' => $transaction->id,
                            'member_id' => $split['member_id'],
                            'amount' => $split['amount'],
                        ]);
                    }
                }

                // $balanceService->recalculate($trip);
            });

            return response()->json([
                'status' => 'success',
                'data' => $transaction->load(['paidBy', 'splits.member']),
            ]);

        } catch (ValidationException $e) {

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

    /**
     * Delete transaksi.
     */
    public function destroy(
        Request $request,
        Trip $trip,
        Transaction $transaction
    ) {
        try {
            $user = $request->user();

            if ($transaction->trip_id !== $trip->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction does not belong to this trip',
                ], 404);
            }

            $member = $trip->members()->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            DB::transaction(function () use ($trip, $transaction) {
                $transaction->splits()->delete();
                $transaction->delete();

                // $balanceService->recalculate($trip);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction deleted',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(Request $request, Trip $trip, Transaction $transaction)
    {
        try {
            $user = $request->user();

            if (!$trip->members()->where('user_id', $user->id)->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            $transaction->load([
                'paidBy.user',
                'splits.member.user',
                'createdBy.user'
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $transaction
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Kirim Gambar + Data Member ke n8n
     */
    // Tambahkan method ini di TransactionController.php atau TripController.php (sesuai route api 'prepare-ai')

    public function prepareAi(Request $request, $tripId = null) // Ubah jadi $tripId = null biar fleksibel
    {
        try {
            // 1. CARI TRIP ID (Prioritas: URL Route -> Body Request)
            $id = $tripId;

            // Kalau dari URL null (karena binding gagal), cek body request
            if (!$id) {
                $id = $request->input('trip_id');
            }

            // Kalau masih gak ketemu, error
            if (!$id) {
                return response()->json(['status' => 'error', 'message' => 'Trip ID not found in URL or Body'], 400);
            }

            // 2. LOAD MODEL TRIP SECARA MANUAL (Lebih Aman)
            $trip = Trip::with('members.user')->find($id);

            if (!$trip) {
                return response()->json(['status' => 'error', 'message' => 'Trip not found'], 404);
            }

            // 3. CEK MEMBER (Security)
            $user = $request->user();
            if (!$trip->members()->where('user_id', $user->id)->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            // --- SISANYA SAMA SEPERTI SEBELUMNYA ---

            // 4. Proses Gambar (Upload ke Storage Public)
            $imageUrl = null;
            if ($request->hasFile('image')) {
                // Simpan dan generate URL yang bisa diakses n8n
                $path = $request->file('image')->store('ai_uploads', 'public');

                // PENTING: Gunakan asset() agar generate http://localhost.../storage/...
                // Pastikan kamu sudah run: php artisan storage:link
                $imageUrl = asset('storage/' . $path);
            }

            // 5. Build Context Member
            $membersContext = $trip->members->map(function ($m) {
                return [
                    'id' => $m->id,
                    'name' => $m->user ? $m->user->name : $m->guest_name,
                    'first_name' => explode(' ', trim($m->user ? $m->user->name : $m->guest_name))[0]
                ];
            });

            // 6. HIT N8N (Menggunakan URL dari .env)
            $n8nUrl = env('N8N_SMART_ADD_URL');

            // Payload ke n8n
            $payload = [
                'query' => $request->input('query') ?? '',
                'members_context' => $membersContext->toJson(),
                'image_url' => $imageUrl, // Kirim URL gambar ke n8n (bukan binary biar ringan)
                'currency' => $trip->currency_code ?? 'IDR'
            ];

            // Kirim request (Jika ada gambar, n8n akan download dari image_url)
            // Atau jika n8n local, kita bisa kirim binary langsung (opsi sebelumnya)

            // OPSI KIRIM BINARY LANGSUNG (Sesuai kode service Flutter)
            $http = Http::timeout(60);

            if ($request->hasFile('image')) {
                $http->attach(
                    'data', // Key binary n8n
                    file_get_contents($request->file('image')->getRealPath()),
                    $request->file('image')->getClientOriginalName()
                );
            }

            $response = $http->post($n8nUrl, [
                'query' => $request->input('query') ?? '',
                'members_context' => $membersContext->toJson()
            ]);

            if ($response->failed()) {
                \Log::error("N8N Error", ['body' => $response->body()]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'AI Processing Failed: ' . $response->status(),
                    'debug_n8n' => $response->body() // Hapus ini di production
                ], 502);
            }

            return response()->json([
                'status' => 'success',
                'data' => $response->json()
            ]);

        } catch (\Exception $e) {
            \Log::error("Controller Error", ['msg' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Server Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Smart Add - simpan transaksi dari hasil AI + mapping user.
     * Endpoint: POST /api/trips/{trip}/transactions/save-ai
     */
    public function saveAi(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            // 1. Validasi Akses
            $creatorMember = $trip->members()->where('user_id', $user->id)->first();
            if (!$creatorMember) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            // 2. Validasi Data
            $data = $request->validate([
                'draft_id' => 'required|string',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'emoji' => 'nullable|string',
                'date' => 'required|date',
                'paid_by_member_id' => 'required|integer',
                'items' => 'required|array|min:1',
                'items.*.name' => 'required|string',
                'items.*.total' => 'required|numeric',
                'items.*.qty' => 'required|numeric',
                'items.*.splits' => 'required|array|min:1',
                'items.*.splits.*.member_id' => 'required|integer',
                'items.*.splits.*.qty' => 'required|numeric', // qty > 0 = porsi spesifik, 0 = bagi rata
                'tax' => 'nullable|numeric|min:0',
                'service_charge' => 'nullable|numeric|min:0',
                'tax_split_mode' => 'nullable|in:proportional,equal',
            ]);

            // 3. Cek Idempotency (Cegah simpan double)
            $existing = Transaction::where('trip_id', $trip->id)
                ->where('meta->draft_id', $data['draft_id'])
                ->first();
            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'data' => $existing->load(['paidBy', 'splits.member']),
                    'message' => 'Transaction already saved.',
                ], 200);
            }

            // 4. LOGIC PERHITUNGAN: THE GOLDEN RULE
            $memberSubtotals = []; // Penampung harga item sebelum pajak
            $tripMemberIds = $trip->members()->pluck('id')->toArray();

            foreach ($data['items'] as $item) {
                $totalPrice = (float) $item['total'];
                $itemQty = (float) $item['qty'];
                $unitPrice = ($itemQty > 0) ? ($totalPrice / $itemQty) : 0;

                $fixedCostTotal = 0;
                $sharedMemberIds = [];

                foreach ($item['splits'] as $split) {
                    $mid = $split['member_id'];

                    // Security: Pastikan member id beneran ada di trip ini
                    if (!in_array($mid, $tripMemberIds))
                        continue;

                    if ($split['qty'] > 0) {
                        // Kasus Porsi Spesifik (Isna makan 1, Budi makan 2)
                        $amount = $split['qty'] * $unitPrice;
                        $memberSubtotals[$mid] = ($memberSubtotals[$mid] ?? 0) + $amount;
                        $fixedCostTotal += $amount;
                    } else {
                        // Kasus Bagi Rata (qty = 0)
                        $sharedMemberIds[] = $mid;
                    }
                }

                // Bagi sisa harga ke member yang 'bagi rata'
                if (count($sharedMemberIds) > 0) {
                    $remainingPrice = max(0, $totalPrice - $fixedCostTotal);
                    $share = $remainingPrice / count($sharedMemberIds);
                    foreach ($sharedMemberIds as $mid) {
                        $memberSubtotals[$mid] = ($memberSubtotals[$mid] ?? 0) + $share;
                    }
                }
            }

            // 5. TAMBAHKAN PAJAK & SERVICE CHARGE
            $tax = (float) ($data['tax'] ?? 0);
            $service = (float) ($data['service_charge'] ?? 0);
            $extraTotal = $tax + $service;
            $finalMemberTotals = $memberSubtotals;

            if ($extraTotal > 0 && !empty($memberSubtotals)) {
                $totalSubtotal = array_sum($memberSubtotals);
                $mode = $data['tax_split_mode'] ?? 'proportional';

                foreach ($memberSubtotals as $mid => $sub) {
                    if ($mode === 'equal') {
                        $finalMemberTotals[$mid] += $extraTotal / count($memberSubtotals);
                    } else {
                        // Proportional: Makin besar jajanmu, makin besar pajaknya
                        $portion = ($totalSubtotal > 0) ? ($sub / $totalSubtotal) : (1 / count($memberSubtotals));
                        $finalMemberTotals[$mid] += $extraTotal * $portion;
                    }
                }
            }

            // 6. SIMPAN KE DATABASE
            $transaction = DB::transaction(function () use ($trip, $creatorMember, $data, $finalMemberTotals, $tax, $service) {
                $tx = Transaction::create([
                    'trip_id' => $trip->id,
                    'created_by_member_id' => $creatorMember->id,
                    'paid_by_member_id' => $data['paid_by_member_id'],
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'emoji' => $data['emoji'] ?? '🍽️',
                    'date' => $data['date'],
                    'total_amount' => array_sum($finalMemberTotals),
                    'split_type' => 'itemized_ai',
                    'meta' => [
                        'draft_id' => $data['draft_id'],
                        'tax' => $tax,
                        'service_charge' => $service,
                        'tax_split_mode' => $data['tax_split_mode'] ?? 'proportional',
                    ],
                ]);

                foreach ($finalMemberTotals as $mid => $amount) {
                    if ($amount <= 0)
                        continue;
                    TransactionSplit::create([
                        'transaction_id' => $tx->id,
                        'member_id' => $mid,
                        'amount' => $amount,
                    ]);
                }

                return $tx;
            });

            return response()->json([
                'status' => 'success',
                'data' => $transaction->load(['paidBy', 'splits.member']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
