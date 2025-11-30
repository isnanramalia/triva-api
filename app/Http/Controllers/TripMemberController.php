<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripMember;
use App\Models\User;
use Illuminate\Http\Request;

class TripMemberController extends Controller
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


    public function store(Request $request, Trip $trip)
    {
        try {

            $user = $request->user();

            $isAdmin = $trip->members()
                ->where('user_id', $user->id)
                ->where('role', 'admin')
                ->exists();

            if (!$isAdmin) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only admin can add members'
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

            // Add user member
            if ($data['type'] === 'user') {

                $userToAdd = User::where('email', $data['email'])->first();

                if (!$userToAdd) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'User not found'
                    ], 404);
                }

                $exists = TripMember::where('trip_id', $trip->id)
                    ->where('user_id', $userToAdd->id)
                    ->first();

                if ($exists) {
                    return response()->json([
                        'status' => 'success',
                        'data' => $exists
                    ]);
                }

                $member = TripMember::create([
                    'trip_id' => $trip->id,
                    'user_id' => $userToAdd->id,
                    'role' => $role,
                    'balance' => 0,
                ]);

                return response()->json(['status' => 'success', 'data' => $member], 201);
            }

            // Add guest member
            $member = TripMember::create([
                'trip_id' => $trip->id,
                'guest_name' => $data['guest_name'],
                'guest_contact' => $data['guest_contact'],
                'role' => $role,
                'balance' => 0,
            ]);

            return response()->json(['status' => 'success', 'data' => $member], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

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
