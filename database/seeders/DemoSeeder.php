<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('courses')->where('slug', 'demo-adab-menuntut-ilmu')->exists()) {
            return;
        }
        DB::transaction(function () {
            $id = DB::table('courses')->insertGetId(['title' => '[DEMO] Adab Menuntut Ilmu', 'slug' => 'demo-adab-menuntut-ilmu', 'description' => 'Konten contoh untuk menguji aplikasi. Ganti dengan materi yang telah disetujui pengelola sebelum peluncuran.', 'category' => 'Contoh kelas', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('modules')->insert(['course_id' => $id, 'title' => '[DEMO] Memulai perjalanan belajar', 'position' => 1, 'body' => 'Ini adalah materi demonstrasi untuk menguji progres, kuis, catatan, tugas dan sertifikat. Bukan materi kajian final.', 'assignment_required' => true, 'assignment_prompt' => 'Tuliskan satu tujuan belajar Anda.', 'quiz_question' => 'Apa fungsi catatan pribadi?', 'quiz_options' => json_encode(['Menyimpan refleksi belajar', 'Mengirim pembayaran', 'Mengubah harga kelas', 'Membuat akun admin']), 'quiz_correct' => 0, 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([['Kelas contoh', 'single', 50000, 30], ['Paket contoh', 'bundle', 75000, 60], ['Langganan contoh', 'subscription', 100000, 30]] as [$title,$type,$price,$days]) {
                $pid = DB::table('products')->insertGetId(['title' => '[DEMO] '.$title, 'type' => $type, 'price' => $price, 'duration_days' => $days, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('product_courses')->insert(['product_id' => $pid, 'course_id' => $id]);
            }
        });
    }
}
