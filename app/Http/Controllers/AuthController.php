<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class AuthController extends Controller
{
    public function form(string $mode = 'login'): mixed
    {
        return view('auth', compact('mode'));
    }

    public function register(Request $r): mixed
    {
        $r->merge(['email' => Str::lower((string) $r->input('email'))]);
        $v = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:200|unique:users', 'password' => ['required', 'confirmed', Rules\Password::min(10)]]);
        $u = User::create(['name' => $v['name'], 'email' => Str::lower($v['email']), 'password' => Hash::make($v['password'])]);
        Auth::login($u);
        $r->session()->regenerate();

        return redirect()->intended('/kelas-saya');
    }

    public function login(Request $r): mixed
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $v['email'] = Str::lower($v['email']);
        if (! Auth::attempt($v, $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'Email atau kata sandi tidak sesuai.'])->onlyInput('email');
        }$r->session()->regenerate();

        return redirect()->intended('/kelas-saya');
    }

    public function logout(Request $r): mixed
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/');
    }

    public function forgot(Request $r): mixed
    {
        $v = $r->validate(['email' => 'required|email']);
        Password::sendResetLink(['email' => Str::lower($v['email'])]);

        return back()->with('status', 'Jika akun terdaftar, tautan pemulihan akan dikirim.');
    }

    public function resetForm(Request $r, string $token): mixed
    {
        $mode = 'reset';

        return view('auth', ['mode' => $mode, 'token' => $token, 'email' => $r->query('email')]);
    }

    public function reset(Request $r): mixed
    {
        $v = $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', Rules\Password::min(10)]]);
        $status = Password::reset($v, function (User $u, string $password) {
            $u->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        });

        return $status === Password::PasswordReset ? redirect('/masuk')->with('status', 'Kata sandi diperbarui. Silakan masuk.') : back()->withErrors(['email' => __($status)]);
    }

    public function profile(Request $r): mixed
    {
        if ($r->isMethod('put')) {
            $v = $r->validate(['name' => 'required|string|max:100']);
            $r->user()->update($v);

            return back()->with('status', 'Profil disimpan.');
        }

        return view('profile');
    }
}
