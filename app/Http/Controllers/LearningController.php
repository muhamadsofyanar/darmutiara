<?php

namespace App\Http\Controllers;

use App\Services\Certificates;
use App\Services\Commerce;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LearningController extends Controller
{
    private function module(Request $r, int $module): object
    {
        $m = DB::table('modules')->join('courses', 'courses.id', '=', 'modules.course_id')->where('modules.id', $module)->where('modules.published', true)->where('courses.published', true)->select('modules.*', 'courses.title as course_title')->first();
        abort_unless($m, 404);
        abort_unless(app(Commerce::class)->canAccess($r->user()->id, $m->course_id), 403, 'Masa akses kelas sudah berakhir atau belum aktif.');

        return $m;
    }

    public function dashboard(Request $r): mixed
    {
        $uid = $r->user()->id;
        $terms = DB::table('access_terms')->where('user_id', $uid)->get();
        $ids = $terms->flatMap(fn ($t) => json_decode($t->course_ids, true))->unique()->all();
        $courses = DB::table('courses')->whereIn('id', $ids)->get()->map(function ($c) use ($uid) {
            $ids = DB::table('modules')->where('course_id', $c->id)->where('published', true)->pluck('id');
            $c->count = $ids->count();
            $c->done = DB::table('module_progress')->where('user_id', $uid)->whereIn('module_id', $ids)->count();
            $c->active = app(Commerce::class)->canAccess($uid, $c->id);
            $c->next = DB::table('modules')->where('course_id', $c->id)->where('published', true)->whereNotIn('id', DB::table('module_progress')->where('user_id', $uid)->select('module_id'))->orderBy('position')->value('id') ?? $ids->first();

            return $c;
        });
        $orders = DB::table('orders')->where('user_id', $uid)->latest('id')->paginate(10);
        $certificates = DB::table('certificates')->where('user_id', $uid)->get();

        return view('dashboard', compact('courses', 'orders', 'certificates'));
    }

    public function show(Request $r, int $module): mixed
    {
        $m = $this->module($r, $module);
        $modules = DB::table('modules')->where('course_id', $m->course_id)->where('published', true)->orderBy('position')->get(['id', 'title']);
        $completed = DB::table('module_progress')->where('user_id', $r->user()->id)->pluck('module_id')->all();
        $passed = DB::table('quiz_attempts')->where('user_id', $r->user()->id)->where('module_id', $module)->where('passed', true)->exists();
        $submissions = DB::table('assignment_submissions')->where('user_id', $r->user()->id)->where('module_id', $module)->latest('id')->get();

        return view('learning', compact('m', 'modules', 'completed', 'passed', 'submissions'));
    }

    public function progress(Request $r, int $module): mixed
    {
        $this->module($r, $module);
        DB::table('module_progress')->updateOrInsert(['user_id' => $r->user()->id, 'module_id' => $module], ['completed_at' => now()]);

        return back()->with('status', 'Modul ditandai selesai.');
    }

    public function note(Request $r, int $module): mixed
    {
        $this->module($r, $module);
        $uid = $r->user()->id;
        if ($r->isMethod('get')) {
            return response()->json(DB::table('private_notes')->where('user_id', $uid)->where('module_id', $module)->first() ?? ['body' => '', 'version' => 0]);
        }$v = $r->validate(['body' => 'nullable|string|max:20000', 'version' => 'required|integer|min:0']);
        $note = DB::transaction(function () use ($uid, $module, $v) {
            DB::table('users')->where('id', $uid)->lockForUpdate()->first();
            $note = DB::table('private_notes')->where('user_id', $uid)->where('module_id', $module)->first();
            abort_if(($note?->version ?? 0) != $v['version'], 409, 'Catatan telah berubah di tab lain. Muat ulang sebelum menyimpan.');
            DB::table('private_notes')->updateOrInsert(['user_id' => $uid, 'module_id' => $module], ['body' => $v['body'] ?? '', 'version' => ($note?->version ?? 0) + 1, 'created_at' => $note?->created_at ?? now(), 'updated_at' => now()]);

            return DB::table('private_notes')->where('user_id', $uid)->where('module_id', $module)->first();
        });

        return response()->json($note);
    }

    public function discussion(Request $r, int $module): mixed
    {
        $this->module($r, $module);
        if ($r->isMethod('post')) {
            $v = $r->validate(['body' => 'required|string|max:3000']);
            DB::table('discussion_posts')->insert(['user_id' => $r->user()->id, 'module_id' => $module, 'body' => $v['body'], 'created_at' => now(), 'updated_at' => now()]);

            return back()->with('status', 'Pesan dikirim.');
        }

        return response()->json(DB::table('discussion_posts')->join('users', 'users.id', '=', 'discussion_posts.user_id')->where('module_id', $module)->where('hidden', false)->latest('discussion_posts.id')->select('discussion_posts.body', 'discussion_posts.created_at', 'users.name')->paginate(20));
    }

    public function quiz(Request $r, int $module): mixed
    {
        $m = $this->module($r, $module);
        abort_unless($m->quiz_question, 404);
        $v = $r->validate(['answer' => 'required|integer|between:0,3']);
        $passed = (int) $v['answer'] === (int) $m->quiz_correct;
        DB::table('quiz_attempts')->insert(['user_id' => $r->user()->id, 'module_id' => $module, 'answer' => $v['answer'], 'passed' => $passed, 'question_snapshot' => $m->quiz_question, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', $passed ? 'Jawaban benar. Kuis lulus.' : 'Jawaban belum tepat. Baca kembali materi dan coba lagi.');
    }

    public function assignment(Request $r, int $module): mixed
    {
        $m = $this->module($r, $module);
        abort_unless($m->assignment_required, 404);
        $v = $r->validate(['body' => 'nullable|string|max:20000', 'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        if (empty($v['body']) && ! $r->hasFile('attachment')) {
            throw ValidationException::withMessages(['body' => 'Isi jawaban atau unggah berkas.']);
        }$path = $r->file('attachment')?->store('assignments', 'local');
        DB::table('assignment_submissions')->insert(['user_id' => $r->user()->id, 'module_id' => $module, 'body' => $v['body'] ?? null, 'attachment' => $path, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Tugas dikumpulkan. Menunggu penilaian mentor.');
    }

    public function attachment(Request $r, int $module): mixed
    {
        $m = $this->module($r, $module);
        abort_unless($m->attachment && Storage::disk('local')->exists($m->attachment), 404);

        return Storage::disk('local')->download($m->attachment, 'materi-'.$m->id.'.pdf');
    }

    public function certificate(Request $r, int $course, Certificates $certs): mixed
    {
        $cert = $certs->issue($r->user()->id, $course);

        return redirect('/sertifikat/'.$cert->code);
    }

    public function download(Request $r, string $code): mixed
    {
        $cert = DB::table('certificates')->where('code', $code)->where('user_id', $r->user()->id)->first();
        abort_unless($cert, 404);
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml(view('certificate', compact('cert'))->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="sertifikat-'.$code.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
