# AGENTS.md
## Payroll & Employee Task Management System

Dokumen ini adalah pedoman utama untuk AI coding agent dalam membangun aplikasi **Payroll & Employee Task Management System**. Baca seluruh dokumen sebelum membuat atau mengubah kode.

AI agent harus mengikuti requirement, arsitektur, aturan bisnis, dan standar UI/UX dalam dokumen ini. Jangan mengubah teknologi utama atau business logic tanpa alasan teknis yang kuat.

---

# 1. Tujuan Aplikasi

Bangun aplikasi internal perusahaan untuk:

- Mengelola karyawan.
- Mengelola divisi dan tim.
- Mengelola tugas/to-do list karyawan.
- Mengelola gaji pokok.
- Mengelola bonus individu.
- Mengelola bonus tim.
- Mengelola pencapaian/transaksi bonus.
- Mengelola potongan dan penyesuaian gaji.
- Memproses payroll berdasarkan periode.
- Membuat dan mencetak slip gaji.
- Menyediakan histori payroll.
- Memberikan notifikasi kepada pengguna.
- Menyediakan dashboard berbeda berdasarkan role.
- Menyediakan audit log untuk aktivitas penting.

Aplikasi harus sederhana digunakan tetapi struktur kode dan database harus cukup fleksibel untuk dikembangkan di kemudian hari.

---

# 2. Technology Stack

WAJIB menggunakan:

- PHP Native.
- PHP 8.2+.
- SQLite.
- PDO untuk seluruh koneksi database.
- HTML5.
- CSS3.
- Vanilla JavaScript.
- Font Awesome untuk icon.

Jangan menggunakan framework backend seperti:

- Laravel
- CodeIgniter
- Symfony

Jangan menggunakan framework frontend seperti:

- React
- Vue
- Angular

Library tambahan diperbolehkan hanya jika benar-benar diperlukan, misalnya untuk:

- PDF generation.
- Chart.
- Date picker.

Hindari dependency yang tidak diperlukan.

---

# 3. Database

Gunakan:

```text
SQLite
```

Database utama:

```text
/database/payroll.sqlite
```

Semua komunikasi database WAJIB menggunakan PDO.

Contoh:

```php
$db = new PDO(
    'sqlite:' . __DIR__ . '/../database/payroll.sqlite'
);

$db->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$db->setAttribute(
    PDO::ATTR_DEFAULT_FETCH_MODE,
    PDO::FETCH_ASSOC
);
```

Jangan menulis kode aplikasi yang terlalu bergantung pada fitur eksklusif SQLite.

Tujuannya agar aplikasi relatif mudah dimigrasikan ke MySQL/MariaDB di kemudian hari.

---

# 4. Security

Security bukan fitur opsional.

WAJIB:

- Gunakan `password_hash()` untuk password.
- Gunakan `password_verify()` saat login.
- Gunakan prepared statement PDO.
- Jangan menyusun SQL dari input pengguna secara langsung.
- Escape output HTML.
- Gunakan CSRF protection untuk operasi POST penting.
- Regenerate session ID setelah login.
- Validasi semua input server-side.
- Jangan hanya mengandalkan validasi JavaScript.
- Lindungi halaman berdasarkan authentication.
- Lindungi halaman berdasarkan role/permission.
- Jangan expose database SQLite melalui public URL.
- Jangan menyimpan password dalam plaintext.

Direktori database harus tidak dapat diakses langsung melalui browser.

---

# 5. Struktur Aplikasi

Gunakan struktur modular.

Contoh:

```text
/
├── index.php
├── login.php
├── logout.php
│
├── config/
│   ├── app.php
│   └── database.php
│
├── database/
│   ├── payroll.sqlite
│   ├── migrations/
│   └── seeds/
│
├── includes/
│   ├── auth.php
│   ├── permissions.php
│   ├── functions.php
│   ├── csrf.php
│   ├── header.php
│   ├── sidebar.php
│   ├── navbar.php
│   └── footer.php
│
├── dashboard/
│
├── tasks/
│
├── payroll/
│
├── payslips/
│
├── bonuses/
│
├── deductions/
│
├── employees/
│
├── divisions/
│
├── teams/
│
├── notifications/
│
├── profile/
│
├── settings/
│
├── audit/
│
├── storage/
│   ├── backups/
│   ├── payslips/
│   └── uploads/
│
└── assets/
    ├── css/
    ├── js/
    ├── images/
    └── vendor/
```

Jangan membuat satu file PHP besar yang menangani seluruh aplikasi.

Pisahkan logic berdasarkan modul.

---

# 6. Landing Page

Sebelum login, pengguna melihat landing page.

Landing page harus:

- Modern.
- Clean.
- Profesional.
- Responsive.
- Menjelaskan fungsi aplikasi secara singkat.
- Memiliki CTA Login.
- Tidak terlalu ramai.

Header minimal:

```text
Logo / Nama Perusahaan

Beranda
Tentang Aplikasi
Login
```

CTA utama:

```text
Masuk ke Aplikasi
```

---

# 7. Authentication

Pengguna harus login sebelum masuk dashboard.

Login menggunakan:

```text
Email / Username
Password
```

Session harus digunakan untuk mempertahankan login.

Setelah login berhasil:

```text
session_regenerate_id(true);
```

Redirect berdasarkan akses pengguna.

---

# 8. Role & Permission

Minimal terdapat:

## Super Admin

Akses penuh.

Dapat:

- Mengelola user.
- Mengelola karyawan.
- Mengelola divisi.
- Mengelola tim.
- Mengelola bonus.
- Mengelola potongan.
- Mengelola tugas.
- Approve bonus.
- Memproses payroll.
- Finalisasi payroll.
- Melihat semua slip.
- Mengatur aplikasi.
- Backup database.
- Melihat audit log.

## Admin / HR

Permission dapat diberikan sesuai kebutuhan.

Contoh:

- Manage employee.
- Manage task.
- Manage bonus.
- Approve bonus.
- Process payroll.
- View payroll.

## Karyawan

Hanya dapat mengakses data yang relevan dengan dirinya.

Contoh:

- Dashboard sendiri.
- Tugas sendiri.
- Mengubah status tugas sendiri.
- Melihat bonus sendiri.
- Melihat slip gaji sendiri.
- Melihat notifikasi sendiri.
- Mengelola profil sendiri.

Jangan mengandalkan penyembunyian menu sebagai security.

Setiap endpoint PHP harus melakukan pengecekan permission server-side.

---

# 9. Dashboard

Dashboard Super Admin dan Karyawan harus berbeda.

## Dashboard Super Admin

Tampilkan card seperti:

```text
Total Karyawan

Payroll Periode Aktif

Estimasi Total Payroll

Total Bonus

Total Potongan

Tugas Aktif

Tugas Terlambat

Payroll Belum Final
```

Tambahkan:

- Statistik payroll.
- Statistik tugas.
- Aktivitas terbaru.
- Bonus menunggu approval.
- Deadline tugas terdekat.

Gunakan chart hanya jika memberikan informasi yang berguna.

Jangan memenuhi dashboard dengan chart dekoratif.

---

# 10. Dashboard Karyawan

Dashboard karyawan fokus pada kebutuhan sehari-hari.

Contoh:

```text
Selamat datang, [Nama]

Tugas Hari Ini

Tugas Belum Selesai

Tugas Terlambat

Estimasi Bonus Periode Ini

Slip Gaji Terakhir

Notifikasi Terbaru
```

Berikan quick action untuk membuka tugas.

---

# 11. Karyawan

Setiap karyawan memiliki:

```text
Nama
NIK Internal
Username
Email
Nomor Telepon
Alamat
Divisi
Tim
Jabatan
Tanggal Bergabung
Status
Gaji Pokok
Nomor Rekening
Nama Bank
Foto Profil
```

Status:

```text
Aktif
Nonaktif
Resign
```

Detail karyawan menggunakan tab:

```text
Overview
Tugas
Bonus
Payroll
Slip Gaji
```

---

# 12. Histori Gaji Pokok

JANGAN hanya menyimpan satu nilai gaji pokok yang terus ditimpa.

Gunakan histori.

Contoh:

```text
01 Januari 2026
Rp3.000.000

01 September 2026
Rp3.500.000
```

Payroll harus menggunakan gaji yang berlaku pada periode tersebut.

Perubahan gaji sekarang tidak boleh mengubah payroll lama.

---

# 13. Divisi

Administrator dapat:

- Tambah divisi.
- Edit divisi.
- Nonaktifkan divisi.
- Melihat anggota.

Contoh:

```text
Teknisi
Editor
Toko
Administrasi
```

---

# 14. Tim

Tim berbeda dengan divisi.

Contoh:

```text
Divisi Teknisi

├── Tim A
│   ├── Andi
│   └── Budi
│
└── Tim B
    ├── Candra
    └── Deni
```

Tim digunakan terutama untuk:

- Assignment tugas.
- Project.
- Bonus tim.

Satu tim berada dalam satu divisi.

---

# 15. Task / To-do List

Task dapat diberikan kepada:

```text
Karyawan tertentu
```

atau:

```text
Tim
```

atau:

```text
Divisi
```

Setiap task memiliki:

```text
Judul
Deskripsi
PIC
Divisi
Tim
Tanggal dibuat
Tanggal mulai
Deadline
Prioritas
Status
Lampiran
Catatan
Created by
```

---

# 16. Status Task

Status utama:

```text
Belum Dikerjakan
Sedang Dikerjakan
Selesai
```

Sistem juga harus dapat menandai:

```text
Terlambat
```

jika deadline telah lewat dan tugas belum selesai.

Prioritas:

```text
Rendah
Normal
Tinggi
Urgent
```

Gunakan badge yang jelas.

---

# 17. Task History

Setiap perubahan penting harus dicatat.

Contoh:

```text
10 Agustus 2026 08:00
Task dibuat oleh Admin

10 Agustus 2026 09:14
Andi mengubah:
Belum Dikerjakan → Sedang Dikerjakan

11 Agustus 2026 15:42
Andi mengubah:
Sedang Dikerjakan → Selesai
```

History jangan ditimpa.

---

# 18. Bonus

Terdapat dua kategori utama:

```text
Bonus Individu
Bonus Tim
```

Bonus harus menggunakan sistem master bonus.

Jangan langsung memasukkan bonus sebagai angka ke slip gaji tanpa sumber transaksi.

---

# 19. Master Bonus Individu

Administrator dapat menentukan:

```text
Nama Bonus
Divisi
Metode Perhitungan
Nominal
Satuan
Status
```

Contoh:

```text
Bonus Pasang ODP

Divisi:
Teknisi

Metode:
Per Unit

Tarif:
Rp10.000

Satuan:
ODP
```

---

# 20. Metode Perhitungan Bonus

Minimal support:

```text
Fixed
Per Unit
Percentage
Manual
```

## Fixed

```text
Bonus = Rp500.000
```

## Per Unit

```text
Rp10.000 × 12 ODP
= Rp120.000
```

## Percentage

```text
5% × nilai dasar
```

## Manual

Nominal ditentukan administrator ketika transaksi bonus dibuat.

---

# 21. Transaksi Bonus Individu

Master bonus dan transaksi bonus WAJIB dipisahkan.

Contoh master:

```text
Bonus ODP
Rp10.000 / ODP
```

Transaksi:

```text
Karyawan:
Andi

Tanggal:
15 Agustus 2026

Jumlah:
12

Tarif saat transaksi:
Rp10.000

Total:
Rp120.000
```

WAJIB menyimpan snapshot tarif.

Jika tarif master berubah kemudian hari, transaksi lama tidak boleh berubah.

---

# 22. Approval Bonus

Status transaksi bonus:

```text
Pending
Approved
Rejected
```

Hanya bonus:

```text
Approved
```

yang dapat masuk payroll.

Simpan:

```text
Approved by
Approved at
```

dan alasan jika rejected.

---

# 23. Bonus Tim

Bonus tim diberikan kepada sebuah tim.

Contoh:

```text
Project Instalasi Area A

Tim:
Teknisi A

Bonus:
Rp1.000.000
```

Metode pembagian minimal:

```text
Dibagi Rata
Nominal Per Anggota
Manual
```

Default:

```text
Dibagi Rata
```

Contoh:

```text
Rp1.000.000
÷
4 anggota

=

Rp250.000 / anggota
```

Simpan hasil pembagian masing-masing anggota sebagai snapshot.

Perubahan anggota tim di kemudian hari tidak boleh mengubah bonus lama.

---

# 24. Integrasi Task dan Bonus

Task dapat memiliki bonus opsional.

Contoh:

```text
Task:
Pasang ODP Desa A

PIC:
Andi

Bonus:
Bonus ODP

Quantity:
1
```

Tetapi menyelesaikan task JANGAN langsung menambah payroll.

Flow:

```text
TASK SELESAI

↓

BUAT PENGAJUAN BONUS

↓

PENDING

↓

ADMIN REVIEW

↓

APPROVED

↓

ELIGIBLE UNTUK PAYROLL
```

Hal ini mencegah manipulasi payroll melalui perubahan status task.

---

# 25. Potongan

Sediakan master jenis potongan.

Contoh:

```text
Kasbon
Terlambat
Tidak Masuk
Pinjaman
Peralatan
Custom
```

Metode:

```text
Fixed
Per Unit
Percentage
Manual
```

Potongan harus memiliki:

```text
Nama
Karyawan
Tanggal
Nominal
Alasan
Created by
```

---

# 26. Payroll

Payroll menggunakan konsep:

```text
PERIODE PAYROLL
```

Contoh:

```text
Payroll Agustus 2026

Periode:
01 Agustus 2026
sampai
31 Agustus 2026

Tanggal Pembayaran:
05 September 2026
```

---

# 27. Status Payroll

Status:

```text
Draft
Review
Final
Paid
```

Flow:

```text
DRAFT
↓
REVIEW
↓
FINAL
↓
PAID
```

Selama Draft/Review, administrator masih dapat melakukan koreksi sesuai permission.

Setelah FINAL, data payroll dikunci.

Jangan mengubah payroll FINAL secara langsung.

Jika koreksi diperlukan, gunakan mekanisme adjustment/revision dengan audit trail.

---

# 28. Kalkulasi Payroll

Konsep:

```text
Gaji Pokok

+

Bonus Individu

+

Bonus Tim

+

Penambahan / Adjustment

-

Potongan

=

Take Home Pay
```

Contoh:

```text
Gaji Pokok
Rp3.000.000

Bonus ODP
12 × Rp10.000
Rp120.000

Bonus Instalasi
4 × Rp25.000
Rp100.000

Bonus Tim
Rp250.000

Potongan Terlambat
-Rp50.000

----------------------------

Take Home Pay
Rp3.420.000
```

---

# 29. Payroll Snapshot

INI ADALAH ATURAN PENTING.

Payroll final harus menyimpan snapshot seluruh komponen.

Jangan menghitung ulang payroll lama menggunakan master data terbaru.

Simpan:

```text
Gaji Pokok saat payroll

Nama bonus

Tarif bonus

Quantity

Total bonus

Bonus tim

Potongan

Adjustment

Take Home Pay
```

Perubahan master bonus/gaji setelah payroll final tidak boleh memengaruhi payroll lama.

---

# 30. Slip Gaji

Setiap payroll final dapat menghasilkan slip gaji.

Format:

```text
[NAMA / LOGO PERUSAHAAN]

SLIP GAJI
AGUSTUS 2026

Nomor Slip:
SLIP/2026/08/00031

Nama:
Andi

NIK:
EMP-001

Divisi:
Teknisi

Jabatan:
Teknisi Lapangan

Periode:
01 - 31 Agustus 2026

Tanggal Pembayaran:
05 September 2026


PENDAPATAN

Gaji Pokok              Rp3.000.000
Bonus ODP                  Rp120.000
Bonus Instalasi            Rp100.000
Bonus Tim                  Rp250.000


POTONGAN

Terlambat                  Rp50.000


TAKE HOME PAY

Rp3.420.000
```

Tambahkan informasi perusahaan dari Settings.

---

# 31. Cetak Slip Gaji

Slip harus dapat:

```text
Dilihat
Dicetak
Download PDF
```

Sediakan halaman khusus:

```text
payslips/print.php
```

Gunakan CSS:

```css
@media print
```

Sidebar, navbar, tombol dan elemen dashboard jangan muncul saat print.

Ukuran print harus cocok untuk dokumen formal.

---

# 32. Nomor Slip

Generate nomor unik.

Contoh:

```text
SLIP/2026/08/00001
SLIP/2026/08/00002
SLIP/2026/08/00003
```

Nomor slip tidak boleh berubah setelah final.

---

# 33. Notifikasi

Gunakan Font Awesome:

```html
<i class="fa-solid fa-bell"></i>
```

Tampilkan unread badge.

Notifikasi contoh:

```text
Anda mendapatkan tugas baru.

Tugas Instalasi ODP jatuh tempo besok.

Bonus Rp120.000 telah disetujui.

Bonus Anda ditolak.

Payroll Agustus telah diproses.

Slip Gaji Agustus tersedia.
```

Notifikasi memiliki:

```text
Read
Unread
```

Klik notifikasi dapat membuka halaman terkait.

---

# 34. Audit Log

Catat aktivitas sensitif.

Contoh:

```text
Login

Tambah/edit/hapus karyawan

Perubahan gaji

Perubahan master bonus

Approval bonus

Reject bonus

Perubahan payroll

Finalisasi payroll

Payroll marked as paid

Perubahan permission

Restore database
```

Contoh:

```text
12 Agustus 2026 14:31

Super Admin mengubah
Bonus ODP

Rp10.000
→
Rp15.000
```

Audit log tidak boleh dapat diedit oleh user biasa.

---

# 35. Profil

Pengguna dapat melihat:

```text
Foto
Nama
Email
Username
Divisi
Tim
Jabatan
```

Pengguna dapat:

- Edit informasi yang diperbolehkan.
- Mengubah password.
- Mengubah foto profil.

Karyawan tidak boleh mengubah sendiri:

- Gaji.
- Divisi.
- Role.
- Permission.
- Bonus.
- Data payroll.

---

# 36. Pengaturan Perusahaan

Sediakan:

```text
Nama Perusahaan
Logo
Alamat
Nomor Telepon
Email
Website
Nama Penanggung Jawab
```

Informasi digunakan di:

- Landing page.
- Dashboard.
- Slip gaji.
- PDF.

---

# 37. Backup SQLite

Karena menggunakan SQLite, fitur backup WAJIB tersedia.

Menu:

```text
Pengaturan
→
Backup Database
```

Contoh nama:

```text
payroll_backup_2026-08-12_1840.sqlite
```

Fitur:

```text
Buat Backup
Download Backup
Daftar Backup
Restore
```

Restore hanya dapat dilakukan Super Admin.

Sebelum restore:

1. Validasi file.
2. Buat backup database aktif.
3. Baru jalankan restore.
4. Catat dalam audit log.

---

# 38. UI / UX

Desain harus:

- Modern.
- Clean.
- Minimalis.
- Profesional.
- Premium.
- Tidak berlebihan.
- Responsive.
- Cepat.
- Mudah dipahami.

Jangan membuat tampilan seperti admin panel lama.

Gunakan:

- Card.
- Rounded corner secukupnya.
- Soft shadow.
- Spacing konsisten.
- Typography hierarchy yang jelas.
- Badge status.
- Table modern.
- Modal yang bersih.
- Toast notification.
- Skeleton/loading indicator jika diperlukan.

Hindari penggunaan gradient berlebihan.

---

# 39. DILARANG MENGGUNAKAN EMOJI

Jangan menggunakan emoji sebagai icon UI.

SALAH:

```text
🏠 Dashboard
💰 Payroll
👤 Karyawan
🔔 Notifikasi
```

BENAR:

Gunakan Font Awesome.

```html
<i class="fa-solid fa-house"></i>
<i class="fa-solid fa-money-check-dollar"></i>
<i class="fa-solid fa-users"></i>
<i class="fa-solid fa-bell"></i>
```

Aturan ini berlaku untuk seluruh aplikasi.

---

# 40. Desktop Navigation

Desktop menggunakan sidebar.

Contoh:

```text
[LOGO]

Beranda

Tugas

Payroll

Slip Gaji

Bonus

Karyawan

Divisi & Tim

Notifikasi


PENGATURAN

Profil

Pengaturan

Keluar
```

Gunakan icon Font Awesome.

Sidebar dapat collapse jika diperlukan.

---

# 41. Mobile Navigation

Jangan hanya mengecilkan sidebar desktop.

Buat pengalaman mobile secara khusus.

Prioritaskan:

```text
Beranda
Tugas
Slip Gaji
Notifikasi
```

Sidebar lengkap dapat menjadi:

```text
Off Canvas Navigation
```

atau menu drawer.

Button harus nyaman disentuh.

Table besar harus memiliki versi responsive.

Jika informasi lebih cocok dalam card pada mobile, ubah table menjadi card.

---

# 42. Responsive Breakpoints

Pastikan aplikasi nyaman digunakan pada:

```text
Desktop
Laptop
Tablet
Smartphone
```

Minimum target:

```text
320px
375px
390px
768px
1024px
1440px
```

Jangan membuat horizontal overflow yang tidak diperlukan.

---

# 43. Format Rupiah

Gunakan format Indonesia.

Contoh:

```text
Rp10.000
Rp150.000
Rp3.500.000
```

Jangan:

```text
Rp 3,500,000.00
```

Simpan nominal dalam database sebagai nilai numerik, bukan string berformat.

---

# 44. Format Tanggal

Gunakan format tampilan Indonesia.

Contoh:

```text
12 Agustus 2026
```

Database tetap gunakan format yang konsisten seperti:

```text
2026-08-12
```

Timezone aplikasi:

```text
Asia/Jakarta
```

---

# 45. Search, Filter dan Pagination

Halaman data besar harus mempunyai:

```text
Search
Filter
Sort
Pagination
```

Terutama:

- Karyawan.
- Tugas.
- Bonus.
- Payroll.
- Slip Gaji.
- Audit Log.

Filter tugas minimal:

```text
Status
Divisi
Tim
Karyawan
Prioritas
Tanggal
```

---

# 46. Delete Behaviour

Jangan sembarangan melakukan hard delete.

Data historis seperti:

- Payroll.
- Slip.
- Bonus yang sudah masuk payroll.
- Audit.
- Histori gaji.

tidak boleh hilang begitu saja.

Untuk master data gunakan:

```text
Active
Inactive
```

atau soft delete jika sesuai.

Selalu tampilkan confirmation dialog untuk operasi berbahaya.

---

# 47. Database Design Principles

Database minimal perlu mencakup konsep:

```text
users

roles
permissions
role_permissions

employees

divisions

teams
team_members

salary_history

tasks
task_assignments
task_history
task_attachments

bonus_types
bonus_transactions

team_bonus_transactions
team_bonus_members

deduction_types
deduction_transactions

payroll_periods
payrolls
payroll_items

payslips

notifications

audit_logs

company_settings
app_settings

database_backups
```

Nama tabel boleh disesuaikan jika ada alasan desain yang lebih baik.

Namun relationship dan business logic harus tetap mengikuti dokumen ini.

---

# 48. Foreign Key

Aktifkan SQLite foreign key:

```sql
PRAGMA foreign_keys = ON;
```

Pastikan relationship database valid.

Jangan mengandalkan application logic saja untuk menjaga integritas data.

---

# 49. Transaction

Gunakan database transaction untuk operasi kritis.

Contoh:

- Finalisasi payroll.
- Generate payroll massal.
- Distribusi bonus tim.
- Restore database.
- Operasi yang mengubah beberapa tabel sekaligus.

Gunakan:

```php
$db->beginTransaction();

try {

    // operations

    $db->commit();

} catch (Throwable $e) {

    $db->rollBack();

    throw $e;
}
```

Jangan membiarkan payroll setengah tersimpan.

---

# 50. Navigation Berdasarkan Permission

Menu sidebar harus otomatis menyesuaikan permission.

Contoh karyawan:

```text
Beranda
Tugas Saya
Slip Gaji
Notifikasi
Profil
Keluar
```

Contoh Super Admin:

```text
Beranda
Tugas
Payroll
Slip Gaji
Bonus
Karyawan
Divisi & Tim
Notifikasi
Profil
Pengaturan
Audit Log
Keluar
```

Tetap lakukan permission check server-side.

---

# 51. Landing Page vs Application

Pisahkan secara visual:

```text
PUBLIC AREA
```

dan:

```text
AUTHENTICATED APPLICATION
```

Landing page jangan terlihat seperti dashboard.

Dashboard jangan menampilkan navigasi landing page.

---

# 52. Empty State

Setiap halaman harus mempunyai empty state.

Contoh:

```text
Belum ada tugas.

Belum ada payroll periode ini.

Belum ada transaksi bonus.

Belum ada slip gaji.
```

Berikan CTA jika pengguna memiliki permission.

Contoh:

```text
+ Tambah Tugas
```

Gunakan Font Awesome jika membutuhkan ilustrasi/icon.

Tidak menggunakan emoji.

---

# 53. Error Handling

Jangan menampilkan PHP stack trace kepada user production.

Gunakan halaman error yang friendly.

Log detail error secara internal.

Contoh pesan:

```text
Terjadi kesalahan saat memproses data.
Silakan coba kembali.
```

Jangan expose:

- SQL query.
- Path server.
- Database path.
- Password.
- Session.
- Stack trace.

---

# 54. Form Validation

Setiap form harus memiliki:

- Required validation.
- Type validation.
- Length validation.
- Numeric validation.
- Server-side validation.
- Pesan error yang jelas.

Jangan hanya menampilkan:

```text
Error!
```

Gunakan:

```text
Nominal bonus harus lebih besar dari Rp0.
```

---

# 55. Performance

Walaupun SQLite digunakan, jangan melakukan query tidak efisien.

Hindari N+1 query.

Gunakan index untuk kolom yang sering digunakan:

```text
employee_id
division_id
team_id
status
payroll_period_id
created_at
due_date
```

Jangan mengambil seluruh database jika hanya membutuhkan beberapa record.

---

# 56. Workflow Utama

Workflow aplikasi:

```text
KARYAWAN

↓

DIVISI

↓

TIM

↓

TUGAS

↓

PENCAPAIAN

↓

BONUS

↓

APPROVAL

↓

PERIODE PAYROLL

↓

GAJI POKOK
+
BONUS INDIVIDU
+
BONUS TIM
+
ADJUSTMENT
-
POTONGAN

↓

REVIEW

↓

FINALISASI

↓

SLIP GAJI

↓

PEMBAYARAN

↓

HISTORI
```

---

# 57. Prinsip Payroll

Selalu prioritaskan:

```text
Accuracy
Traceability
Historical Integrity
Security
Auditability
```

Jangan mengorbankan integritas histori demi membuat implementasi lebih sederhana.

Data payroll lama harus tetap menghasilkan angka yang sama meskipun:

- Gaji karyawan berubah.
- Bonus berubah.
- Karyawan pindah divisi.
- Karyawan pindah tim.
- Nama bonus berubah.
- Anggota tim berubah.
- Pengaturan aplikasi berubah.

Gunakan snapshot untuk data finansial yang telah difinalisasi.

---

# 58. Jangan Overengineering

Walaupun aplikasi harus memiliki struktur yang baik:

JANGAN:

- Membuat microservices.
- Membuat event architecture yang kompleks.
- Membuat repository abstraction berlapis-lapis tanpa kebutuhan.
- Menambahkan Redis.
- Menambahkan Docker sebagai dependency wajib.
- Menggunakan framework besar.
- Membuat API kompleks jika belum dibutuhkan.

Ini adalah aplikasi PHP Native + SQLite.

Pertahankan kesederhanaannya.

---

# 59. Tetapi Jangan Membuat Spaghetti Code

PHP Native bukan berarti semua logic dicampur.

Pisahkan:

```text
Presentation
Business Logic
Database Access
Authentication
Authorization
Helpers
```

Gunakan reusable function/component jika diperlukan.

Jangan copy-paste sidebar/header/form logic ke banyak halaman.

---

# 60. Coding Style

Gunakan:

- Nama variabel yang jelas.
- Function kecil dan fokus.
- Indentasi konsisten.
- Komentar hanya jika diperlukan.
- Bahasa kode konsisten.

Direkomendasikan menggunakan nama teknis bahasa Inggris:

```php
$employee
$payroll
$bonusTransaction
$payrollPeriod
$task
```

UI tetap menggunakan Bahasa Indonesia.

---

# 61. Initial Seeder

Sediakan mekanisme initial setup/seeder.

Buat Super Admin pertama.

Jangan menyimpan password default permanen di repository production.

Jika development membutuhkan credential awal, dokumentasikan dan paksa perubahan password jika diperlukan.

---

# 62. Halaman Pengaturan

Kelompokkan setting agar tidak berantakan:

```text
Pengaturan

├── Perusahaan
├── User & Hak Akses
├── Payroll
├── Bonus
├── Jenis Potongan
├── Tampilan
├── Database & Backup
└── Sistem
```

---

# 63. Konfirmasi Operasi Finansial

Operasi berikut harus menggunakan confirmation:

```text
Approve Bonus
Reject Bonus
Finalisasi Payroll
Mark Payroll as Paid
Restore Database
Nonaktifkan Karyawan
```

Untuk finalisasi payroll tampilkan informasi seperti:

```text
Anda akan memfinalisasi Payroll Agustus 2026.

32 karyawan
Total Payroll: Rp96.450.000

Setelah difinalisasi, data payroll akan dikunci.
```

---

# 64. Slip Gaji Karyawan

Karyawan hanya dapat melihat slip miliknya.

Jangan menerima `employee_id` dari URL kemudian langsung menampilkan slip.

Selalu validasi ownership.

Contoh:

```text
User Login
↓
Employee Relation
↓
Payslip Ownership
↓
Allowed
```

Super Admin dapat melihat seluruh slip.

---

# 65. Audit Finansial

Perubahan manual terhadap payroll Draft/Review harus menyimpan:

```text
Nilai sebelumnya
Nilai baru
Alasan perubahan
User yang mengubah
Tanggal perubahan
```

Ini sangat penting untuk menghindari perubahan angka tanpa jejak.

---

# 66. Final Definition of Done

Sebuah fitur dianggap selesai jika:

- Backend berfungsi.
- UI tersedia.
- Responsive.
- Permission diterapkan.
- Validasi server-side tersedia.
- CSRF diterapkan jika relevan.
- Error handling tersedia.
- Empty state tersedia.
- Audit tersedia untuk operasi sensitif.
- Data historis aman.
- Tidak ada emoji.
- Font Awesome digunakan untuk icon.
- Tidak ada PHP warning/error.
- Tidak ada broken link.
- Tidak ada horizontal overflow mobile.
- Tidak ada placeholder palsu pada halaman production.

---

# 67. Urutan Implementasi

Jangan mencoba membuat semua fitur sekaligus.

Implementasikan dalam urutan:

```text
PHASE 1
Project Structure
Database
Authentication
Role & Permission
Layout

↓

PHASE 2
Landing Page
Dashboard
Profile
Company Settings

↓

PHASE 3
Divisions
Teams
Employees
Salary History

↓

PHASE 4
Tasks
Assignments
Task History

↓

PHASE 5
Bonus Master
Individual Bonus
Team Bonus
Approval

↓

PHASE 6
Deductions
Adjustments

↓

PHASE 7
Payroll Period
Payroll Calculation
Review
Finalization

↓

PHASE 8
Payslip
Print
PDF

↓

PHASE 9
Notifications
Audit Log

↓

PHASE 10
Backup & Restore
Security Review
Responsive Review
Testing
```

Setiap phase harus stabil sebelum masuk ke phase berikutnya.

---

# 68. Instruksi untuk AI Coding Agent

Sebelum membuat fitur:

1. Baca `AGENTS.md`.
2. Periksa struktur project yang sudah ada.
3. Periksa schema database.
4. Jangan membuat tabel duplikat.
5. Jangan membuat ulang fitur yang sudah tersedia.
6. Pertahankan kompatibilitas dengan fitur yang sudah berjalan.
7. Buat perubahan sekecil mungkin tetapi lengkap.
8. Jangan menghapus fitur existing tanpa alasan.
9. Jangan mengubah technology stack.
10. Jangan mengganti SQLite dengan database lain.
11. Jangan menambahkan framework.
12. Jangan menggunakan emoji.
13. Gunakan Font Awesome.
14. Pastikan responsive.
15. Pastikan permission server-side.
16. Pertahankan histori finansial.

Jika menemukan requirement yang ambigu dan keputusan tersebut berpotensi mengubah business logic payroll secara signifikan, jangan menebak secara sembarangan.

Pilih implementasi yang:

```text
aman
dapat diaudit
mempertahankan histori
sederhana
mudah dikembangkan
```

---

# 69. Prinsip Akhir

Aplikasi harus terasa seperti aplikasi bisnis modern, bukan sekadar kumpulan halaman PHP.

Prioritas:

```text
1. Ketepatan perhitungan payroll
2. Integritas histori
3. Security
4. Kemudahan penggunaan
5. Mobile responsiveness
6. Maintainability
7. Visual quality
```

Jangan membuat fitur terlihat selesai hanya karena UI sudah tersedia.

Setiap tombol harus bekerja.

Setiap data harus berasal dari database nyata.

Setiap angka payroll harus dapat ditelusuri sumbernya.

Setiap perubahan finansial penting harus memiliki histori.

Dan setiap slip gaji yang telah difinalisasi harus tetap konsisten selamanya.