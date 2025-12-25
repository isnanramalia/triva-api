<?php

namespace App\Http\Controllers;

use App\Models\Settlement;
use App\Models\Trip;
use App\Models\TripMember;
use App\Models\User;
use App\Services\TripBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TripController extends Controller
{
    /**
     * List trips yang diikuti user (dengan pagination bawaan Laravel).
     * GET /api/trips?per_page=10&page=1
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $perPage = (int) $request->query('per_page', 10);
            if ($perPage > 50) {
                $perPage = 50;
            } elseif ($perPage < 1) {
                $perPage = 10;
            }

            $trips = Trip::whereHas('members', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
                ->withCount('members')
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $trips, // paginator bawaan Laravel
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
     * Create trip baru.
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();

            $data = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'cover_url' => 'nullable|url',
                'currency_code' => 'nullable|string|size:3',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);

            $trip = Trip::create([
                'owner_id' => $user->id,
                'name' => $data['name'],
                'cover_url' => $data['cover_url'] ?? null,
                'emoji' => $data['emoji'] ?? '✈️',
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'IDR',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'status' => 'planning',
                'public_summary_token' => Str::random(32),
            ]);

            TripMember::create([
                'trip_id' => $trip->id,
                'user_id' => $user->id,
                'role' => 'admin',
                'balance' => 0,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $trip,
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
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            // 1. Cek User Member
            if (!$trip->members()->where('user_id', $user->id)->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            // 2. Load Data Lengkap (PERBAIKAN UTAMA DISINI)
            // Kita HAPUS mapping manual yang membuang data splits
            $trip->load([
                'members.user',
                'transactions' => function ($query) {
                    $query->latest()
                        ->limit(20)
                        ->with(['splits', 'paidBy.user']); // ✅ INI WAJIB ADA
                }
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $trip,
            ]);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Internal server error'], 500);
        }
    }

    /**
     * Update trip (hanya owner).
     */
    public function update(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            if ($trip->owner_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only owner can update this trip',
                ], 403);
            }

            $data = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'cover_url' => 'nullable|url',
                'description' => 'nullable|string',
                'currency_code' => 'nullable|string|size:3',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'status' => 'nullable|in:planning,ongoing,finished,cancelled',
            ]);

            $trip->fill($data);
            $trip->save();

            return response()->json([
                'status' => 'success',
                'data' => $trip,
            ]);

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
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete trip (hanya owner).
     */
    public function destroy(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            if ($trip->owner_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only owner can delete this trip',
                ], 403);
            }

            $trip->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Trip deleted',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function myBalances(Request $request, $tripId)
    {
        $user = $request->user();
        $currentMember = TripMember::where('trip_id', $tripId)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $myMemberId = $currentMember->id;

        $allDebts = (new TripBalanceService())->calculateTripBalances($tripId);

        // Kumpulkan ID untuk ambil nama
        $involvedMemberIds = [];
        $myDebts = [];

        foreach ($allDebts as $debt) {
            // Filter: Hanya yang berhubungan dengan saya
            // TAPI: Tetap masukkan yang status='paid' sesuai request kamu

            // A. Orang hutang ke Saya (Owes You)
            if ($debt['to_member_id'] == $myMemberId) {
                $myDebts[] = array_merge($debt, ['display_type' => 'owes_you', 'display_member_id' => $debt['from_member_id']]);
                $involvedMemberIds[] = $debt['from_member_id'];
            }

            // B. Saya hutang ke Orang (You Owe)
            if ($debt['from_member_id'] == $myMemberId) {
                $myDebts[] = array_merge($debt, ['display_type' => 'you_owe', 'display_member_id' => $debt['to_member_id']]);
                $involvedMemberIds[] = $debt['to_member_id'];
            }
        }

        // Ambil Detail Nama
        $members = TripMember::with('user')
            ->whereIn('id', array_unique($involvedMemberIds))
            ->get()
            ->keyBy('id');

        $data = collect($myDebts)->map(function ($item) use ($members) {
            $m = $members[$item['display_member_id']] ?? null;
            $name = $m?->user ? $m->user->name : ($m?->guest_name ?? 'Unknown');

            return [
                'member_id' => $item['display_member_id'],
                'name' => $name,
                'avatar_url' => $m?->user ? 'https://ui-avatars.com/api/?name=' . urlencode($name) : null,
                'type' => $item['display_type'], // 'owes_you' or 'you_owe'
                'amount' => $item['remaining_amount'], // Yang ditampilkan SISA hutang
                'total_amount' => $item['total_amount'],     // Total awal (opsional buat FE)
                'status' => $item['status'],           // 'paid' or 'unpaid'
            ];
        })->values();

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function summary(Request $request, $tripId)
    {
        $allDebts = (new TripBalanceService())->calculateTripBalances($tripId);

        $tripMembers = TripMember::with('user')->where('trip_id', $tripId)->get();
        $memberMap = $tripMembers->keyBy('id');

        // 1. Hitung Net Balance untuk Grafik (Overview)
        // PENTING: Gunakan 'remaining_amount' agar grafik mencerminkan kondisi SEKARANG
        $netBalances = [];
        foreach ($tripMembers as $m)
            $netBalances[$m->id] = 0;

        foreach ($allDebts as $debt) {
            // Jika status paid/remaining 0, tidak mempengaruhi grafik "Siapa menanggung beban saat ini"
            $netBalances[$debt['from_member_id']] -= $debt['remaining_amount'];
            $netBalances[$debt['to_member_id']] += $debt['remaining_amount'];
        }

        $overview = [];
        foreach ($netBalances as $mid => $amount) {
            if (abs($amount) < 1)
                continue;
            $m = $memberMap[$mid];

            $overview[] = [
                'member_id' => $mid,
                'name' => $m->user ? $m->user->name : $m->guest_name,
                'amount' => $amount,
                'is_current_user' => $m->user_id == $request->user()->id
            ];
        }

        // 2. Settlement Plan (List Bawah)
        // Tampilkan SEMUA (termasuk Paid)
        $settlementPlan = collect($allDebts)->map(function ($d) use ($memberMap) {
            $from = $memberMap[$d['from_member_id']];
            $to = $memberMap[$d['to_member_id']];

            return [
                'from_member_id' => $d['from_member_id'],
                'from_name' => $from->user ? $from->user->name : $from->guest_name,
                'to_member_id' => $d['to_member_id'],
                'to_name' => $to->user ? $to->user->name : $to->guest_name,
                'amount' => $d['remaining_amount'], // Sisa yang harus dibayar
                'total_orig' => $d['total_amount'],     // Hutang asli
                'status' => $d['status']            // 'paid', 'unpaid'
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'overview' => array_values($overview),
                'settlements' => $settlementPlan
            ]
        ]);
    }

    public function publicSummary(string $token)
    {
        $trip = Trip::where('public_summary_token', $token)->firstOrFail();

        // Ambil data format baru (List of Rows)
        $allDebts = (new TripBalanceService())->calculateTripBalances($trip->id);

        // Kita hitung manual balance per member dari list tersebut
        $memberBalances = [];

        // Inisialisasi 0 untuk semua member
        foreach ($trip->members as $m) {
            $memberBalances[$m->user_id ?? $m->id] = 0; // Gunakan user_id atau member id sebagai key sementara
        }

        foreach ($allDebts as $debt) {
            // Mapping ID dari service (member_id) ke logic kita
            $fromMid = $debt['from_member_id'];
            $toMid = $debt['to_member_id'];
            $remain = $debt['remaining_amount'];

            // Si A hutang (Minus)
            // Kita perlu mapping member_id ke user_id (karena FE public mungkin butuh user_id/nama)
            // Tapi agar aman, kita hitung based on member_id dulu
        }

        // --- VERSI SIMPEL YANG LEBIH AMAN ---
        // Mapping ulang member agar mudah diakses
        $membersMap = $trip->members->keyBy('id');

        $finalMembersData = $trip->members->map(function ($m) use ($allDebts) {
            $balance = 0;

            // Loop hasil service
            foreach ($allDebts as $debt) {
                // Jika saya yang berhutang (remaining), balance saya berkurang
                if ($debt['from_member_id'] == $m->id) {
                    $balance -= $debt['remaining_amount'];
                }
                // Jika orang hutang ke saya (remaining), balance saya bertambah
                if ($debt['to_member_id'] == $m->id) {
                    $balance += $debt['remaining_amount'];
                }
            }

            return [
                'user_id' => $m->user_id, // Tetap kirim user_id jika ada
                'name' => $m->user?->name ?? $m->guest_name,
                'balance' => $balance,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'trip' => [
                    'name' => $trip->name,
                    'currency_code' => $trip->currency_code,
                ],
                'members' => $finalMembersData,
            ],
        ]);
    }

}
