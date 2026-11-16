<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\ManageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('home');
Route::get('/kelas/{slug}', [CatalogController::class, 'show'])->name('course');
Route::middleware('guest')->group(function () {
    Route::get('/masuk', fn () => app(AuthController::class)->form('login'))->name('login');
    Route::get('/daftar', fn () => app(AuthController::class)->form('register'))->name('register');
    Route::post('/masuk', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/daftar', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::get('/lupa-sandi', fn () => app(AuthController::class)->form('forgot'))->name('password.request');
    Route::post('/lupa-sandi', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-sandi/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-sandi', [AuthController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
});
Route::middleware('auth')->group(function () {
    Route::post('/keluar', [AuthController::class, 'logout'])->name('logout');
    Route::match(['get', 'put'], '/profil', [AuthController::class, 'profile'])->name('profile');
    Route::get('/checkout/{product}', [CatalogController::class, 'checkout'])->name('checkout');
    Route::post('/checkout', [CatalogController::class, 'order'])->middleware('throttle:10,1')->name('orders.create');
    Route::get('/pesanan/{reference}', [CatalogController::class, 'receipt'])->name('orders.show');
    Route::post('/pesanan/{reference}', [CatalogController::class, 'paymentNote'])->middleware('throttle:10,1')->name('orders.note');
    Route::get('/kelas-saya', [LearningController::class, 'dashboard'])->name('dashboard');
    Route::get('/belajar/{module}', [LearningController::class, 'show'])->name('learning');
    Route::post('/modul/{module}/selesai', [LearningController::class, 'progress'])->name('progress');
    Route::match(['get', 'put'], '/modul/{module}/catatan', [LearningController::class, 'note'])->middleware('throttle:60,1')->name('notes');
    Route::match(['get', 'post'], '/modul/{module}/diskusi', [LearningController::class, 'discussion'])->middleware('throttle:30,1')->name('discussion');
    Route::post('/modul/{module}/kuis', [LearningController::class, 'quiz'])->middleware('throttle:10,1')->name('quiz');
    Route::post('/modul/{module}/tugas', [LearningController::class, 'assignment'])->middleware('throttle:10,1')->name('assignment');
    Route::get('/modul/{module}/berkas', [LearningController::class, 'attachment'])->name('attachment');
    Route::post('/kelas/{course}/sertifikat', [LearningController::class, 'certificate'])->name('certificate.issue');
    Route::get('/sertifikat/{code}', [LearningController::class, 'download'])->name('certificate.download');
    Route::get('/kelola', [ManageController::class, 'index'])->name('manage');
    Route::post('/kelola/kelas', [ManageController::class, 'createCourse'])->name('manage.create');
    Route::get('/kelola/kelas/{course}', [ManageController::class, 'editCourse'])->name('manage.course');
    Route::put('/kelola/kelas/{course}', [ManageController::class, 'updateCourse'])->name('manage.update');
    Route::post('/kelola/kelas/{course}/modul', [ManageController::class, 'saveModule'])->name('manage.module');
    Route::post('/kelola/produk', [ManageController::class, 'product'])->name('manage.product');
    Route::post('/kelola/produk/{product}/status', [ManageController::class, 'toggleProduct'])->name('manage.product.status');
    Route::post('/kelola/kupon', [ManageController::class, 'coupon'])->name('manage.coupon');
    Route::post('/kelola/pesanan/{order}/konfirmasi', [ManageController::class, 'confirm'])->middleware('throttle:20,1')->name('manage.confirm');
    Route::get('/pengumpulan/{submission}/berkas', [ManageController::class, 'submissionFile'])->name('submission.file');
    Route::post('/kelola/pengumpulan/{submission}', [ManageController::class, 'grade'])->name('manage.grade');
    Route::post('/kelola/diskusi/{post}', [ManageController::class, 'moderate'])->name('manage.moderate');
});
