<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Commerce;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManageController extends Controller
{
    private function guard(Request $r): void
    {
        abort_unless(in_array($r->user()->role, ['admin', 'mentor']), 403);
    }

    private function course(Request $r, int $id): object
    {
        $this->guard($r);
        $course = DB::table('courses')->find($id);
        abort_unless($course, 404);
        abort_unless($r->user()->role === 'admin' || $course->mentor_id === $r->user()->id, 403);

        return $course;
    }

    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'admin', 403);
    }

    public function index(Request $r): mixed
    {
        $this->guard($r);
        $admin = $r->user()->role === 'admin';
        $courses = DB::table('courses')->when(! $admin, fn ($q) => $q->where('mentor_id', $r->user()->id))->get();
        $ids = $courses->pluck('id');
        $submissions = DB::table('assignment_submissions')->join('modules', 'modules.id', '=', 'assignment_submissions.module_id')->join('users', 'users.id', '=', 'assignment_submissions.user_id')->whereIn('modules.course_id', $ids)->select('assignment_submissions.*', 'users.name', 'modules.title as module_title')->latest('assignment_submissions.id')->paginate(20, ['*'], 'tasks');
        $orders = $admin ? DB::table('orders')->join('users', 'users.id', '=', 'orders.user_id')->select('orders.*', 'users.name')->latest('orders.id')->paginate(20, ['*'], 'orders') : collect();
        $products = $admin ? DB::table('products')->get() : collect();
        $coupons = $admin ? DB::table('coupons')->get() : collect();
        $mentors = $admin ? User::whereIn('role', ['mentor', 'admin'])->get() : collect();

        return view('manage', compact('admin', 'courses', 'submissions', 'orders', 'products', 'coupons', 'mentors'));
    }

    public function createCourse(Request $r): mixed
    {
        $this->admin($r);
        $v = $r->validate(['title' => 'required|string|max:180', 'description' => 'required|string|max:10000', 'category' => 'required|string|max:100', 'mentor_id' => ['nullable', Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('role', ['mentor', 'admin']))]]);
        $v['slug'] = Str::slug($v['title']).'-'.Str::lower(Str::random(6));
        $v['published'] = $r->boolean('published');
        $v['created_at'] = now();
        $v['updated_at'] = now();
        $id = DB::table('courses')->insertGetId($v);

        return redirect('/kelola/kelas/'.$id);
    }

    public function editCourse(Request $r, int $course): mixed
    {
        $c = $this->course($r, $course);
        $modules = DB::table('modules')->where('course_id', $course)->orderBy('position')->get();
        $progress = DB::table('module_progress')->join('modules', 'modules.id', '=', 'module_progress.module_id')->join('users', 'users.id', '=', 'module_progress.user_id')->where('modules.course_id', $course)->groupBy('users.id', 'users.name')->selectRaw('users.id, users.name, count(*) as done')->get();
        $posts = DB::table('discussion_posts')->join('modules', 'modules.id', '=', 'discussion_posts.module_id')->join('users', 'users.id', '=', 'discussion_posts.user_id')->where('modules.course_id', $course)->select('discussion_posts.*', 'users.name')->latest('discussion_posts.id')->paginate(20);
        $mentors = User::whereIn('role', ['mentor', 'admin'])->get();

        return view('manage-course', compact('c', 'modules', 'progress', 'posts', 'mentors'));
    }

    public function updateCourse(Request $r, int $course): mixed
    {
        $this->admin($r);
        $this->course($r, $course);
        $v = $r->validate(['title' => 'required|string|max:180', 'description' => 'required|string|max:10000', 'category' => 'required|string|max:100', 'mentor_id' => ['nullable', Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('role', ['mentor', 'admin']))]]);
        $v['published'] = $r->boolean('published');
        $v['updated_at'] = now();
        DB::table('courses')->where('id', $course)->update($v);

        return back()->with('status', 'Kelas diperbarui.');
    }

    public function saveModule(Request $r, int $course): mixed
    {
        $this->course($r, $course);
        $v = $r->validate(['module_id' => ['nullable', Rule::exists('modules', 'id')->where('course_id', $course)], 'title' => 'required|string|max:180', 'position' => 'required|integer|min:1|max:10000', 'body' => 'nullable|string|max:60000', 'video_url' => ['nullable', 'url:https', 'max:2000', function ($a, $value, $fail) {
            $host = parse_url($value, PHP_URL_HOST);
            $allowed = explode(',', config('dar.video_hosts'));
            if (! in_array($host, $allowed, true)) {
                $fail('Domain video belum diizinkan. Tambahkan melalui VIDEO_ALLOWED_HOSTS.');
            }
        }], 'attachment' => 'nullable|file|mimes:pdf|max:10240', 'assignment_prompt' => 'nullable|string|max:10000', 'quiz_question' => 'nullable|string|max:255', 'option_0' => 'nullable|string|max:300', 'option_1' => 'nullable|string|max:300', 'option_2' => 'nullable|string|max:300', 'option_3' => 'nullable|string|max:300', 'quiz_correct' => 'nullable|integer|between:0,3']);
        if ($r->filled('quiz_question')) {
            $r->validate(['option_0' => 'required', 'option_1' => 'required', 'option_2' => 'required', 'option_3' => 'required', 'quiz_correct' => 'required']);
        }
        $id = $v['module_id'] ?? null;
        $existing = $id ? DB::table('modules')->find($id) : null;
        // Freeze assessment content once participants have submitted work.
        if ($id && (DB::table('quiz_attempts')->where('module_id', $id)->exists() || DB::table('assignment_submissions')->where('module_id', $id)->exists())) {
            return back()->withErrors(['module' => 'Modul sudah memiliki penilaian peserta. Buat modul baru untuk perubahan materi/penilaian.']);
        }
        $data = ['course_id' => $course, 'title' => $v['title'], 'position' => $v['position'], 'body' => $v['body'] ?? null, 'video_url' => $v['video_url'] ?? null, 'assignment_required' => $r->boolean('assignment_required'), 'assignment_prompt' => $v['assignment_prompt'] ?? null, 'quiz_question' => $v['quiz_question'] ?? null, 'quiz_options' => $r->filled('quiz_question') ? json_encode([$v['option_0'], $v['option_1'], $v['option_2'], $v['option_3']]) : null, 'quiz_correct' => $r->filled('quiz_question') ? $v['quiz_correct'] : null, 'published' => $r->boolean('published'), 'updated_at' => now()];
        $data['attachment'] = $r->hasFile('attachment') ? $r->file('attachment')->store('materials', 'local') : ($existing?->attachment);
        if ($id) {
            DB::table('modules')->where('id', $id)->update($data);
        } else {
            DB::table('modules')->insert($data + ['created_at' => now()]);
        }

        return back()->with('status', 'Modul disimpan.');
    }

    public function product(Request $r): mixed
    {
        $this->admin($r);
        $v = $r->validate(['title' => 'required|string|max:180', 'type' => 'required|in:single,bundle,subscription', 'price' => 'required|integer|min:1|max:1000000000', 'duration_days' => 'required|integer|between:1,3650', 'course_ids' => 'required|array|min:1', 'course_ids.*' => 'required|integer|distinct|exists:courses,id']);
        if ($v['type'] === 'single' && count($v['course_ids']) !== 1) {
            return back()->withErrors(['course_ids' => 'Produk kelas satuan harus berisi satu kelas.']);
        }DB::transaction(function () use ($v) {
            $ids = $v['course_ids'];
            unset($v['course_ids']);
            $id = DB::table('products')->insertGetId($v + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($ids as $cid) {
                DB::table('product_courses')->insert(['product_id' => $id, 'course_id' => $cid]);
            }
        });

        return back()->with('status', 'Penawaran dibuat.');
    }

    public function toggleProduct(Request $r, int $product): mixed
    {
        $this->admin($r);
        $p = DB::table('products')->find($product);
        abort_unless($p, 404);
        DB::table('products')->where('id', $product)->update(['active' => ! $p->active, 'updated_at' => now()]);

        return back();
    }

    public function coupon(Request $r): mixed
    {
        $this->admin($r);
        $r->merge(['code' => Str::upper(trim($r->input('code', '')))]);
        $v = $r->validate(['code' => 'required|alpha_dash|max:50|unique:coupons', 'percent' => 'required|integer|between:1,99', 'quota' => 'required|integer|between:1,100000', 'expires_at' => 'required|date|after:now', 'product_id' => 'nullable|integer|exists:products,id']);
        DB::table('coupons')->insert($v + ['created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Kupon dibuat.');
    }

    public function confirm(Request $r, int $order, Commerce $commerce): mixed
    {
        $this->admin($r);
        $v = $r->validate(['payment_reference' => 'required|string|max:180']);
        $commerce->confirm($order, $r->user()->id, $v['payment_reference']);

        return back()->with('status', 'Pembayaran dikonfirmasi dan akses diberikan.');
    }

    public function submissionFile(Request $r, int $submission): mixed
    {
        $s = DB::table('assignment_submissions')->find($submission);
        abort_unless($s, 404);
        if ($s->user_id !== $r->user()->id) {
            $m = DB::table('modules')->find($s->module_id);
            $this->course($r, $m->course_id);
        }abort_unless($s->attachment && Storage::disk('local')->exists($s->attachment), 404);

        return Storage::disk('local')->download($s->attachment);
    }

    public function grade(Request $r, int $submission): mixed
    {
        $s = DB::table('assignment_submissions')->find($submission);
        abort_unless($s, 404);
        $m = DB::table('modules')->find($s->module_id);
        $this->course($r, $m->course_id);
        $v = $r->validate(['grade' => 'required|integer|between:0,100', 'feedback' => 'required|string|max:5000']);
        DB::table('assignment_submissions')->where('id', $submission)->update($v + ['grader_id' => $r->user()->id, 'updated_at' => now()]);

        return back()->with('status', 'Penilaian disimpan.');
    }

    public function moderate(Request $r, int $post): mixed
    {
        $p = DB::table('discussion_posts')->find($post);
        abort_unless($p, 404);
        $m = DB::table('modules')->find($p->module_id);
        $this->course($r, $m->course_id);
        DB::table('discussion_posts')->where('id', $post)->update(['hidden' => ! $p->hidden, 'updated_at' => now()]);

        return back();
    }
}
