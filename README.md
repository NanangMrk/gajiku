# 🏢 Gajiku — Sistem Payroll & Task Management

Aplikasi web berbasis PHP untuk manajemen penggajian karyawan (payroll), tugas tim, dan monitoring performa. Dirancang ringan, cepat, dan dapat dijalankan tanpa framework eksternal.

---

## 📋 Daftar Isi

- [Fitur Utama](#-fitur-utama)
- [Teknologi yang Digunakan](#-teknologi-yang-digunakan)
- [Persyaratan Sistem](#-persyaratan-sistem)
- [Instalasi Lokal (MAMP / XAMPP)](#-instalasi-lokal-mamp--xampp)
- [Instalasi di aaPanel (VPS/Server)](#-instalasi-di-aapanel-vpsserver)
- [Struktur Direktori](#-struktur-direktori)
- [Konfigurasi Aplikasi](#%EF%B8%8F-konfigurasi-aplikasi)
- [Menjalankan Migrasi Database](#-menjalankan-migrasi-database)
- [Akun Default (Setelah Seed)](#-akun-default-setelah-seed)
- [Cara Penggunaan Aplikasi](#-cara-penggunaan-aplikasi)
- [Upload ke GitHub](#-upload-ke-github)
- [Pengembangan Lanjutan](#-pengembangan-lanjutan)

---

## ✨ Fitur Utama

| Modul | Deskripsi |
|---|---|
| **Dashboard** | Ringkasan statistik gaji, tugas aktif, dan notifikasi |
| **Manajemen Karyawan** | CRUD karyawan lengkap dengan foto profil, NIK, divisi, jabatan, dan gaji pokok |
| **Divisi & Jabatan** | Kelola struktur organisasi perusahaan |
| **Proses Payroll** | Kalkulasi gaji otomatis per periode, monitoring real-time, dan finalisasi |
| **Slip Gaji** | Pratinjau, cetak, dan filter slip gaji per karyawan & periode |
| **Bonus** | Aturan bonus tim maupun individu dengan perhitungan otomatis |
| **Potongan** | Manajemen jenis potongan (potongan tetap, berdasarkan hari, dll.) |
| **Tugas (Task)** | Penugasan, pemantauan progres, dan laporan tugas karyawan |
| **Notifikasi** | Sistem notifikasi dalam aplikasi |
| **Pengaturan** | Konfigurasi nama perusahaan, hari pembayaran, dan lainnya |
| **Audit Log** | Rekam jejak seluruh aktivitas kritis dalam sistem |
| **Multi-role** | Hak akses berbeda untuk Admin dan Karyawan |

---

## 🛠 Teknologi yang Digunakan

| Komponen | Teknologi | Versi yang Direkomendasikan |
|---|---|---|
| Bahasa Backend | PHP | **8.1 – 8.3** |
| Database | SQLite (via PDO) | **SQLite 3.x** (bawaan PHP) |
| Frontend CSS | Bootstrap | **5.x** (via CDN) |
| Ikon | Font Awesome | **6.x** (via CDN) |
| Template UI | Skydash Admin Template | Free Version |
| Grafik | Chart.js | **4.x** (via CDN) |
| Web Server Lokal | MAMP / XAMPP / PHP Built-in Server | — |
| Web Server Produksi | Nginx / Apache | Nginx **1.18+** / Apache **2.4+** |

> **Catatan:** Aplikasi ini **tidak membutuhkan Composer**, Node.js, npm, atau framework tambahan apapun. Semua dependensi frontend dimuat melalui CDN.

---

## 📦 Persyaratan Sistem

### Wajib (Mandatory)
- **PHP >= 8.1** dengan ekstensi:
  - `pdo` — koneksi database abstrak
  - `pdo_sqlite` — driver SQLite
  - `sqlite3` — engine SQLite
  - `gd` atau `imagick` — resize/proses gambar (untuk foto profil)
  - `fileinfo` — validasi tipe file upload
  - `mbstring` — penanganan string multibyte (Unicode/UTF-8)
  - `session` — manajemen sesi login
  - `openssl` — hashing password & CSRF token

### Opsional (Untuk Produksi)
- **Nginx** atau **Apache** sebagai web server
- **aaPanel** sebagai panel manajemen server (disarankan)

---

## 💻 Instalasi Lokal (MAMP / XAMPP)

### Langkah 1 — Clone atau Salin Proyek

```bash
# Jika menggunakan Git
git clone https://github.com/username/gajiku.git

# Atau salin manual folder ke direktori web server:
# MAMP  : /Applications/MAMP/htdocs/gajiku/
# XAMPP : C:\xampp\htdocs\gajiku\
```

### Langkah 2 — Jalankan Web Server

**Menggunakan MAMP/XAMPP:**
Pastikan Apache/Nginx dan PHP sudah berjalan, lalu akses:
```
http://localhost/gajiku/public/
```

**Menggunakan PHP Built-in Server (tanpa MAMP/XAMPP):**
```bash
cd /path/ke/gajiku
php -S localhost:8000 -t public
```
Lalu buka: `http://localhost:8000`

### Langkah 3 — Inisialisasi Database

```bash
# Dari root direktori proyek
php database/migrate.php

# Isi data awal (role, admin default, dll.)
php database/seed.php
```

> File database SQLite akan otomatis terbuat di `database/payroll.sqlite`

### Langkah 4 — Atur Permission (Linux/macOS)

```bash
chmod -R 755 storage/
chmod -R 755 database/
chmod -R 755 public/uploads/
```

---

## 🌐 Instalasi di aaPanel (VPS/Server)

### Prasyarat aaPanel
Pastikan sudah menginstall di aaPanel:
- **LNMP Stack**: Nginx + PHP 8.1/8.2 + SQLite
- Aktifkan ekstensi PHP: `pdo`, `pdo_sqlite`, `sqlite3`, `gd`, `fileinfo`, `mbstring`

---

### Langkah 1 — Upload Proyek ke Server

**Opsi A — Via Git di Terminal SSH:**
```bash
# SSH ke server
ssh root@ip-server-anda

# Masuk ke direktori web
cd /www/wwwroot/

# Clone proyek
git clone https://github.com/username/gajiku.git gajiku.domain.com
```

**Opsi B — Upload Manual via aaPanel File Manager:**
1. Buka aaPanel → **File** → arahkan ke `/www/wwwroot/`
2. Buat folder baru, misal `gajiku.domain.com`
3. Upload file ZIP proyek → klik kanan → **Extract**

---

### Langkah 2 — Buat Website Baru di aaPanel

1. Buka aaPanel → **Website** → **Add Site**
2. Isi:
   - **Domain**: `gajiku.domain.com` (atau IP server)
   - **Root Directory**: `/www/wwwroot/gajiku.domain.com/public`
   - **PHP Version**: `8.1` atau `8.2`
   - **Database**: Pilih **None** (pakai SQLite)
3. Klik **Submit**

---

### Langkah 3 — Konfigurasi Nginx

Buka aaPanel → **Website** → **Setting** → **Config** dan pastikan konfigurasi Nginx seperti berikut:

```nginx
server {
    listen 80;
    server_name gajiku.domain.com;

    root /www/wwwroot/gajiku.domain.com/public;
    index index.php index.html;

    # Semua request diarahkan ke index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-81.sock;
        fastcgi_index index.php;
        include fastcgi.conf;
    }

    # Blokir akses ke folder sensitif
    location ~ ^/(database|app|storage)/ {
        deny all;
        return 403;
    }

    # Izinkan akses ke assets
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|woff2?)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

---

### Langkah 4 — Inisialisasi Database via SSH

```bash
cd /www/wwwroot/gajiku.domain.com

# Jalankan migrasi
php database/migrate.php

# Jalankan seeder
php database/seed.php
```

---

### Langkah 5 — Atur Permission File

```bash
# Atur kepemilikan ke user web server
chown -R www:www /www/wwwroot/gajiku.domain.com/

# Atur permission folder yang butuh tulis
chmod -R 755 /www/wwwroot/gajiku.domain.com/storage/
chmod -R 755 /www/wwwroot/gajiku.domain.com/database/
chmod -R 755 /www/wwwroot/gajiku.domain.com/public/uploads/
```

---

### Langkah 6 — (Opsional) Aktifkan SSL/HTTPS

1. Di aaPanel → **Website** → **Setting** → **SSL**
2. Pilih **Let's Encrypt** → centang domain → klik **Apply**
3. Aktifkan **Force HTTPS**

---

### Langkah 7 — Aktifkan Ekstensi PHP di aaPanel

1. Buka aaPanel → **App Store** → **PHP 8.1** → **Settings** → **Extensions**
2. Pastikan ekstensi berikut **terinstall dan aktif**:

| Ekstensi | Status |
|---|---|
| `pdo` | ✅ Harus Aktif |
| `pdo_sqlite` | ✅ Harus Aktif |
| `sqlite3` | ✅ Harus Aktif |
| `gd` | ✅ Harus Aktif |
| `fileinfo` | ✅ Harus Aktif |
| `mbstring` | ✅ Harus Aktif |
| `openssl` | ✅ Harus Aktif |

---

## 📁 Struktur Direktori

```
gajiku/
├── app/
│   ├── Config/
│   │   ├── app.php          # Konfigurasi utama (APP_NAME, BASE_URL, path)
│   │   └── database.php     # Koneksi PDO SQLite singleton
│   ├── Helpers/
│   │   ├── auth.php         # Fungsi autentikasi & sesi
│   │   ├── csrf.php         # CSRF token generator & validator
│   │   ├── functions.php    # Helper umum (formatRupiah, formatTanggal, dll.)
│   │   └── permissions.php  # Kontrol hak akses berbasis role
│   ├── Views/
│   │   └── layouts/
│   │       ├── header.php   # HTML head, navbar atas
│   │       ├── sidebar.php  # Sidebar navigasi
│   │       └── footer.php   # Script JS, navigasi mobile
│   └── bootstrap.php        # Entry point inisialisasi semua helper
│
├── database/
│   ├── migrate.php          # Script pembuatan semua tabel SQLite
│   ├── seed.php             # Script pengisian data awal (admin, role, dll.)
│   └── payroll.sqlite       # File database SQLite (auto-generated)
│
├── public/                  # ⚠️ Document Root (arahkan web server ke sini)
│   ├── index.php            # Entry point publik (redirect ke dashboard)
│   ├── login.php            # Halaman login
│   ├── dashboard.php        # Dashboard utama
│   ├── employees.php        # Manajemen karyawan
│   ├── divisions.php        # Manajemen divisi & jabatan
│   ├── payroll.php          # Proses & kalkulasi payroll
│   ├── payslips.php         # Daftar & pratinjau slip gaji
│   ├── payslip-print.php    # Halaman cetak slip gaji
│   ├── tasks.php            # Manajemen tugas karyawan
│   ├── bonuses.php          # Aturan bonus
│   ├── deductions.php       # Aturan potongan
│   ├── settings.php         # Pengaturan perusahaan
│   ├── profile.php          # Profil pengguna
│   ├── audit.php            # Log audit aktivitas
│   ├── assets/              # CSS, JS, gambar (Skydash template)
│   └── uploads/             # Foto profil karyawan & bukti transfer
│
├── storage/                 # Penyimpanan file internal
├── skydash-free/            # Aset template UI (referensi)
└── README.md                # Dokumentasi ini
```

---

## ⚙️ Konfigurasi Aplikasi

Edit file `app/Config/app.php` jika diperlukan:

```php
// Nama aplikasi
define('APP_NAME', 'Gajiku - Payroll & Task Management');

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Path database
define('DATABASE_PATH', ROOT_PATH . '/database/payroll.sqlite');
```

> **BASE_URL** terdeteksi otomatis — tidak perlu diubah secara manual untuk deployment standar.

---

## 🗄 Menjalankan Migrasi Database

```bash
# Dari root direktori proyek
php database/migrate.php
```

**Output yang diharapkan:**
```
Running Database Migration...
✔ Table roles created (or already exists)
✔ Table permissions created (or already exists)
...
Migration complete!
```

```bash
# Isi data awal
php database/seed.php
```

---

## 🔐 Akun Default (Setelah Seed)

| Role | Username | Password | Akses |
|---|---|---|---|
| Super Admin | `admin` | `admin123` | Akses penuh seluruh sistem (Administrator) |
| Admin / HR | `hradmin` | `hr123` | Manajemen Karyawan, Payroll, Tugas, Bonus |
| Karyawan (Teknisi) | `andi` | `emp123` | Slip gaji, Tugas, Chat, Bonus sendiri |
| Karyawan (Teknisi) | `budi` | `emp123` | Slip gaji, Tugas, Chat, Bonus sendiri |

> **⚠️ Penting:** Segera ganti password setelah login pertama melalui menu **Profil**.

---

## 📱 Build Aplikasi Android (APK / AAB)

Aplikasi **Gajiku** telah dilengkapi wrapper native Android berbasis **Capacitor**:
- Konfigurasi: `capacitor.config.json` (App ID: `id.nanangmrk.gajiku`, URL Produksi: `https://gajiku.nanangmrk.id/`)
- Persistent Login: Didukung oleh *persistent remember token* HTTP-only cookies yang aman.

### Langkah Cepat Build APK:
```bash
# 1. Install dependency & sync
npm install
npx cap sync android

# 2. Buka di Android Studio
npx cap open android
```
> Panduan lengkap mengenai signing Keystore, generate APK Debug/Release, dan konfigurasi URL produksi tersedia di file [ANDROID_BUILD.md](file:///Applications/MAMP/htdocs/gajiku/ANDROID_BUILD.md).

---

## 📖 Cara Penggunaan Aplikasi

### 1. Setup Awal (Oleh Admin)
1. Login sebagai Admin
2. Buka **Pengaturan** → isi nama perusahaan, hari pembayaran gaji
3. Buka **Divisi** → tambah divisi dan jabatan
4. Buka **Karyawan** → tambah data karyawan dengan gaji pokok

### 2. Proses Payroll Bulanan
1. Buka **Payroll** → klik **Buat Periode Payroll Baru**
2. Pilih bulan & tahun → klik **Submit**
3. Klik periode yang baru dibuat → klik **Mulai Proses Payroll**
4. Edit nominal gaji individual jika diperlukan
5. Tambah bonus/potongan di menu **Bonus** dan **Potongan**
6. Klik **Finalisasi & Kunci Payroll** → slip gaji otomatis terbuat

### 3. Melihat Slip Gaji
- **Admin**: Buka **Slip Gaji** → filter berdasarkan periode/karyawan
- **Karyawan**: Login → buka **Slip Gaji** → hanya melihat milik sendiri

### 4. Manajemen Tugas
1. Admin membuat tugas di menu **Tugas**
2. Assign ke karyawan dengan tenggat waktu
3. Karyawan update progres & status penyelesaian

---

## 🚀 Upload ke GitHub

### Langkah 1 — Buat `.gitignore`

Buat file `.gitignore` di root proyek:

```
# Database (jangan commit file database yang sudah berisi data)
database/payroll.sqlite

# File upload pengguna
public/uploads/

# Storage internal
storage/

# Sistem operasi
.DS_Store
Thumbs.db

# IDE
.vscode/
.idea/
*.swp

# Log
*.log
```

### Langkah 2 — Inisialisasi Git & Commit Pertama

```bash
cd /path/ke/gajiku

# Inisialisasi repository Git
git init

# Tambahkan semua file (kecuali yang di .gitignore)
git add .

# Commit pertama
git commit -m "feat: initial release Gajiku Payroll & Task Management v1.0.0"
```

### Langkah 3 — Hubungkan ke GitHub

```bash
# Buat repository baru di github.com terlebih dahulu, lalu:
git remote add origin https://github.com/username/gajiku.git

# Push ke branch utama
git branch -M main
git push -u origin main
```

### Langkah 4 — Update Setelah Ada Perubahan

```bash
git add .
git commit -m "fix: perbaikan tampilan mobile payslips"
git push
```

### Deploy Ulang di Server Setelah Push

```bash
# SSH ke server
ssh root@ip-server

cd /www/wwwroot/gajiku.domain.com

# Tarik perubahan terbaru
git pull origin main

# Jalankan migrasi jika ada perubahan skema
php database/migrate.php
```

---

## 🧪 Pengembangan Lanjutan

### Menjalankan Lokal dengan Hot-Reload Sederhana

```bash
php -S localhost:8000 -t public
```

### Struktur Pengembangan yang Disarankan

- Semua logika bisnis diletakkan di file `public/*.php` masing-masing halaman
- Helper dan fungsi umum ditambahkan di `app/Helpers/functions.php`
- Perubahan skema database dilakukan melalui `database/migrate.php`
- Tidak ada build step — edit file → refresh browser

### Menambah Kolom Database Baru

Tambahkan logika migrasi dinamis di `app/Config/database.php` (pola sudah tersedia):

```php
// Contoh: tambah kolom baru ke tabel karyawan
$q = $db->query("PRAGMA table_info(employees)");
$cols = $q->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('nama_kolom_baru', $cols)) {
    $db->exec("ALTER TABLE employees ADD COLUMN nama_kolom_baru TEXT");
}
```

---

## 📞 Dukungan & Kontribusi

- Laporkan bug atau saran fitur melalui **GitHub Issues**
- Pull Request sangat disambut — pastikan kode sudah diuji sebelum dikirim

---

## 📄 Lisensi

Proyek ini dibuat untuk kebutuhan internal. Lisensi disesuaikan dengan kebutuhan tim pengembang.

---

> Dibuat dengan ❤️ menggunakan PHP murni + SQLite — ringan, cepat, tanpa dependensi berat.
