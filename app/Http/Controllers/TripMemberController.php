<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TripMemberController extends Controller
{
    // List Member
    public function index(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            // Cek apakah user yang request adalah anggota trip?
            if (!$trip->members()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Forbidden'
                ], 403);
            }

            $members = $trip->members()->with('user')->get();

            return response()->json(['status' => 'success', 'data' => $members]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // Add Member (Invite)
    public function store(Request $request, Trip $trip)
    {
        try {
            $user = $request->user();

            $isMember = $trip->members()
                ->where('user_id', $user->id)
                ->exists();

            if (!$isMember) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Only trip members can invite others.'
                ], 403);
            }

            $data = $request->validate([
                'type' => 'required|in:user,guest',
                'email' => 'required_if:type,user|email',
                'guest_name' => 'required_if:type,guest',
                'guest_contact' => 'nullable|string',
                'role' => 'nullable|in:admin,member',
            ]);

            $role = $data['role'] ?? 'member';

            // 1. ADD REGISTERED USER
            if ($data['type'] === 'user') {

                $userToAdd = User::where('email', $data['email'])->first();

                if (!$userToAdd) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'User not found'
                    ], 404);
                }

                // Cek apakah sudah join?
                $exists = TripMember::where('trip_id', $trip->id)
                    ->where('user_id', $userToAdd->id)
                    ->first();

                if ($exists) {
                    return response()->json([
                        'status' => 'error', // Ubah jadi error biar frontend tau kalau duplikat
                        'message' => 'User is already a member',
                        'data' => $exists
                    ], 409);
                }

                $member = TripMember::create([
                    'trip_id' => $trip->id,
                    'user_id' => $userToAdd->id,
                    'role' => $role,
                    'balance' => 0,
                ]);

                return response()->json(['status' => 'success', 'data' => $member], 201);
            }

            // 2. ADD GUEST (User tanpa akun)
            $guestExists = TripMember::where('trip_id', $trip->id)
                ->where('guest_name', $data['guest_name'])
                ->exists();

            if ($guestExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Guest with this name already exists in the trip'
                ], 409);
            }

            $member = TripMember::create([
                'trip_id' => $trip->id,
                'guest_name' => $data['guest_name'],
                'guest_contact' => $data['guest_contact'] ?? null,
                'role' => $role,
                'balance' => 0,
            ]);

            return response()->json(['status' => 'success', 'data' => $member], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
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