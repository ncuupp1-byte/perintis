# Panduan Deploy ke Domainesia (Shared Hosting / cPanel)

## Yang Dibutuhkan
- Akun cPanel Domainesia aktif
- Domain `enuyrasa.my.id` (DNS dikelola di Domainesia)

---

## Langkah 0 — Arahkan Domain ke Hosting Domainesia

> Lakukan ini **sebelum** upload file. Domain harus sudah aktif di hosting.

### A. Cek IP Hosting Kamu

1. Login ke **cPanel** Domainesia
2. Scroll ke bawah ke bagian **"General Information"** atau **"Server Information"**
3. Catat nilai **"Shared IP Address"** — contoh: `103.x.x.x`

### B. Update DNS Record di Dashboard Domainesia

1. Login ke **Member Area / Panel Domainesia** → **Domain** → klik `enuyrasa.my.id` → **Kelola DNS**
   *(bisa juga lewat cPanel → Zone Editor)*
2. Cari record `A` yang ada — kemungkinan masih mengarah ke IP Vercel lama
3. **Edit / ubah** nilai record tersebut:

   | Type | Name | Value | TTL |
   |------|------|-------|-----|
   | A | `@` (atau `enuyrasa.my.id`) | `IP_HOSTING_DOMAINESIA` | 3600 |
   | A | `www` | `IP_HOSTING_DOMAINESIA` | 3600 |

4. Klik **Save**

> Jika sebelumnya ada record CNAME ke `cname.vercel-dns.com` — **hapus** record itu, lalu buat record A di atas sebagai penggantinya. CNAME dan A tidak bisa ada bersamaan untuk nama yang sama.

### C. Tambahkan Domain di cPanel Hosting

Kalau `enuyrasa.my.id` belum terdaftar sebagai domain di cPanel hosting kamu:

1. cPanel → **Addon Domains** (atau **Domains**)
2. Klik **Create/Add Domain**
3. Masukkan `enuyrasa.my.id`
4. Folder document root akan otomatis dibuat (biasanya `/public_html/enuyrasa.my.id/` atau `/public_html/`)

### D. Tunggu Propagasi DNS

- Propagasi DNS biasanya **5–30 menit** (kadang sampai 24 jam)
- Test dengan buka browser: `https://enuyrasa.my.id` — kalau muncul halaman cPanel default atau "It works!" berarti sudah aktif
- Atau cek propagasi di: **https://dnschecker.org/#A/enuyrasa.my.id**

---

---

## Langkah 1 — Buat Database MySQL di cPanel

1. Login cPanel → **MySQL® Databases**
2. Buat database baru, contoh: `user123_tokoenuy`
3. Buat user database baru, contoh: `user123_dbuser` + password kuat
4. Tambahkan user ke database dengan hak akses **ALL PRIVILEGES**
5. Catat ketiga nilai ini: DB_NAME, DB_USER, DB_PASS

---

## Langkah 2 — Isi Nilai di `.htaccess`

Buka file `.htaccess` di folder ini, edit bagian `SetEnv`:

```
SetEnv DB_HOST     localhost
SetEnv DB_NAME     user123_tokoenuy      ← sesuaikan
SetEnv DB_USER     user123_dbuser        ← sesuaikan
SetEnv DB_PASS     password-db-kamu      ← sesuaikan

SetEnv ADMIN_TOKEN       token-rahasia-toko    ← buat sendiri, minimal 12 karakter
SetEnv SUPER_ADMIN_TOKEN token-super-rahasia   ← boleh dikosongkan

SetEnv FONNTE_TOKEN  xxx    ← dari fonnte.com → akun → token
SetEnv ADMIN_WA      6281234567890

SetEnv DIGIFLAZZ_USERNAME  username-digiflazz
SetEnv DIGIFLAZZ_API_KEY   api-key-production-digiflazz
SetEnv DIGIFLAZZ_TESTING   false         ← ganti "true" untuk mode test

SetEnv MARKUP_PERCENT  5
SetEnv MARKUP_FLAT     0
SetEnv MARKUP_ROUND    100
```

---

## Langkah 3 — Upload File ke cPanel

1. Login cPanel → **File Manager**
2. Masuk ke folder `/public_html/` (atau folder domain `enuyrasa.my.id`)
3. Upload **semua file dan folder berikut**:

```
Upload ini:
  .htaccess          ← wajib (routing + env vars)
  config.php         ← wajib
  index.html
  admin.html
  digital.html
  oleh-oleh.html
  images/            ← folder beserta isinya
  api/               ← folder beserta semua .php di dalamnya

JANGAN upload:
  node_modules/      ← tidak dipakai
  api/*.js           ← file lama Vercel, tidak dipakai
  vercel.json        ← tidak dipakai
  server.js          ← hanya untuk mock lokal
  bahan bacaan.md    ← catatan internal
  cloudflare-worker/ ← tidak dipakai
  database/*.sql     ← jalankan manual via phpMyAdmin (lihat langkah 4)
  .git/              ← jangan upload
```

> **Cara upload paling mudah:** Kompres semua file di atas menjadi ZIP, upload ke cPanel, lalu Extract di dalam `/public_html/`.

---

## Langkah 4 — Setup Database (Jalankan Sekali)

Setelah semua file ter-upload, buka browser:

```
https://enuyrasa.my.id/api/setup-db?secret=setup2024
```

Harus muncul:
```json
{"success":true,"tables_created":["products","orders","digital_orders"],"products":[...]}
```

Ini otomatis membuat semua tabel dan mengisi data produk awal (Dodol Wajik, Burayot, Rengginang).

**Alternatif — Import via phpMyAdmin:**
1. cPanel → **phpMyAdmin**
2. Pilih database kamu
3. Tab **Import** → pilih file `database/mysql_migration.sql` → Go

---

## Langkah 5 — Whitelist IP Hosting di Digiflazz

1. Di cPanel → **Server Information** → catat **Shared IP Address**
2. Login [digiflazz.com](https://digiflazz.com) → **Pengaturan** → **Koneksi API** → **Whitelist IP**
3. Tambahkan IP hosting tersebut
4. Set **Callback URL** ke: `https://enuyrasa.my.id/api/digiflazz-webhook`

---

## Langkah 6 — Test Semua Fitur

| URL | Yang diuji |
|-----|-----------|
| `https://enuyrasa.my.id` | Halaman toko — produk tampil |
| `https://enuyrasa.my.id/api/products` | API produk — return JSON |
| `https://enuyrasa.my.id/admin` | Halaman admin — login dengan ADMIN_TOKEN |
| `https://enuyrasa.my.id/digital` | Halaman top-up digital |
| `https://enuyrasa.my.id/admin` → Digiflazz → Test Koneksi | Cek koneksi Digiflazz |

---

## Troubleshooting

| Error | Kemungkinan penyebab |
|-------|---------------------|
| Halaman 500 | Cek `.htaccess` — pastikan nilai DB_* sudah diisi |
| API products kosong | Belum jalankan `/api/setup-db` |
| Upload produk gagal | PHP ekstensi `zip` tidak aktif (cek di cPanel → PHP Extensions) |
| Digiflazz gagal | IP belum di-whitelist di Digiflazz |
| Notif WA tidak masuk | FONNTE_TOKEN atau ADMIN_WA salah |

---

## Struktur URL Final

| URL | File yang dijalankan |
|-----|---------------------|
| `enuyrasa.my.id` | `index.html` |
| `enuyrasa.my.id/admin` | `admin.html` |
| `enuyrasa.my.id/digital` | `digital.html` |
| `enuyrasa.my.id/oleh-oleh` | `oleh-oleh.html` |
| `enuyrasa.my.id/api/products` | `api/products.php` |
| `enuyrasa.my.id/api/orders` | `api/orders.php` |
| `enuyrasa.my.id/api/setup-db` | `api/setup-db.php` |
| `enuyrasa.my.id/api/digiflazz-products` | `api/digiflazz-products.php` |
| `enuyrasa.my.id/api/digiflazz-topup` | `api/digiflazz-topup.php` |
| `enuyrasa.my.id/api/digiflazz-webhook` | `api/digiflazz-webhook.php` |
| `enuyrasa.my.id/api/digiflazz-admin` | `api/digiflazz-admin.php` |
| `enuyrasa.my.id/api/digital-orders` | `api/digital-orders.php` |
| `enuyrasa.my.id/api/game-topup` | `api/game-topup.php` |
| `enuyrasa.my.id/api/process-digital` | `api/process-digital.php` |
| `enuyrasa.my.id/api/import-products` | `api/import-products.php` |
