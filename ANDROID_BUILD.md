# ANDROID BUILD & MAINTENANCE GUIDE — GAJIKU

Aplikasi Android **Gajiku** dibangun menggunakan Capacitor WebView Wrapper yang terhubung langsung secara secure ke server produksi `https://gajiku.nanangmrk.id/`.

---

## 1. Prerequisites (Persyaratan Environment)

- **Node.js**: v18+ (Disarankan Node.js v20/v22)
- **Java JDK**: OpenJDK / JDK 17
- **Android Studio**: Versi terbaru (misal: Hedgehog / Iguana / Jellyfish) dengan Android SDK (API 34) & Build Tools.

---

## 2. Struktur Project Mobile

- `capacitor.config.json` — Konfigurasi utama Capacitor (App ID: `id.nanangmrk.gajiku`, URL Production: `https://gajiku.nanangmrk.id/`)
- `package.json` — Dependency Capacitor (`@capacitor/core`, `@capacitor/android`, `@capacitor/app`, `@capacitor/status-bar`)
- `android/` — Native Android Project (Gradle, AndroidManifest.xml, MainActivity.java, Icon/Splash resources)

---

## 3. Langkah-Langkah Build APK / AAB

### Step 1: Install Node Dependencies & Sync
```bash
npm install
./node_modules/.bin/cap sync android
```

### Step 2: Buka Project di Android Studio
```bash
./node_modules/.bin/cap open android
```
*Atau buka folder `android/` secara langsung dari menu **File -> Open** di Android Studio.*

### Step 3: Build Debug APK dari Android Studio
1. Di Android Studio, pilih menu **Build > Build Bundle(s) / APK(s) > Build APK(s)**.
3. Setelah selesai, lokasi Debug APK:
   `android/app/build/outputs/apk/debug/app-debug.apk`
   *(Atau file yang disalin ke root project: [`gajiku-debug.apk`](file:///Applications/MAMP/htdocs/gajiku/gajiku-debug.apk))*

### Step 4: Build Release APK / AAB (Signed)
1. Di Android Studio, pilih menu **Build > Generate Signed Bundle / APK...**.
2. Pilih **Android App Bundle** (untuk Google Play) atau **APK** (untuk instalasi manual).
3. Masukkan Keystore (Path, Password, Key Alias, Key Password).
4. Klik **Finish**.
5. Lokasi Release AAB / APK:
   `android/app/release/`

---

## 4. Keystore Setup (Untuk Production Release)

Untuk membuat Keystore baru via terminal (opsional):
```bash
keytool -genkey -v -keystore gajiku-release-key.jks -keyalg RSA -keysize 2048 -validity 10000 -alias gajiku-key
```
> **PENTING**: Simpan file `gajiku-release-key.jks` dan password dengan aman. JANGAN simpan keystore di dalam Git repository.

---

## 5. Fitur Keamanan & Persistent Login

1. **Persistent Authentication (Selector & Validator)**:
   - Server PHP Native menggunakan sistem `remember_tokens` di SQLite.
   - Token disimpan dalam Cookie HTTP-only, Secure (HTTPS), SameSite=Lax.
   - Aplikasi Android WebView mempertahankan Cookie & Session secara otomatis saat aplikasi ditutup, di-force close, maupun saat smartphone direstart.
2. **Logout Revocation**:
   - Menekan tombol **Logout** akan menghapus token dari database SQLite server, menghapus cookie persisten, dan menghancurkan PHP session.

---

## 6. Maintenance & Mengganti Production URL

Jika URL server berubah di kemudian hari:
1. Edit file `capacitor.config.json`:
   ```json
   "server": {
     "url": "https://gajiku.nanangmrk.id/",
     "cleartext": false
   }
   ```
2. Jalankan sync ulang:
   ```bash
   ./node_modules/.bin/cap sync android
   ```
