<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');

        $users = User::query()
            ->when($search, function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
            })
            ->where('id', '!=', $request->user()->id) // Jangan tampilkan diri sendiri
            ->limit(20) // Limit biar gak berat
            ->get(['id', 'name', 'email']); // Ambil field yg perlu aja

        return response()->json([
            'status' => 'success',
            'data' => $users
        ]);
    }
}
