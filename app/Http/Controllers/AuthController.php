<?php

namespace App\Http\Controllers;

use App\Services\ApiView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254'], 'password' => ['required', 'string', 'max:200']]);
        if (! Auth::guard('web')->attempt(['email' => Str::lower(trim($data['email'])), 'password' => $data['password'], 'active' => true])) {
            return response()->json(['error' => 'Correo o contraseña incorrectos.'], 401);
        }
        $request->session()->regenerate();

        return response()->json(['user' => ApiView::user($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
