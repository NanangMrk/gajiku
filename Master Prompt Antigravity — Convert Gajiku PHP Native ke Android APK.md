Saya memiliki aplikasi web internal bernama **Gajiku** dengan detail berikut:

- URL produksi: `https://gajiku.nanangmrk.id/`
- Backend: PHP Native
- Database: SQLite
- Frontend: HTML, CSS, JavaScript
- Aplikasi sudah berjalan dan digunakan melalui browser
- Saya ingin aplikasi ini dibuat menjadi **Android APK**
- Prioritas utama: mempertahankan seluruh fitur website yang sudah ada tanpa merusak logic, database, role, session, atau alur aplikasi

Tugas Anda adalah **menganalisis codebase terlebih dahulu**, lalu mengubah project ini agar dapat digunakan sebagai aplikasi Android yang stabil, aman, dan terasa seperti aplikasi Android, bukan hanya website yang dibuka di browser.

Jangan langsung melakukan perubahan besar sebelum memahami struktur project.

# 1. ANALISIS PROJECT TERLEBIH DAHULU

Sebelum melakukan implementasi:

1. Scan seluruh struktur project.
2. Identifikasi:
   - entry point aplikasi
   - struktur routing
   - login
   - logout
   - PHP session
   - middleware/auth guard jika ada
   - tabel user
   - role dan permission
   - koneksi SQLite
   - struktur frontend
   - penggunaan AJAX/fetch
   - upload file
   - download file
   - PDF
   - external link
   - penggunaan cookie
   - penggunaan `localStorage`
   - penggunaan `sessionStorage`
3. Identifikasi bagaimana login saat ini bekerja.
4. Identifikasi bagaimana logout saat ini bekerja.
5. Identifikasi timeout session PHP.
6. Jangan mengganti sistem authentication secara total jika tidak diperlukan.
7. Pertahankan kompatibilitas login melalui browser desktop/mobile.

Setelah memahami struktur, implementasikan solusi dengan perubahan seminimal mungkin tetapi tetap robust.

---

# 2. TARGET UTAMA

Saya ingin hasil akhir berupa aplikasi Android dengan behavior berikut:

```text
Install APK
↓
Buka aplikasi
↓
Login sekali
↓
Masuk dashboard
↓
Tutup aplikasi
↓
Buka kembali
↓
Tetap login
```

Behavior tersebut juga harus berlaku ketika:

```text
Aplikasi di-swipe dari recent apps
Aplikasi di-force close
HP direstart
HP dimatikan lalu dinyalakan
Aplikasi tidak dibuka beberapa hari
```

User **tidak boleh otomatis logout hanya karena aplikasi ditutup**.

User baru logout ketika:

```text
User menekan tombol Logout
```

Namun autentikasi juga harus tetap aman.

Jangan menyimpan:

```text
password plaintext
username + password di localStorage
password di cookie
```

---

# 3. PERSISTENT LOGIN

Implementasikan persistent authentication yang aman.

Jangan hanya mengandalkan lifetime `PHPSESSID` yang sangat panjang.

Gunakan konsep:

```text
PHP Session
+
Remember Login Token
```

Flow:

```text
User login
↓
Password diverifikasi
↓
PHP Session dibuat
↓
Remember token dibuat
↓
Token persisten disimpan melalui secure cookie
↓
Token hash disimpan di SQLite
```

Saat PHP Session sudah hilang tetapi remember token masih valid:

```text
Request masuk
↓
Tidak ada session user
↓
Ada remember cookie
↓
Validasi token ke SQLite
↓
Jika valid
↓
Buat session baru secara otomatis
↓
User tetap masuk
```

Logout:

```text
User Logout
↓
Hapus token dari database
↓
Hapus cookie
↓
Destroy PHP Session
↓
Redirect login
```

---

# 4. SECURITY PERSISTENT TOKEN

Gunakan token cryptographically secure:

```php
random_bytes()
```

Jangan menggunakan:

```php
rand()
mt_rand()
uniqid()
```

Gunakan model seperti:

```text
selector
validator
```

Database hanya menyimpan hash validator.

Contoh konsep:

```text
selector = random
validator = random

cookie:
selector:validator

database:
selector
hash(validator)
```

Validasi menggunakan:

```php
hash_equals()
```

Token harus memiliki expiry.

Gunakan masa berlaku panjang agar user tidak sering login ulang.

Target awal:

```text
365 hari
```

Implementasikan sliding expiration jika masuk akal:

```text
token digunakan secara valid
↓
expiry diperpanjang
atau
token dirotasi
```

Lebih baik lakukan token rotation secara aman jika implementasinya tidak membuat sistem menjadi rapuh.

---

# 5. COOKIE SECURITY

Persistent cookie harus menggunakan konfigurasi yang tepat untuk production HTTPS.

Gunakan:

```php
secure => true
httponly => true
samesite => Lax
path => /
```

Jangan membuat token authentication dapat dibaca melalui JavaScript jika tidak diperlukan.

Jangan menggunakan:

```javascript
localStorage.setItem('password', ...)
```

atau menyimpan credentials sensitif di frontend.

---

# 6. DATABASE SQLITE

Database utama tetap:

```text
SQLite
```

Jangan migrasi ke:

```text
MySQL
PostgreSQL
Firebase
Supabase
```

kecuali saya secara eksplisit meminta.

Tambahkan tabel persistent login menggunakan migration yang aman.

Contoh struktur yang dapat digunakan:

```sql
CREATE TABLE remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    selector TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME
);
```

Sesuaikan foreign key dengan struktur tabel user yang sebenarnya.

Jangan membuat asumsi nama tabel user.

Periksa database/schema terlebih dahulu.

Migration harus:

- aman dijalankan pada database existing
- tidak menghapus data existing
- tidak melakukan reset database
- tidak mengubah data payroll existing

---

# 7. MULTI DEVICE

Sistem idealnya mendukung user login dari beberapa perangkat.

Contoh:

```text
HP pribadi
Tablet
Browser laptop
```

Jangan menggunakan satu kolom remember token langsung pada tabel user jika itu membuat login device lain otomatis rusak.

Gunakan tabel token terpisah sehingga satu user dapat memiliki beberapa active login.

Logout dari satu device sebaiknya hanya menghapus token device tersebut.

Jika project memiliki fitur:

```text
Logout Semua Perangkat
```

atau mudah ditambahkan tanpa mengganggu UI, siapkan helper backend untuk revoke seluruh token user, tetapi jangan menambahkan menu baru yang tidak diperlukan.

---

# 8. ANDROID APPLICATION

Buat aplikasi Android menggunakan pendekatan yang paling sederhana dan maintainable.

Prioritas:

```text
Capacitor
+
Android
+
WebView
```

Aplikasi Android akan menggunakan website produksi:

```text
https://gajiku.nanangmrk.id/
```

Jangan menyalin database SQLite server ke APK.

Jangan membuat database payroll lokal yang terpisah.

Arsitektur:

```text
ANDROID APK
     │
     │ HTTPS
     ▼
gajiku.nanangmrk.id
     │
     ▼
PHP Native
     │
     ▼
SQLite Server
```

Data harus tetap realtime dan terpusat di server.

---

# 9. JANGAN BIKIN SEKADAR BROWSER

APK jangan terasa seperti browser sederhana.

Tidak boleh ada:

```text
address bar
browser toolbar
browser navigation UI
```

Buat shell aplikasi Android yang bersih.

Minimal implementasikan:

- App icon
- App name
- Splash screen
- Main WebView
- Loading state
- Error state ketika internet tidak tersedia
- Pull-to-refresh hanya jika memang cocok
- Android back button handling
- External link handling
- Download handling
- File upload handling
- Persistent cookies
- Correct keyboard behavior
- Correct status bar behavior
- Safe area handling
- Proper viewport
- HTTPS only

---

# 10. APP NAME

Gunakan:

```text
Gajiku
```

Jika perlu application/package ID, gunakan sesuatu yang profesional seperti:

```text
id.nanangmrk.gajiku
```

Pastikan package ID valid untuk Android.

Jangan mengganti URL production.

---

# 11. WEBVIEW PERSISTENCE

Pastikan cookie Android WebView tetap tersimpan setelah aplikasi ditutup.

Konfigurasi WebView agar mendukung:

```text
Cookies
DOM Storage
Local Storage jika website memang menggunakannya
JavaScript
File upload
File download
```

Namun authentication utama harus tetap menggunakan secure cookie/server-side token, bukan bergantung pada localStorage.

Pastikan persistent cookie tidak dibersihkan ketika:

```text
Activity destroyed
Aplikasi masuk background
WebView dibuat ulang
HP restart
```

Jangan memanggil fungsi yang membersihkan cookie saat startup.

---

# 12. STARTUP FLOW

Ketika aplikasi Android dibuka:

```text
Splash Screen
↓
Check connectivity
↓
Open https://gajiku.nanangmrk.id/
↓
Server mengecek PHP Session
↓
Jika session aktif → Dashboard
↓
Jika session habis tetapi remember token valid
↓
Server restore session
↓
Dashboard
↓
Jika tidak ada authentication valid
↓
Login page
```

Jangan membuat authentication Android terpisah dari authentication website kecuali benar-benar diperlukan.

Targetnya:

```text
Satu authentication system
```

yang bekerja untuk:

```text
browser
Android APK
```

---

# 13. LOGOUT HARUS BENAR

Audit tombol logout existing.

Ketika user Logout, pastikan:

```text
remember token current device dihapus dari SQLite
persistent cookie dihapus
PHP session dihapus
session cookie dihapus
```

Setelah logout:

```text
close app
↓
buka kembali
↓
harus tetap berada di Login
```

Jangan sampai remember-token membuat user login kembali setelah sengaja Logout.

---

# 14. SESSION SECURITY

Audit session configuration.

Gunakan security yang masuk akal seperti:

```text
session.use_strict_mode
session.cookie_httponly
session.cookie_secure untuk HTTPS
session.cookie_samesite
session_regenerate_id setelah login
```

Setelah login sukses:

```php
session_regenerate_id(true);
```

Jangan melakukan regeneration berlebihan pada setiap request jika menyebabkan race condition.

---

# 15. CSRF

Jika aplikasi memiliki action sensitif berbasis POST seperti:

```text
ubah password
ubah profil
buat payroll
approve payroll
hapus data
edit karyawan
bonus
task
```

audit apakah sudah memiliki perlindungan CSRF.

Jangan merombak seluruh project hanya untuk ini, tetapi jika implementasinya saat ini jelas berbahaya, tambahkan CSRF protection yang kompatibel tanpa merusak fungsi existing.

---

# 16. XSS DAN OUTPUT

Audit secara proporsional.

Output dari user/database yang tampil ke HTML sebaiknya menggunakan escaping seperti:

```php
htmlspecialchars()
```

Jangan melakukan rewrite besar yang berisiko merusak UI.

Prioritaskan bagian yang berkaitan langsung dengan login/token/security.

---

# 17. ANDROID BACK BUTTON

Tombol Back Android harus bekerja natural.

Behavior:

Jika WebView memiliki history:

```text
Back
↓
goBack()
```

Jika berada di root/dashboard tanpa history berarti:

```text
jangan langsung menutup akibat satu accidental tap jika UX menjadi buruk
```

Gunakan behavior Android yang masuk akal.

Jangan membuat tombol back menyebabkan user logout.

---

# 18. EXTERNAL LINK

Link internal:

```text
gajiku.nanangmrk.id
```

tetap dibuka di dalam aplikasi.

Link external seperti:

```text
WhatsApp
Google Maps
tel:
mailto:
website lain
```

buka menggunakan aplikasi/browser eksternal yang sesuai.

Jangan membuka OAuth/external intents secara sembarangan tanpa handling yang benar.

---

# 19. FILE DOWNLOAD

Website payroll kemungkinan memiliki:

```text
Slip Gaji
PDF
Export
Download
```

Pastikan file dapat di-download dari APK.

Tangani:

```text
application/pdf
application/octet-stream
file attachment
```

dengan benar.

Jika Android membutuhkan permission atau DownloadManager, implementasikan sesuai versi Android modern.

Hindari meminta permission storage luas jika tidak diperlukan.

---

# 20. FILE UPLOAD

Jika website mempunyai:

```html
<input type="file">
```

pastikan bekerja di WebView.

Dukung file chooser Android.

Jika atribut accept menentukan:

```text
image/*
application/pdf
```

ikuti MIME type tersebut.

Jangan meminta kamera/gallery permission kecuali benar-benar diperlukan.

---

# 21. PDF

Jika PDF saat ini dibuka melalui browser:

pastikan di Android hasilnya tetap usable.

Prioritaskan:

```text
download PDF
atau
open PDF menggunakan viewer Android
```

Jangan mengimplementasikan PDF renderer custom jika tidak diperlukan.

---

# 22. INTERNET OFFLINE

Aplikasi payroll membutuhkan server, sehingga jangan berpura-pura aplikasi sepenuhnya offline.

Jika internet tidak tersedia, tampilkan halaman yang bersih:

```text
Tidak ada koneksi internet

Periksa koneksi Anda lalu coba lagi.

[Coba Lagi]
```

Jangan menampilkan error WebView mentah seperti:

```text
net::ERR_INTERNET_DISCONNECTED
```

Tidak perlu membuat sinkronisasi offline database kecuali saya meminta.

---

# 23. SERVER ERROR

Jika:

```text
500
502
503
504
DNS error
timeout
```

berikan UI error sederhana dan tombol Retry.

Jangan menyebabkan infinite reload loop.

---

# 24. UI EXISTING

Jangan redesign seluruh website kecuali ada masalah khusus pada mobile.

Pertahankan:

- layout
- dashboard
- menu
- payroll
- task
- bonus
- karyawan
- profile
- permission
- role

yang sudah ada.

Fokus pekerjaan:

```text
APK
persistent authentication
Android compatibility
```

bukan redesign.

---

# 25. MOBILE VIEW

Audit:

```html
<meta name="viewport">
```

Pastikan website responsive di Android.

Jika layout existing sudah responsive, pertahankan.

Jika ada bug kecil yang membuat WebView tidak nyaman, perbaiki seminimal mungkin.

---

# 26. CAPACITOR PROJECT

Jika codebase belum memiliki Capacitor, buat struktur yang jelas.

Gunakan versi Capacitor yang stabil dan kompatibel dengan toolchain Android yang tersedia.

Jangan memilih dependency usang hanya karena lebih mudah.

Siapkan minimal:

```text
capacitor.config
android/
package configuration
app icon
splash
```

Jika website remote langsung digunakan sebagai `server.url`, konfigurasi dengan benar dan aman.

URL:

```text
https://gajiku.nanangmrk.id
```

Jangan gunakan:

```text
http://
```

untuk production.

---

# 27. ANDROID VERSION

Target Android modern.

Pastikan build kompatibel setidaknya dengan mayoritas perangkat Android yang masih wajar digunakan.

Gunakan:

```text
compileSdk
targetSdk
minSdk
```

yang kompatibel dengan versi Capacitor/Android Gradle Plugin yang dipilih.

Jangan menggunakan versi Gradle/AGP yang saling tidak kompatibel.

Sebelum build, cek compatibility:

```text
Java
Gradle
Android Gradle Plugin
Capacitor
Android SDK
```

---

# 28. BUILD OUTPUT

Saya ingin dapat melakukan:

```bash
npm install
npx cap sync android
```

kemudian build melalui Android Studio atau command line.

Pastikan project dapat menghasilkan:

```text
debug APK
release APK/AAB
```

Berikan lokasi hasil build.

Contoh:

```text
android/app/build/outputs/apk/debug/app-debug.apk
```

Sesuaikan dengan hasil sebenarnya.

---

# 29. RELEASE

Siapkan agar project nantinya dapat menghasilkan:

```text
APK
AAB
```

untuk distribusi internal maupun Google Play.

Jangan memasukkan signing secret/private ke repository.

Jika perlu signing release, buat dokumentasi bagaimana membuat:

```text
keystore
key alias
gradle properties
```

Gunakan environment/local properties untuk data sensitif.

---

# 30. APP ICON DAN SPLASH

Jika asset logo existing tersedia di project, gunakan asset tersebut.

Jangan membuat logo baru yang berbeda tanpa alasan.

Buat:

```text
adaptive icon
launcher icon
splash screen
```

yang bersih dan profesional.

Jika asset yang tersedia resolusinya terlalu kecil, laporkan.

---

# 31. PERMISSION ANDROID

Prinsip:

```text
minimum permissions
```

Gunakan Internet permission.

Tambahkan permission lain hanya jika fitur memang membutuhkannya.

Hindari:

```text
MANAGE_EXTERNAL_STORAGE
READ seluruh storage
permission berlebihan
```

tanpa alasan.

---

# 32. NETWORK SECURITY

Production hanya menggunakan:

```text
HTTPS
```

Jangan mengaktifkan cleartext traffic global jika tidak dibutuhkan.

Jangan bypass SSL validation.

Dilarang membuat:

```text
TrustAllCertificates
hostnameVerifier = always true
disable SSL validation
```

Jika certificate server bermasalah, perbaiki certificate/server. Jangan mengakali keamanan di APK.

---

# 33. WEBVIEW SECURITY

Jangan membuat JavaScript interface/native bridge berbahaya jika tidak diperlukan.

Hindari:

```text
addJavascriptInterface
```

yang mengekspos native methods tanpa kebutuhan.

Batasi navigasi domain.

Internal trusted domain:

```text
gajiku.nanangmrk.id
```

External domains harus diarahkan keluar sesuai kebutuhan.

---

# 34. CACHE

Gunakan cache WebView secara normal.

Jangan membuat sistem caching agresif yang membuat:

```text
data payroll lama
data task lama
status approval lama
```

tetap tampil setelah server berubah.

Server tetap menjadi source of truth.

---

# 35. PHP SESSION DAN COOKIE DI APK

Pastikan domain/path cookie benar:

```text
domain sesuai kebutuhan
path /
secure
httponly
```

Jangan mengikat cookie ke domain yang salah sehingga WebView tidak mendapatkannya.

Audit kemungkinan redirect:

```text
http → https
www → non-www
subdomain lain
```

karena dapat mempengaruhi cookie.

Canonical URL:

```text
https://gajiku.nanangmrk.id/
```

---

# 36. REMEMBER TOKEN HELPER

Pisahkan logic token agar tidak menjadi copy-paste di banyak file.

Contoh struktur sesuai project:

```text
includes/auth.php
includes/remember.php
```

atau pola project existing.

Helper minimal:

```text
createRememberToken()
restoreSessionFromRememberToken()
revokeRememberToken()
revokeAllRememberTokens()
cleanupExpiredRememberTokens()
```

Sesuaikan naming dengan style codebase.

Jangan memaksakan struktur baru jika project memiliki convention sendiri.

---

# 37. CLEANUP EXPIRED TOKEN

Token expired tidak boleh menumpuk selamanya.

Buat cleanup ringan.

Contoh:

ketika membuat token baru, sesekali jalankan:

```sql
DELETE FROM remember_tokens
WHERE expires_at < CURRENT_TIMESTAMP;
```

Jangan menjalankan maintenance berat setiap request.

---

# 38. USER STATUS

Ketika melakukan auto-login melalui remember token:

jangan hanya mengecek token.

Pastikan user masih:

```text
exists
active
boleh login
```

Jika akun:

```text
disabled
inactive
deleted
```

token harus ditolak dan dihapus.

Sesuaikan berdasarkan struktur status user existing.

---

# 39. PASSWORD CHANGE

Jika aplikasi existing memiliki fitur change password:

pertimbangkan security policy:

```text
password berubah
↓
token perangkat lain sebaiknya dapat direvoke
```

Implementasikan jika tidak merusak flow existing.

Minimal jangan membuat token perpetual yang tetap valid tanpa kontrol.

---

# 40. ROLE DAN PERMISSION

Persistent login tidak boleh melewati authorization.

Setelah restore user session:

```text
load user yang sebenarnya
load role yang sebenarnya
load permission yang sebenarnya
```

Jangan menyimpan permission permanen ke remember cookie.

Jika role user berubah, login berikutnya harus menggunakan role terbaru dari database.

---

# 41. NO HARDCODE USER

Jangan hardcode:

```text
admin
superadmin
employee ID
password
user ID
```

Gunakan sistem user existing.

---

# 42. LOGGING

Jangan log:

```text
password
raw remember token
session ID
auth cookie
```

Jika perlu debug, redact data sensitif.

---

# 43. BACKWARD COMPATIBILITY

Setelah modifikasi:

Browser desktop harus tetap bekerja.

Browser mobile harus tetap bekerja.

APK harus bekerja.

Dengan authentication yang sama:

```text
Web
+
Android
```

---

# 44. TESTING WAJIB

Lakukan testing minimal berikut.

## Test A — Login normal

```text
Open APK
Login
Dashboard muncul
```

PASS jika berhasil.

## Test B — Close APK

```text
Login
Close APK
Buka lagi
```

Expected:

```text
langsung Dashboard
```

## Test C — Force stop

```text
Login
Android Settings
Force Stop
Open APK
```

Expected:

```text
langsung Dashboard
```

## Test D — Restart HP/emulator

```text
Login
Restart
Open APK
```

Expected:

```text
langsung Dashboard
```

## Test E — Session PHP expired

Simulasikan session server hilang/expired.

Remember token tetap valid.

Open app.

Expected:

```text
session dibuat ulang
Dashboard
```

## Test F — Logout

```text
Login
Logout
Close app
Open lagi
```

Expected:

```text
Login page
```

## Test G — Invalid token

Manipulasi token.

Expected:

```text
token ditolak
cookie dibersihkan
Login page
```

Tidak boleh terjadi:

```text
500
login bypass
```

## Test H — Expired token

Expected:

```text
Login page
```

## Test I — User disabled

Token valid tetapi akun dinonaktifkan.

Expected:

```text
Login ditolak
token dicabut
```

## Test J — Multiple devices

Login di dua browser/device.

Logout device A.

Expected:

```text
device B tetap login
```

jika menggunakan per-device token.

## Test K — File download

Download slip gaji/PDF.

Expected:

```text
berhasil
```

## Test L — Upload

Jika aplikasi memiliki upload:

Expected:

```text
file chooser Android bekerja
```

## Test M — Back Button

Pastikan:

```text
back navigation bekerja
tidak logout
tidak loop
```

## Test N — Offline

Matikan internet.

Expected:

```text
offline UI
Retry
```

## Test O — Reconnect

Internet hidup kembali lalu Retry.

Expected:

```text
website kembali terbuka
```

---

# 45. JANGAN MELAKUKAN HAL BERIKUT

DILARANG:

- rewrite seluruh aplikasi
- migrasi database tanpa perintah
- mengganti PHP Native dengan framework
- mengganti SQLite
- membuat login Android terpisah
- menyimpan password di aplikasi
- menyimpan raw token di SQLite
- menonaktifkan SSL verification
- memberi WebView permission berlebihan
- menghapus database
- reset users
- reset payroll
- mengubah nominal payroll existing
- merusak role/permission
- membuat dummy system menggantikan backend nyata
- menghapus fitur existing hanya agar build berhasil

---

# 46. BACKUP

Sebelum migration atau perubahan authentication yang signifikan:

buat backup database SQLite.

Contoh sesuai lokasi actual:

```text
payroll.sqlite
↓
payroll.backup-before-android.sqlite
```

Jangan overwrite backup existing.

Jika project menggunakan Git:

cek status terlebih dahulu.

Jangan membuang perubahan user yang belum di-commit.

---

# 47. WORKING METHOD

Kerjakan secara autonomous.

Jangan berhenti hanya untuk meminta konfirmasi pada keputusan teknis kecil yang dapat disimpulkan dari codebase.

Gunakan best judgement.

Jika menemukan dua pendekatan:

pilih solusi yang:

```text
lebih sederhana
lebih aman
lebih mudah dirawat
lebih sedikit mengubah project existing
```

Tetapi jika menemukan risiko yang berpotensi:

```text
menghapus data
merusak database
mengganti architecture utama
mengubah domain
mengubah authentication total
```

jangan melakukan tindakan destruktif.

Pilih alternatif non-destructive.

---

# 48. JANGAN HANYA MEMBERIKAN INSTRUKSI

Saya tidak ingin hanya menerima tutorial.

Saya ingin Anda:

```text
inspect
modify
create files
run migration
install dependency jika diperlukan
build
test
fix error
build ulang
```

hingga project dalam kondisi usable.

Jika build gagal:

baca error.

Perbaiki root cause.

Build ulang.

Jangan berhenti pada error pertama dan hanya menjelaskan error.

---

# 49. VALIDASI SEBELUM SELESAI

Sebelum menyatakan selesai, verifikasi:

```text
PHP syntax
SQLite migration
login
persistent login
logout
Android sync
Gradle build
APK output
```

Gunakan command terkait sesuai environment, misalnya:

```bash
php -l
```

pada file PHP yang berubah.

Untuk Android:

```bash
npx cap sync android
```

dan build menggunakan Gradle yang tersedia.

---

# 50. DOCUMENTATION

Setelah implementasi selesai, buat dokumentasi singkat:

```text
ANDROID_BUILD.md
```

Isi minimal:

```text
Requirements
Install dependencies
Capacitor sync
Open Android Studio
Build debug APK
Build release APK/AAB
Keystore setup
Lokasi output
Mengganti app icon
Mengganti production URL
Troubleshooting
```

Jangan membuat dokumentasi terlalu panjang.

---

# 51. CHANGELOG

Berikan ringkasan file yang:

```text
dibuat
diubah
```

dan alasan perubahan.

Contoh:

```text
includes/auth.php
- menambahkan auto restore session

database/migrate.php
- menambahkan remember_tokens

logout.php
- revoke persistent token

android/...
- native Android wrapper
```

Sesuaikan dengan file sebenarnya.

---

# 52. FINAL REPORT

Pada akhir pekerjaan berikan status seperti:

```text
WEB APPLICATION
[PASS / FAIL]

PERSISTENT LOGIN
[PASS / FAIL]

LOGOUT REVOCATION
[PASS / FAIL]

SQLITE MIGRATION
[PASS / FAIL]

ANDROID PROJECT
[PASS / FAIL]

CAPACITOR SYNC
[PASS / FAIL]

ANDROID BUILD
[PASS / FAIL]

DEBUG APK
[path jika berhasil]

RELEASE READY
[YES / NO]
```

Jika ada FAIL, jelaskan root cause secara spesifik.

Jangan mengatakan sukses jika APK sebenarnya belum berhasil di-build.

---

# 53. PRIORITY ORDER

Kerjakan dengan urutan:

```text
1. Inspect codebase
2. Backup
3. Audit authentication
4. Implement persistent login
5. Update logout
6. Migrate SQLite
7. Test PHP authentication
8. Create Capacitor Android project
9. Configure WebView
10. Configure cookies/storage
11. Configure back/external URL
12. Configure upload/download
13. Configure offline/error handling
14. Sync Android
15. Build APK
16. Test
17. Fix issues
18. Build final APK
19. Documentation
20. Final report
```

---

# 54. DEFINITION OF DONE

Project hanya boleh dianggap selesai ketika kondisi berikut terpenuhi:

```text
Website existing tetap berjalan

User dapat login melalui website

User dapat login melalui APK

Login tetap aktif setelah aplikasi ditutup

Login tetap aktif setelah force stop

Login tetap aktif setelah restart device

Session PHP yang habis dapat direstore melalui persistent token

Logout benar-benar menghapus authentication

User yang sudah logout tidak otomatis masuk lagi

SQLite tetap menjadi database utama

Payroll dan data existing tidak hilang

Android project berhasil sync

APK debug berhasil di-build

Tidak ada bypass SSL

Tidak ada password plaintext

Tidak ada raw remember token di database
```

Fokus utama adalah menghasilkan **Android APK Gajiku yang stabil dengan persistent secure login**, sambil mempertahankan aplikasi PHP Native + SQLite yang saat ini sudah berfungsi.