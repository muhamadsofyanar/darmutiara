<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('dar:user {email} {--role=admin} {--name=}', function () {
    $email = strtolower($this->argument('email'));
    $role = $this->option('role');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, ['admin', 'mentor', 'student'])) {
        $this->error('Email atau peran tidak valid.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Akun sudah ada. Gunakan dar:role untuk mengubah peran.');

        return 1;
    }
    $name = $this->option('name') ?: $this->ask('Nama lengkap');
    $password = $this->secret('Kata sandi (minimal 12 karakter)');
    if (! $name || strlen($password ?? '') < 12) {
        $this->error('Nama wajib dan kata sandi minimal 12 karakter.');

        return 1;
    }
    $u = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
    $u->forceFill(['role' => $role])->save();
    $this->info('Akun dibuat.');
})->purpose('Membuat akun pengelola secara aman, tanpa kata sandi bawaan.');
Artisan::command('dar:role {email} {role}', function () {
    $role = $this->argument('role');
    if (! in_array($role, ['admin', 'mentor', 'student'])) {
        $this->error('Peran tidak valid.');

        return 1;
    }
    $u = User::where('email', strtolower($this->argument('email')))->first();
    if (! $u) {
        $this->error('Akun tidak ditemukan.');

        return 1;
    }
    if ($u->role === 'admin' && $role !== 'admin' && User::where('role', 'admin')->count() <= 1) {
        $this->error('Jangan hapus admin terakhir.');

        return 1;
    }
    $u->forceFill(['role' => $role])->save();
    $this->info('Peran diperbarui.');
})->purpose('Mengubah peran akun yang sudah ada.');
Schedule::command('queue:prune-failed --hours=168')->daily();
