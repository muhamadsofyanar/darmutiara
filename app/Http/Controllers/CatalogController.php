<?php

namespace App\Http\Controllers;

use App\Services\Commerce;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogController extends Controller
{
    public function index(Request $r): mixed
    {
        $courses = DB::table('courses')->where('published', true)->when($r->filled('q'), fn ($q) => $q->where('title', 'like', '%'.mb_substr($r->string('q'), 0, 100).'%'))->orderBy('title')->paginate(12)->withQueryString();
        $products = DB::table('products')->where('active', true)->get();

        return view('catalog', compact('courses', 'products'));
    }

    public function show(string $slug): mixed
    {
        $course = DB::table('courses')->where('slug', $slug)->where('published', true)->first();
        abort_unless($course, 404);
        $modules = DB::table('modules')->where('course_id', $course->id)->where('published', true)->orderBy('position')->get(['id', 'title', 'position']);
        $mentor = DB::table('users')->find($course->mentor_id);
        $products = DB::table('products')->join('product_courses', 'products.id', '=', 'product_courses.product_id')->where('course_id', $course->id)->where('active', true)->select('products.*')->get();

        return view('course', compact('course', 'modules', 'products', 'mentor'));
    }

    public function checkout(Request $r, int $product): mixed
    {
        $product = DB::table('products')->where('id', $product)->where('active', true)->first();
        abort_unless($product, 404);
        $courses = DB::table('courses')->join('product_courses', 'courses.id', '=', 'product_courses.course_id')->where('product_id', $product->id)->where('courses.published', true)->get(['courses.title']);
        $key = (string) Str::uuid();

        return view('checkout', compact('product', 'courses', 'key'));
    }

    public function order(Request $r, Commerce $commerce): mixed
    {
        $v = $r->validate(['product_id' => 'required|integer', 'coupon' => 'nullable|string|max:50', 'checkout_key' => 'required|uuid']);
        $order = $commerce->createOrder($r->user()->id, $v['product_id'], $v['coupon'] ?? null, $v['checkout_key']);

        return redirect('/pesanan/'.$order->reference);
    }

    public function receipt(Request $r, string $reference): mixed
    {
        $order = DB::table('orders')->where('reference', $reference)->where('user_id', $r->user()->id)->first();
        abort_unless($order, 404);

        return view('receipt', compact('order'));
    }

    public function paymentNote(Request $r, string $reference): mixed
    {
        $v = $r->validate(['payment_note' => 'required|string|max:1000']);
        $order = DB::table('orders')->where('reference', $reference)->where('user_id', $r->user()->id)->first();
        abort_unless($order, 404);
        abort_unless($order->status === 'pending' && now()->lt($order->expires_at), 422);
        DB::table('orders')->where('id', $order->id)->update(['payment_note' => $v['payment_note'], 'updated_at' => now()]);

        return back()->with('status', 'Informasi transfer terkirim. Akses dibuka setelah diperiksa admin.');
    }
}
