# Dar Mutiara

Platform e-course dengan Laravel 13, Blade, JavaScript native, dan PostgreSQL. Image produksi menggunakan PHP 8.5. Paket ini disiapkan untuk unggah manual ke GitHub dan deployment Dockerfile melalui Coolify pada VPS 4 vCPU / RAM 8 GB. Tidak membutuhkan Node/Vite untuk build atau menjalankan tampilan.

## Status paket

Versi awal fungsional dengan pembayaran transfer manual. Baca `docs/STATUS.md` sebelum membuka pendaftaran peserta. Integrasi payment gateway dan video bertoken belum tersedia. Tidak ada akun admin, kata sandi bawaan, rekening, atau materi produksi yang ditanam di kode.

Fitur yang tersedia:

- Katalog dan detail kelas terbit; paket satuan, bundle dan langganan dengan perpanjangan manual.
- Daftar, masuk, reset kata sandi lewat email, profil, serta peran peserta/mentor/admin.
- Pesanan dengan harga dari server, snapshot kelas/harga/durasi, kupon persentase dan reservasi kuota 24 jam.
- Konfirmasi transfer oleh admin, pencatatan audit, email konfirmasi lewat antrean, dan hak akses dengan masa berlaku.
- Kelas Saya tanpa duplikasi kelas; progres tetap tersimpan setelah akses berakhir.
- Modul teks, rujukan, video embed yang dimuat saat dibuka, PDF privat, catatan privat dan diskusi.
- Satu soal pilihan ganda per modul, percobaan ulang, tugas teks/berkas dan penilaian mentor.
- Sertifikat PDF unik per peserta/kelas dan unduh ulang setelah masa akses habis.
- Pengelolaan kelas, modul, pengajar, penawaran, kupon, pembayaran, progres, tugas dan moderasi diskusi.

## 1. Unggah manual ke GitHub

1. Ekstrak ZIP ini di komputer. Buka folder `dar-mutiara`.
2. Buat repository baru di GitHub; repository private cocok untuk kode bisnis Anda.
3. Pilih **Add file → Upload files**. Unggah **isi** folder `dar-mutiara`, bukan ZIP dan bukan folder induknya.
4. Pastikan `Dockerfile`, `artisan`, `composer.json`, `composer.lock`, `app`, `bootstrap`, `config`, `database`, `docker`, `public`, `resources`, `routes`, dan `storage` ada di root repository.
5. Sertakan `.env.example`, `.dockerignore`, `.gitignore`, dan `.github/workflows/ci.yml`. Nama yang diawali titik mungkin disembunyikan oleh pengelola berkas komputer Anda. Bila unggahan browser melewati file tersebut, buat ulang melalui **Add file → Create new file** dengan nama persisnya.
6. Commit ke branch `main`. Jangan unggah `.env` berisi rahasia, `vendor`, database, atau data peserta.
7. Buka tab **Actions** dan pastikan workflow **Laravel checks** lulus. Workflow menjalankan tes memakai PostgreSQL, build Docker, dan smoke test container. Hasil lokal paket belum menggantikan hasil workflow ini.

Jika GitHub membatasi banyaknya file dalam satu unggahan, unggah beberapa folder secara bertahap. Total kode kecil; dependency PHP akan dipasang ketika Docker dibangun.

## 2. Buat PostgreSQL di Coolify

1. Buka project dan environment tujuan. Tambahkan resource **PostgreSQL**, misalnya versi 16.
2. Isi nama database dan pengguna, misalnya `dar_mutiara`. Buat kata sandi database yang kuat, kemudian start database.
3. Catat **hostname internal**, port, nama database, username dan password dari resource tersebut. Gunakan resource aplikasi dan database pada server/destination jaringan yang sama. Pastikan aplikasi dapat menjangkau database melalui jaringan internal Coolify.
4. Biarkan akses database publik nonaktif. Jangan gunakan `localhost` sebagai DB_HOST pada container aplikasi yang terpisah dari container database.
5. Atur backup database pada Coolify dan salin backup ke lokasi di luar VPS.

## 3. Buat APP_KEY satu kali

APP_KEY wajib tersedia **sebelum deployment pertama**, karena container sengaja tidak start tanpa key. Key harus tetap sama pada deployment berikutnya.

Di terminal server VPS/Coolify yang memiliki OpenSSL, jalankan:

```sh
printf 'base64:'; openssl rand -base64 32
```

Salin seluruh hasil satu baris yang diawali `base64:`. Alternatif pada Windows PowerShell:

```powershell
$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
'base64:' + [Convert]::ToBase64String($bytes)
```

Simpan key di pengelola rahasia Anda dan di environment Coolify. Jangan commit key ke repository.

## 4. Buat aplikasi di Coolify

1. Tambahkan resource aplikasi dari repository GitHub tadi. Untuk private repository, hubungkan GitHub App atau deploy key pada Coolify.
2. Pilih branch `main` dan build pack/strategy **Dockerfile**.
3. Base Directory: `/`. Dockerfile Location: `/Dockerfile` (atau `Dockerfile` bila field meminta path relatif).
4. **Ports Exposes: `8080`**. Aplikasi mendengarkan semua interface di port ini. Tidak perlu mengisi Ports Mappings/host port: akses publik lewat proxy Coolify.
5. Atur domain, misalnya `https://belajar.domainanda.id`. Arahkan DNS domain ke IP VPS terlebih dahulu dan aktifkan HTTPS melalui Coolify.
6. Masukkan environment runtime pada bagian Environment Variables. Mulai dari `.env.example`, lalu ganti seluruh placeholder. Jangan membuat `.env` produksi di GitHub.
7. Tambahkan Persistent Storage/volume dengan **destination path `/var/www/html/storage`**. Volume ini menyimpan materi privat, tugas, cache dan sesi berkas pendukung. Atur sebelum peserta mengunggah berkas.
8. Gunakan satu replica aplikasi untuk awal. Startup menjalankan migrasi; untuk arsitektur multi-replica nanti, pisahkan migrasi ke deployment job dan jalankan scheduler hanya sekali.
9. Deploy. Container menjalankan migrasi, cache konfigurasi dan Blade, kemudian Nginx, PHP-FPM, satu queue worker dan scheduler.
10. Health check: GET `/up`, port `8080`, start period 60 detik atau lebih jika build pertama lambat. Periksa log bila status tidak sehat.

Pengaturan Dockerfile dan port mengikuti dokumentasi Coolify: https://coolify.io/docs/applications/builds/dockerfile. Prasyarat database/worker: https://coolify.io/docs/applications/framework-examples/php/laravel.

## 5. Environment yang perlu diganti

| Variabel | Nilai/contoh |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | URL HTTPS aplikasi, tanpa slash terakhir |
| `APP_KEY` | Key unik dari langkah 3; jangan berubah pada redeploy |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | Hostname internal PostgreSQL dari Coolify |
| `DB_PORT` | `5432`, kecuali resource Anda berbeda |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Sesuai resource PostgreSQL |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` |
| `SESSION_SECURE_COOKIE` | `true` untuk HTTPS produksi |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Kredensial SMTP transaksional Anda |
| `MAIL_SCHEME` | `smtp` untuk STARTTLS seperti port 587; `smtps` bila penyedia mensyaratkannya |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Pengirim yang telah diverifikasi penyedia email |
| `BANK_NAME`, `BANK_ACCOUNT`, `BANK_HOLDER` | Rekening pengelola untuk transfer manual |
| `SUPPORT_EMAIL` | Alamat pengelola untuk pertanyaan peserta |
| `VIDEO_ALLOWED_HOSTS` | Daftar hostname embed HTTPS yang dipercaya, dipisahkan koma tanpa spasi |
| `RUN_MIGRATIONS` | `true` untuk deployment satu replica ini |

Pastikan environment tersedia pada **runtime**, bukan hanya sebagai build argument. Untuk mencoba situs sebelum SMTP siap, `MAIL_MAILER=log` bisa digunakan pada lingkungan uji; tautan reset akan masuk log dan email tidak terkirim. Jangan gunakan mode ini untuk peserta sungguhan karena log memuat tautan pemulihan.

## 6. Buat admin dan mentor

Setelah aplikasi berjalan, buka **Terminal aplikasi** di Coolify. Jalankan:

```sh
php artisan dar:user admin@domainanda.id --role=admin --name="Admin Dar Mutiara"
```

Masukkan kata sandi minimal 12 karakter saat diminta; tidak ada password default. Masuk ke `/masuk`, lalu buka `/kelola`.

Untuk mentor:

```sh
php artisan dar:user mentor@domainanda.id --role=mentor --name="Nama Pengajar"
```

Untuk mengubah akun yang sudah terdaftar:

```sh
php artisan dar:role peserta@domainanda.id mentor
```

Penugasan mentor dilakukan melalui edit kelas. Mentor hanya dapat mengelola kelas yang ditugaskan kepadanya. Admin terakhir tidak dapat diturunkan perannya melalui command ini.

## 7. Masukkan kelas pertama

1. Di `/kelola`, buat kelas sebagai draf. Isi judul, kategori, deskripsi dan mentor.
2. Buka kelas, tambah modul. Isikan materi teks dan rujukan; bila ada PDF, unggah maksimal 10 MB.
3. Untuk video, masukkan **URL embed**, bukan URL halaman biasa. Contoh format YouTube: `https://www.youtube-nocookie.com/embed/ID_VIDEO`, Vimeo: `https://player.vimeo.com/video/ID_VIDEO`. Aktifkan pembatasan embed di penyedia jika tersedia.
4. Kuis awal mendukung satu soal dengan empat pilihan per modul. Tugas mendukung teks/PDF/JPG/PNG, maksimal 10 MB. Nilai lulus tugas ditetapkan 70 pada versi ini.
5. Terbitkan modul, lalu terbitkan kelas melalui edit kelas.
6. Di dashboard, buat penawaran. Pilih jenis single/bundle/subscription, harga dalam rupiah, jumlah hari akses dan kelas yang termasuk.
7. Semua jenis produk menggunakan pembayaran sekali per pesanan. Subscription berarti perpanjangan manual, tanpa auto debit.
8. Untuk revisi harga/durasi/cakupan produk, buat produk pengganti dan nonaktifkan produk lama. Transaksi lama menggunakan snapshot awalnya.

Opsional khusus lingkungan uji kosong:

```sh
php artisan db:seed --class=DemoSeeder --force
```

Perintah tersebut membuat kelas/produk bertanda `[DEMO]`, tanpa akun atau akses gratis. Jangan jalankan pada situs peserta sungguhan kecuali memang ingin menambahkan konten contoh. Default `db:seed` tidak membuat akun atau materi apapun.

## 8. Alur pembayaran manual

1. Peserta memilih produk dan membuat pesanan. Harga dan diskon ditetapkan server. Pesanan/reservasi kupon berlaku 24 jam.
2. Peserta transfer ke rekening yang tampil dan mengirim informasi pengirim/referensi transfer pada halaman pesanan.
3. Admin memeriksa mutasi bank di luar aplikasi, mencocokkan nominal/pengirim, lalu mengisi referensi bank di dashboard dan mengonfirmasi.
4. Hak akses dibuat dalam transaksi database. Konfirmasi yang diulang tidak menambah akses kedua. Email pemberitahuan masuk antrean setelah transaksi selesai.
5. Untuk perpanjangan produk sama yang masih aktif, periode baru mengikuti akhir periode sebelumnya. Jika sudah habis, periode baru dimulai saat konfirmasi. Sumber akses lain tetap berlaku.
6. Jika transfer dilakukan untuk pesanan yang sudah kedaluwarsa, rekonsiliasi dengan peserta terlebih dahulu. Aplikasi tidak mengonfirmasi pesanan kedaluwarsa; buat pesanan pengganti sesuai hasil rekonsiliasi dan hindari menghitung pembayaran dua kali.

Tidak ada webhook atau payment gateway otomatis pada versi ini. Jangan menafsirkan informasi transfer dari peserta sebagai bukti pembayaran yang telah diverifikasi.

## 9. Pemeriksaan sebelum digunakan peserta

- GitHub Actions hijau untuk tes PostgreSQL, build dan startup Docker.
- Situs HTTPS dapat dibuka dari HP; login dan logout berfungsi.
- Buat akun peserta uji dan satu pesanan. Saat pending, akses belajar harus ditolak.
- Cocokkan pembayaran uji, konfirmasi sebagai admin, lalu uji kelas satuan, bundle, dan perpanjangan.
- Simpan catatan dari dua tab dan pastikan konflik versi ditolak. Uji pengguna lain tidak dapat melihat catatan atau tugas peserta pertama.
- Selesaikan modul/kuis/tugas, beri nilai sebagai mentor, lalu unduh sertifikat PDF.
- Uji reset kata sandi dan email konfirmasi dengan SMTP asli. Periksa spam dan domain pengirim.
- Unggah berkas uji, redeploy, dan pastikan berkas tetap dapat diunduh dari volume persisten.
- Uji pemulihan backup database **dan** `storage/app/private`. Volume persisten bukan backup.
- Ukur beban nyata sebelum menjanjikan kapasitas pengguna serentak. Setelan FPM 12 worker adalah titik awal yang harus diukur.

## 10. Pembaruan dan diagnosis

Setelah mengunggah revisi kode ke GitHub, tekan Redeploy pada Coolify. Dependency dikunci melalui `composer.lock`. Pertahankan APP_KEY, database dan volume. Backup sebelum migrasi; rollback image tidak otomatis membalikkan schema/data.

| Gejala | Periksa |
| --- | --- |
| Container berhenti dengan pesan APP_KEY | Isi APP_KEY runtime sebelum deploy |
| SQLSTATE connection refused / hostname gagal | DB_HOST internal, database berjalan dan jaringan antarkontainer |
| 502 / tidak sehat | Log startup, port 8080, `/up`, migrasi selesai |
| 419 saat masuk | APP_URL HTTPS, secure cookie, APP_KEY stabil; bersihkan cookie lama |
| Permission denied pada berkas | Mount `/var/www/html/storage` dan izin www-data |
| Reset sandi tidak sampai | SMTP, sender terverifikasi, log aplikasi dan pengaturan penyedia |
| Email konfirmasi tertunda | `php artisan queue:failed`; setelah memperbaiki penyebab, `php artisan queue:retry all` |
| Embed video ditolak | URL embed HTTPS dan hostname persis dalam VIDEO_ALLOWED_HOSTS |

Docker tidak tersedia di lingkungan pembuatan paket, sehingga build container belum dieksekusi di sana. Workflow yang disertakan melakukan verifikasi ini di GitHub. Rincian pengujian dan batasan ada di `docs/STATUS.md`.

## Pengembangan lokal (opsional)

Gunakan PHP 8.5, Composer 2, ekstensi PostgreSQL/SQLite, mbstring, GD, DOM dan ZIP. Salin `.env.example` ke `.env`, isi koneksi database lokal dan ubah `APP_ENV=local`, `APP_URL=http://localhost:8000`, `SESSION_SECURE_COOKIE=false` sebelum menjalankan:

```sh
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Untuk tes SQLite tanpa database server, jalankan `vendor/bin/phpunit`. Tes konkurensi akan dilewati di SQLite. PostgreSQL dan CI tetap diperlukan untuk memeriksa penguncian transaksi.
