<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth')->plainTextToken;
        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;
        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function user(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json(['user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]]);
    }
}
