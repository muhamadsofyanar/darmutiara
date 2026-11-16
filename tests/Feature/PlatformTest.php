<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commerce;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private function setupCourse(): array
    {
        $cid = DB::table('courses')->insertGetId(['title' => 'Kelas Uji', 'slug' => 'kelas-uji', 'description' => 'Deskripsi', 'category' => 'Adab', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        $mid = DB::table('modules')->insertGetId(['course_id' => $cid, 'title' => 'Modul Uji', 'position' => 1, 'body' => 'Materi aman', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pid = DB::table('products')->insertGetId(['title' => 'Produk Uji', 'type' => 'single', 'price' => 100000, 'duration_days' => 30, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_courses')->insert(['product_id' => $pid, 'course_id' => $cid]);

        return [$cid, $mid, $pid];
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'admin'])->save();

        return $u;
    }

    private function buy(User $u, int $pid): object
    {
        $o = app(Commerce::class)->createOrder($u->id, $pid, null, (string) Str::uuid());
        app(Commerce::class)->confirm($o->id, $this->admin()->id, 'REF-'.Str::uuid());

        return $o;
    }

    public function test_public_catalog_excludes_drafts_and_does_not_render_player(): void
    {
        [$cid] = $this->setupCourse();
        $this->get('/')->assertOk()->assertSee('Kelas Uji')->assertDontSee('<iframe', false);
        DB::table('courses')->where('id', $cid)->update(['published' => false]);
        $this->get('/')->assertDontSee('Kelas Uji');
        $this->get('/kelas/kelas-uji')->assertNotFound();
    }

    public function test_registration_does_not_accept_admin_role(): void
    {
        $this->post('/daftar', ['name' => 'Peserta', 'email' => 'peserta@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123', 'role' => 'admin'])->assertRedirect('/kelas-saya');
        $this->assertDatabaseHas('users', ['email' => 'peserta@example.test', 'role' => 'student']);
    }

    public function test_checkout_uses_server_price_and_is_idempotent(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $u = User::factory()->create();
        $key = (string) Str::uuid();
        $this->actingAs($u)->post('/checkout', ['product_id' => $pid, 'checkout_key' => $key, 'total' => 1])->assertRedirect();
        $this->post('/checkout', ['product_id' => $pid, 'checkout_key' => $key])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['user_id' => $u->id, 'total' => 100000]);
        $this->get('/belajar/'.$mid)->assertForbidden();
    }

    public function test_coupon_reserves_last_slot_and_expired_reservation_releases_it(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        DB::table('coupons')->insert(['code' => 'HEMAT', 'percent' => 20, 'quota' => 1, 'expires_at' => now()->addDay()]);
        $u = User::factory()->create();
        $o = app(Commerce::class)->createOrder($u->id, $pid, 'hemat', (string) Str::uuid());
        $this->assertSame(80000, $o->total);
        $other = User::factory()->create();
        $this->actingAs($other)->post('/checkout', ['product_id' => $pid, 'coupon' => 'HEMAT', 'checkout_key' => (string) Str::uuid()])->assertSessionHasErrors('coupon');
        DB::table('orders')->where('id', $o->id)->update(['expires_at' => now()->subMinute()]);
        $new = app(Commerce::class)->createOrder($other->id, $pid, 'HEMAT', (string) Str::uuid());
        $this->assertSame(80000, $new->total);
    }

    public function test_confirm_is_idempotent_and_renewal_starts_after_active_period(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $u = User::factory()->create();
        $admin = $this->admin();
        $s = app(Commerce::class);
        $o = $s->createOrder($u->id, $pid, null, (string) Str::uuid());
        $s->confirm($o->id, $admin->id, 'BANK-001');
        $s->confirm($o->id, $admin->id, 'BANK-001');
        $this->assertDatabaseCount('access_terms', 1);
        $end = DB::table('access_terms')->first()->ends_at;
        $o2 = $s->createOrder($u->id, $pid, null, (string) Str::uuid());
        $s->confirm($o2->id, $admin->id, 'BANK-002');
        $this->assertDatabaseHas('access_terms', ['order_id' => $o2->id, 'starts_at' => $end]);
        $this->assertTrue($s->canAccess($u->id, $cid));
    }

    public function test_expired_subscription_does_not_remove_single_purchase(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $u = User::factory()->create();
        $o = $this->buy($u, $pid);
        DB::table('access_terms')->where('order_id', $o->id)->update(['ends_at' => now()->subDay()]);
        $this->assertFalse(app(Commerce::class)->canAccess($u->id, $cid));
        $this->buy($u, $pid);
        $this->assertTrue(app(Commerce::class)->canAccess($u->id, $cid));
        $this->actingAs($u)->get('/kelas-saya')->assertOk();
    }

    public function test_notes_are_private_and_reject_stale_versions(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->buy($a, $pid);
        $this->buy($b, $pid);
        $this->actingAs($a)->putJson('/modul/'.$mid.'/catatan', ['body' => 'RAHASIA PRIBADI', 'version' => 0])->assertOk()->assertJsonPath('version', 1);
        $this->putJson('/modul/'.$mid.'/catatan', ['body' => 'ditimpa', 'version' => 0])->assertStatus(409);
        $this->actingAs($b)->getJson('/modul/'.$mid.'/catatan')->assertOk()->assertDontSee('RAHASIA PRIBADI');
    }

    public function test_expiry_blocks_note_updates_but_preserves_progress(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $u = User::factory()->create();
        $o = $this->buy($u, $pid);
        $this->actingAs($u)->post('/modul/'.$mid.'/selesai')->assertRedirect();
        DB::table('access_terms')->where('order_id', $o->id)->update(['ends_at' => now()->subSecond()]);
        $this->putJson('/modul/'.$mid.'/catatan', ['body' => 'x', 'version' => 0])->assertForbidden();
        $this->assertDatabaseHas('module_progress', ['user_id' => $u->id, 'module_id' => $mid]);
        $this->buy($u, $pid);
        $this->get('/belajar/'.$mid)->assertOk()->assertSee('Modul selesai');
    }

    public function test_role_and_mentor_assignment_are_enforced(): void
    {
        [$cid] = $this->setupCourse();
        $student = User::factory()->create();
        $this->actingAs($student)->get('/kelola')->assertForbidden();
        $mentor = User::factory()->create();
        $mentor->forceFill(['role' => 'mentor'])->save();
        $this->actingAs($mentor)->get('/kelola/kelas/'.$cid)->assertForbidden();
        DB::table('courses')->where('id', $cid)->update(['mentor_id' => $mentor->id]);
        $this->get('/kelola/kelas/'.$cid)->assertOk();
        $this->post('/kelola/produk', ['title' => 'x'])->assertForbidden();
    }

    public function test_quiz_key_not_sent_to_learner_and_grades_on_server(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        DB::table('modules')->where('id', $mid)->update(['quiz_question' => 'Pertanyaan', 'quiz_options' => json_encode(['a', 'b', 'c', 'd']), 'quiz_correct' => 2]);
        $u = User::factory()->create();
        $this->buy($u, $pid);
        $this->actingAs($u)->get('/belajar/'.$mid)->assertOk()->assertDontSee('quiz_correct');
        $this->post('/modul/'.$mid.'/kuis', ['answer' => 0])->assertRedirect();
        $this->assertDatabaseHas('quiz_attempts', ['user_id' => $u->id, 'passed' => false]);
        $this->post('/modul/'.$mid.'/kuis', ['answer' => 2])->assertRedirect();
        $this->assertDatabaseHas('quiz_attempts', ['user_id' => $u->id, 'passed' => true]);
    }

    public function test_certificate_requires_completion_and_download_survives_expiry(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $u = User::factory()->create();
        $o = $this->buy($u, $pid);
        $this->actingAs($u)->post('/kelas/'.$cid.'/sertifikat')->assertForbidden();
        $this->post('/modul/'.$mid.'/selesai');
        $this->post('/kelas/'.$cid.'/sertifikat')->assertRedirect();
        $this->post('/kelas/'.$cid.'/sertifikat')->assertRedirect();
        $this->assertDatabaseCount('certificates', 1);
        $cert = DB::table('certificates')->first();
        DB::table('access_terms')->where('order_id', $o->id)->update(['ends_at' => now()->subDay()]);
        $res = $this->get('/sertifikat/'.$cert->code)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->actingAs(User::factory()->create())->get('/sertifikat/'.$cert->code)->assertNotFound();
    }

    public function test_assignment_attachment_is_private_and_requires_assigned_mentor_to_grade(): void
    {
        Storage::fake('local');
        [$cid,$mid,$pid] = $this->setupCourse();
        DB::table('modules')->where('id', $mid)->update(['assignment_required' => true]);
        $u = User::factory()->create();
        $this->buy($u, $pid);
        Storage::disk('local')->put('assignments/private.pdf', 'PRIVATE');
        $id = DB::table('assignment_submissions')->insertGetId(['user_id' => $u->id, 'module_id' => $mid, 'attachment' => 'assignments/private.pdf', 'body' => 'Jawaban', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create())->get('/pengumpulan/'.$id.'/berkas')->assertForbidden();
        $mentor = User::factory()->create();
        $mentor->forceFill(['role' => 'mentor'])->save();
        $this->actingAs($mentor)->post('/kelola/pengumpulan/'.$id, ['grade' => 80, 'feedback' => 'Baik'])->assertForbidden();
        DB::table('courses')->where('id', $cid)->update(['mentor_id' => $mentor->id]);
        $this->post('/kelola/pengumpulan/'.$id, ['grade' => 80, 'feedback' => 'Baik'])->assertRedirect();
        $this->assertDatabaseHas('assignment_submissions', ['id' => $id, 'grade' => 80]);
    }

    public function test_all_main_pages_render_and_admin_can_create_content(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $this->get('/masuk')->assertOk();
        $this->get('/daftar')->assertOk();
        $this->get('/lupa-sandi')->assertOk();
        $this->get('/kelas/kelas-uji')->assertOk();
        $u = $this->admin();
        $this->actingAs($u)->get('/kelola')->assertOk();
        $this->get('/kelola/kelas/'.$cid)->assertOk();
        $this->get('/checkout/'.$pid)->assertOk();
        $this->get('/profil')->assertOk();
        $this->post('/kelola/kelas/'.$cid.'/modul', ['title' => 'Baru', 'position' => 2, 'body' => 'Materi', 'published' => 1])->assertRedirect();
        $this->assertDatabaseHas('modules', ['title' => 'Baru']);
    }

    public function test_another_users_order_is_not_visible(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $a = User::factory()->create();
        $o = app(Commerce::class)->createOrder($a->id, $pid, null, (string) Str::uuid());
        $this->actingAs(User::factory()->create())->get('/pesanan/'.$o->reference)->assertNotFound();
    }

    public function test_payment_confirmation_cannot_be_triggered_by_student(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $u = User::factory()->create();
        $o = app(Commerce::class)->createOrder($u->id, $pid, null, (string) Str::uuid());
        $this->actingAs($u)->post('/kelola/pesanan/'.$o->id.'/konfirmasi', ['payment_reference' => 'FAKE'])->assertForbidden();
        $this->assertDatabaseCount('access_terms', 0);
    }

    public function test_bundle_grants_two_classes_and_my_courses_deduplicates_sources(): void
    {
        [$cid, $mid, $pid] = $this->setupCourse();
        $second = DB::table('courses')->insertGetId(['title' => 'Kelas Kedua', 'slug' => 'kelas-kedua', 'description' => 'Uji', 'published' => true]);
        $bundle = DB::table('products')->insertGetId(['title' => 'Bundle', 'type' => 'bundle', 'price' => 150000, 'duration_days' => 20, 'active' => true]);
        foreach ([$cid, $second] as $course) {
            DB::table('product_courses')->insert(['product_id' => $bundle, 'course_id' => $course]);
        }
        $u = User::factory()->create();
        $this->buy($u, $pid);
        $this->buy($u, $bundle);
        $this->assertTrue(app(Commerce::class)->canAccess($u->id, $cid));
        $this->assertTrue(app(Commerce::class)->canAccess($u->id, $second));
        $this->actingAs($u)->get('/kelas-saya')->assertOk()->assertSee('Kelas Kedua');
        DB::table('access_terms')->where('product_id', $bundle)->update(['ends_at' => now()->subDay()]);
        $this->assertTrue(app(Commerce::class)->canAccess($u->id, $cid));
        $this->assertFalse(app(Commerce::class)->canAccess($u->id, $second));
    }

    public function test_failed_quiz_and_ungraded_assignment_block_certificate(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        DB::table('modules')->where('id', $mid)->update(['quiz_question' => 'Uji', 'quiz_options' => json_encode(['a', 'b', 'c', 'd']), 'quiz_correct' => 0, 'assignment_required' => true]);
        $u = User::factory()->create();
        $this->buy($u, $pid);
        $this->actingAs($u)->post('/modul/'.$mid.'/selesai');
        $this->post('/kelas/'.$cid.'/sertifikat')->assertForbidden();
        $this->post('/modul/'.$mid.'/kuis', ['answer' => 0]);
        $this->post('/kelas/'.$cid.'/sertifikat')->assertForbidden();
        $this->post('/modul/'.$mid.'/tugas', ['body' => 'Jawaban saya'])->assertRedirect();
        $this->post('/kelas/'.$cid.'/sertifikat')->assertForbidden();
        $id = DB::table('assignment_submissions')->value('id');
        $this->actingAs($this->admin())->post('/kelola/pengumpulan/'.$id, ['grade' => 80, 'feedback' => 'Lulus'])->assertRedirect();
        $this->actingAs($u)->post('/kelas/'.$cid.'/sertifikat')->assertRedirect();
    }

    public function test_password_reset_sends_notification_and_updates_password(): void
    {
        Notification::fake();
        $u = User::factory()->create();
        $this->post('/lupa-sandi', ['email' => $u->email])->assertRedirect();
        Notification::assertSentTo($u, ResetPassword::class);
        $token = Password::createToken($u);
        $this->post('/reset-sandi', ['token' => $token, 'email' => $u->email, 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123'])->assertRedirect('/masuk');
        $this->assertTrue(Hash::check('Changed-password-123', $u->fresh()->password));
    }

    public function test_inactive_product_and_expired_order_cannot_be_paid(): void
    {
        [$cid,$mid,$pid] = $this->setupCourse();
        $u = User::factory()->create();
        $o = app(Commerce::class)->createOrder($u->id, $pid, null, (string) Str::uuid());
        DB::table('products')->where('id', $pid)->update(['active' => false]);
        $this->actingAs($u)->get('/checkout/'.$pid)->assertNotFound();
        DB::table('orders')->where('id', $o->id)->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($this->admin())->post('/kelola/pesanan/'.$o->id.'/konfirmasi', ['payment_reference' => 'LATE'])->assertSessionHasErrors('order');
        $this->assertDatabaseCount('access_terms', 0);
    }

    public function test_health_check_and_receipt_render(): void
    {
        $this->get('/up')->assertOk();
        [$cid,$mid,$pid] = $this->setupCourse();
        $u = User::factory()->create();
        $o = app(Commerce::class)->createOrder($u->id, $pid, null, (string) Str::uuid());
        $this->actingAs($u)->get('/pesanan/'.$o->reference)->assertOk()->assertSee('Selesaikan pembayaran');
    }
}
