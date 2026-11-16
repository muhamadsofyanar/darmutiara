<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commerce;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrency requires PostgreSQL; CI supplies it.');
        }
    }

    private function data(): array
    {
        $cid = DB::table('courses')->insertGetId(['title' => 'Concurrent', 'slug' => 'concurrent', 'description' => 'test', 'published' => true]);
        $pid = DB::table('products')->insertGetId(['title' => 'Concurrent', 'type' => 'single', 'price' => 100000, 'duration_days' => 30, 'active' => true]);
        DB::table('product_courses')->insert(['product_id' => $pid, 'course_id' => $cid]);

        return [$cid, $pid];
    }

    private function runTogether(array $jobs): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'dar-barrier-');
        unlink($barrier);
        $processes = [];
        try {
            foreach ($jobs as [$mode,$user,$product,$extra]) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/commerce-worker.php'), $mode, $barrier, (string) $user, (string) $product, $extra], base_path(), ['MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync']);
                $p->setTimeout(30);
                $p->start();
                $processes[] = $p;
            }file_put_contents($barrier, 'go');
            $results = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
                $results[] = trim($p->getOutput());
            }

return $results;
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    public function test_last_coupon_slot_can_only_be_reserved_by_one_checkout(): void
    {
        [$cid,$pid] = $this->data();
        DB::table('coupons')->insert(['code' => 'LAST', 'percent' => 10, 'quota' => 1, 'expires_at' => now()->addDay()]);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $results = $this->runTogether([['coupon', $a->id, $pid, ''], ['coupon', $b->id, $pid, '']]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_concurrent_first_payments_produce_contiguous_terms(): void
    {
        [$cid,$pid] = $this->data();
        $u = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $commerce = app(Commerce::class);
        $a = $commerce->createOrder($u->id, $pid, null, (string) Str::uuid());
        $b = $commerce->createOrder($u->id, $pid, null, (string) Str::uuid());
        $this->assertSame(['accepted', 'accepted'], $this->runTogether([['confirm', $admin->id, $a->id, 'TX-A'], ['confirm', $admin->id, $b->id, 'TX-B']]));
        $terms = DB::table('access_terms')->orderBy('starts_at')->get();
        $this->assertCount(2, $terms);
        $this->assertSame($terms[0]->ends_at, $terms[1]->starts_at);
    }

    public function test_same_payment_confirmed_concurrently_only_creates_one_term(): void
    {
        [$cid,$pid] = $this->data();
        $u = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $o = app(Commerce::class)->createOrder($u->id, $pid, null, (string) Str::uuid());
        $this->assertSame(['accepted', 'accepted'], $this->runTogether([['confirm', $admin->id, $o->id, 'TX-ONE'], ['confirm', $admin->id, $o->id, 'TX-ONE']]));
        $this->assertDatabaseCount('access_terms',1);
    }
}
