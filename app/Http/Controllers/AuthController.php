<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {

            $credentials = $request->validate([
                'email'    => 'required|email',
                'password' => 'required',
            ]);

            if (!Auth::attempt($credentials)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            /** @var \App\Models\User $user */
            $user = $request->user();

            $token = $user->createToken('mobile')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'token' => $token,
                    'user'  => $user
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function register(Request $request)
    {
        try {

            $data = $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|string|min:6',
            ]);

            $user = User::create([
                'name'  => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            // Auto login → generate token
            $token = $user->createToken('mobile')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully.',
                'user'    => $user,
                'token'   => $token,
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
                'message' => 'Registration failed.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }


    public function logout(Request $request)
    {
        try {

            $request->user()->currentAccessToken()?->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Logged out successfully.'
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
