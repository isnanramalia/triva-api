<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripMember;
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
                'data'   => $trips, // paginator bawaan Laravel
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
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
                'name'          => 'required|string|max:255',
                'description'   => 'nullable|string',
                'currency_code' => 'nullable|string|size:3',
                'start_date'    => 'nullable|date',
                'end_date'      => 'nullable|date',
            ]);

            $trip = Trip::create([
                'owner_id'            => $user->id,
                'name'                => $data['name'],
                'description'         => $data['description'] ?? null,
                'currency_code'       => $data['currency_code'] ?? 'IDR',
                'start_date'          => $data['start_date'] ?? null,
                'end_date'            => $data['end_date'] ?? null,
                'status'              => 'planning',
                'public_summary_token'=> Str::random(32),
            ]);

            TripMember::create([
                'trip_id' => $trip->id,
                'user_id' => $user->id,
                'role'    => 'admin',
                'balance' => 0,
            ]);

            return response()->json([
                'status' => 'success',
                'data'   => $trip,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Detail 1 trip (hanya untuk member).
     */
    public function show(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            $isMember = $trip->members()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Forbidden',
                ], 403);
            }

            $trip->load([
                'members.user',
                'transactions' => fn ($q) => $q->latest()->limit(10),
            ]);

            return response()->json([
                'status' => 'success',
                'data'   => $trip,
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
     * Update trip (hanya owner).
     */
    public function update(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            if ($trip->owner_id !== $user->id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Only owner can update this trip',
                ], 403);
            }

            $data = $request->validate([
                'name'          => 'sometimes|required|string|max:255',
                'description'   => 'nullable|string',
                'currency_code' => 'nullable|string|size:3',
                'start_date'    => 'nullable|date',
                'end_date'      => 'nullable|date',
                'status'        => 'nullable|in:planning,ongoing,finished,cancelled',
            ]);

            $trip->fill($data);
            $trip->save();

            return response()->json([
                'status' => 'success',
                'data'   => $trip,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
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
                    'status'  => 'error',
                    'message' => 'Only owner can delete this trip',
                ], 403);
            }

            $trip->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Trip deleted',
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
     * Public summary (tanpa auth) untuk guest link.
     * GET /api/public/trips/{token}
     */
    public function publicSummary(string $token)
    {
        try {
            $trip = Trip::where('public_summary_token', $token)
                ->with(['members', 'transactions'])
                ->first();

            if (!$trip) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Trip not found',
                ], 404);
            }

            $totalSpent = $trip->transactions->sum('total_amount');

            $members = $trip->members->map(function ($m) {
                return [
                    'id'      => $m->id,
                    'name'    => $m->user?->name ?? $m->guest_name,
                    'balance' => (float) $m->balance,
                    'role'    => $m->role,
                ];
            });

            $transactions = $trip->transactions->map(function ($t) {
                return [
                    'id'           => $t->id,
                    'title'        => $t->title,
                    'date'         => $t->date,
                    'total_amount' => (float) $t->total_amount,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'trip' => [
                        'name'          => $trip->name,
                        'description'   => $trip->description,
                        'currency_code' => $trip->currency_code,
                        'status'        => $trip->status,
                    ],
                    'total_spent'  => (float) $totalSpent,
                    'members'      => $members,
                    'transactions' => $transactions,
                ],
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
