<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        return view('user.profile', ['user' => Auth::user()]);
    }

    public function update(Request $request)
    {
        dd("hit");

        $user = Auth::user();

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'date_of_birth' => 'required|date',
            'profile_picture' => 'image|dimensions:min_width=100,min_height=100,max_width=2000,max_height=2000',
            'password' => 'required|string|min:6|confirmed',
        ]);


        return view('user.profile', ['user' => $user]);
    }
}
