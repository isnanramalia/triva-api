<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripMember;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $trips = Trip::whereHas('members', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                ->withCount('members')
                ->orderByDesc('created_at')
                ->get();

            return response()->json(['status' => 'success', 'data' => $trips]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }


    public function store(Request $request)
    {
        try {

            $user = $request->user();

            $data = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'currency_code' => 'nullable|string|size:3',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);

            $trip = Trip::create([
                'owner_id' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'IDR',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'status' => 'planning',
            ]);

            TripMember::create([
                'trip_id' => $trip->id,
                'user_id' => $user->id,
                'role' => 'admin',
                'balance' => 0,
            ]);

            return response()->json(['status' => 'success', 'data' => $trip], 201);

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

            $isMember = $trip->members()->where('user_id', $user->id)->exists();

            if (!$isMember) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden'
                ], 403);
            }

            $trip->load([
                'members.user',
                'transactions' => fn($q) => $q->latest()->limit(10),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $trip
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
