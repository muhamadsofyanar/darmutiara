# Status implementasi dan pengujian

Paket awal Dar Mutiara, 16 November 2026.

## Yang tersedia

Aplikasi Laravel + Blade nyata, dengan database/migration, formulir dan pemeriksaan akses di server. Alur peserta, pembayaran transfer manual, pengelolaan kelas, catatan/progres, diskusi, penilaian dan PDF sertifikat sudah terhubung ke database. Dockerfile, Nginx, PHP-FPM, Supervisor, worker, scheduler dan health check disertakan untuk Coolify.

## Batasan yang harus diketahui sebelum peluncuran

| Area | Batasan versi ini |
| --- | --- |
| Pembayaran | Transfer manual yang diverifikasi admin. Belum ada payment gateway/webhook, refund otomatis atau rekonsiliasi bank otomatis. |
| Video | Embed HTTPS dari domain yang diizinkan. Belum ada token playback sementara/DRM atau integrasi API streaming. Menyembunyikan URL di balik login tidak mencegah peserta membagikan URL embed. Untuk konten berbayar sensitif, integrasi penyedia bertoken harus dikerjakan sebelum peluncuran. |
| Kuis | Satu soal, empat pilihan per modul, lulus ketika benar, percobaan ulang tanpa batas bisnis; request tetap dibatasi laju. Belum bank soal, pengacakan atau timer. |
| Tugas | Teks/PDF/JPG/PNG, maksimal 10 MB. Nilai lulus 70 untuk versi awal. Belum pemindaian antivirus terintegrasi. |
| Sertifikat | PDF dengan snapshot nama/judul dan UUID; diunduh pemilik. Belum tanda tangan elektronik, QR atau portal verifikasi publik. |
| Materi | Teks dan rujukan ditampilkan aman sebagai teks, bukan editor HTML. Cover katalog berupa desain CSS; unggah cover belum ada. |
| Produk | Harga/durasi/cakupan diubah dengan membuat penawaran pengganti lalu menonaktifkan produk lama. Kelas dalam paket mengikuti snapshot checkout. |
| Mentor | Satu mentor utama per kelas pada versi ini. Akun/peran dibuat atau diubah melalui Artisan; tidak ada UI manajemen akun massal. |
| Syarat kelulusan | Seluruh modul terbit selesai, kuis benar dan tugas wajib minimal 70. Sertifikat lama tetap menggunakan snapshot saat diterbitkan. |
| Performa | Katalog dipaginasi, video/diskusi/catatan lazy-load; cache hasil query katalog belum diterapkan. Belum uji beban pengguna serentak. |
| Operasional | Database backup diatur di Coolify. Berkas privat perlu backup terpisah. Belum dashboard observabilitas/rekonsiliasi menyeluruh. |

Cakupan terinci pada rencana sebelumnya tetap menjadi arah pengembangan; batasan di atas menunjukkan bagian yang masih perlu diperluas sebelum kesetaraan penuh dengan rencana tersebut. Jangan menganggap paket ini sudah menjalankan integrasi yang belum dipilih penyedianya.

## Pengujian dalam lingkungan pembuatan

- Suite dasar pada SQLite: 21 tes, 94 assertions lulus; tiga tes konkurensi PostgreSQL dilewati (total 24 tes), meliputi draft, register/role, checkout, kupon, idempotensi, perpanjangan, akses habis, catatan privat/konflik, mentor, kuis, tugas, pemulihan kata sandi, bundle, health check dan PDF sertifikat.
- Route dan kompilasi Blade diperiksa dengan Artisan. Dependency dikunci pada composer.lock dan divalidasi dengan Composer.
- Docker tidak tersedia, sehingga image belum dibangun/dijalankan di lingkungan ini.
- Runtime PostgreSQL dicoba terpisah, tetapi pembukaan socket dibatasi lingkungan; tes PostgreSQL dan konkurensi belum dapat dieksekusi di sini.
- Browser visual dan uji perangkat HP sungguhan belum dilakukan.

## Pemeriksaan yang disertakan untuk GitHub

Workflow `.github/workflows/ci.yml` menjalankan suite pada PostgreSQL 16, termasuk tiga tes konkurensi dengan dua proses terpisah: kupon terakhir, dua pembayaran perpanjangan pertama, dan konfirmasi pembayaran sama. Workflow juga membangun Docker dan mencoba startup image dengan database serta GET `/up` dan `/`.

Workflow baru akan berjalan setelah kode diunggah ke GitHub. Tidak ada klaim workflow sudah hijau. Tinjau hasil Actions, selesaikan checklist README dan jalankan transaksi uji sebelum menerima peserta/pembayaran sungguhan.
