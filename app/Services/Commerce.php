<?php

namespace App\Services;

use App\Mail\OrderConfirmed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Commerce
{
    public function createOrder(int $userId, int $productId, ?string $code, string $key): object
    {
        return DB::transaction(function () use ($userId, $productId, $code, $key) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $existing = DB::table('orders')->where('checkout_key', $key)->first();
            if ($existing) {
                abort_unless($existing->user_id === $userId, 403);

                return $existing;
            }
            $product = DB::table('products')->where('id', $productId)->where('active', true)->first();
            abort_unless($product, 404);
            $ids = DB::table('product_courses')->join('courses', 'courses.id', '=', 'product_courses.course_id')->where('product_id', $productId)->where('courses.published', true)->pluck('courses.id')->all();
            if (! $ids) {
                throw ValidationException::withMessages(['product' => 'Produk belum memiliki kelas terbit.']);
            }
            $coupon = null;
            $discount = 0;
            if ($code) {
                $coupon = DB::table('coupons')->where('code', Str::upper(trim($code)))->lockForUpdate()->first();
                if (! $coupon || CarbonImmutable::parse($coupon->expires_at)->lte(now()) || ($coupon->product_id && $coupon->product_id != $productId)) {
                    throw ValidationException::withMessages(['coupon' => 'Kupon tidak berlaku.']);
                }
                $used = DB::table('orders')->where('coupon_id', $coupon->id)->where(function ($q) {
                    $q->where('status', 'paid')->orWhere(fn ($q) => $q->where('status', 'pending')->where('expires_at', '>', now()));
                })->count();
                if ($used >= $coupon->quota) {
                    throw ValidationException::withMessages(['coupon' => 'Kuota kupon sudah habis atau sedang dipesan.']);
                }
                $discount = intdiv($product->price * $coupon->percent, 100);
            }
            $id = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'user_id' => $userId, 'product_id' => $productId, 'coupon_id' => $coupon?->id, 'title' => $product->title, 'type' => $product->type, 'duration_days' => $product->duration_days, 'course_ids' => json_encode($ids), 'subtotal' => $product->price, 'discount' => $discount, 'total' => $product->price - $discount, 'status' => 'pending', 'checkout_key' => $key, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('orders')->find($id);
        }, 3);
    }

    public function confirm(int $orderId, int $adminId, string $reference): void
    {
        DB::transaction(function () use ($orderId, $adminId, $reference) {
            $initial = DB::table('orders')->find($orderId);
            abort_unless($initial, 404);
            // User lock serializes concurrent renewals, including the first term.
            DB::table('users')->where('id', $initial->user_id)->lockForUpdate()->first();
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order->status === 'paid') {
                return;
            }
            if ($order->status !== 'pending' || CarbonImmutable::parse($order->expires_at)->lte(now())) {
                throw ValidationException::withMessages(['order' => 'Pesanan tidak aktif. Buat pesanan baru; rekonsiliasi transfer lama secara manual.']);
            }
            if (DB::table('orders')->where('payment_reference', $reference)->exists()) {
                throw ValidationException::withMessages(['reference' => 'Referensi transfer sudah dipakai.']);
            }
            $at = CarbonImmutable::now();
            $end = DB::table('access_terms')->where('user_id', $order->user_id)->where('product_id', $order->product_id)->whereNull('revoked_at')->max('ends_at');
            $start = $end && CarbonImmutable::parse($end)->gt($at) ? CarbonImmutable::parse($end) : $at;
            DB::table('access_terms')->insert(['user_id' => $order->user_id, 'product_id' => $order->product_id, 'order_id' => $order->id, 'course_ids' => $order->course_ids, 'starts_at' => $start, 'ends_at' => $start->addDays($order->duration_days), 'created_at' => $at, 'updated_at' => $at]);
            DB::table('orders')->where('id', $orderId)->update(['status' => 'paid', 'paid_at' => $at, 'payment_reference' => $reference, 'updated_at' => $at]);
            DB::afterCommit(function () use ($order) {
                $email = DB::table('users')->where('id', $order->user_id)->value('email');
                Mail::to($email)->queue(new OrderConfirmed($order->reference, $order->title));
            });
            DB::table('audit_logs')->insert(['user_id' => $adminId, 'action' => 'payment.confirmed.manual', 'subject' => 'order:'.$orderId, 'created_at' => $at, 'updated_at' => $at]);
        }, 3);
    }

    public function canAccess(int $userId, int $courseId): bool
    {
        return DB::table('access_terms')->where('user_id', $userId)->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->get()->contains(fn ($term) => in_array($courseId, json_decode($term->course_ids, true)));
    }
}
