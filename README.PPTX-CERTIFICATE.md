# Setup: Template Sertifikat PPTX

Panduan setup agar fitur generate sertifikat dari template `.pptx` berfungsi,
baik untuk development lokal di Windows maupun deployment via Docker.

---

## Cara Kerja

1. `Kegiatan.template_sertifikat` diisi path file PPTX relatif terhadap `storage/app/public/`
   - Contoh: `kegiatan/template_sertifikat/1773043128_Sertifikat Webinar CERITA KITA.pptx`
2. Setiap `{{placeholder}}` di slide diganti dengan data peserta.
3. Shape yang berisi teks `{{tte}}` dideteksi → posisinya direkam → shape dihapus.
4. PPTX dikonversi ke PNG menggunakan LibreOffice atau PowerPoint (COM).
5. QR code diletakkan di posisi `{{tte}}` tadi.
6. Output-nya tetap PDF bertanda tangan digital.

### Placeholder yang tersedia

| Placeholder | Isi |
|---|---|
| `{{nama}}` | Nama lengkap peserta |
| `{{peran}}` | Peserta / Narasumber / Moderator |
| `{{nomor_sertifikat}}` | Nomor sertifikat sesuai format KP |
| `{{nama_kegiatan}}` | Nama kegiatan |
| `{{judul_kegiatan}}` | Judul / tema kegiatan |
| `{{tanggal}}` | Tanggal kegiatan (format: 10 Maret 2026) |
| `{{tte}}` | **Posisi QR code** — isi textbox dengan teks ini saja |

> **Catatan:** Ketik placeholder tanpa mengganti format font di tengah kata.
> PowerPoint kadang memecah satu kata ke beberapa XML run jika ada perubahan style
> di tengah pengetikan, sehingga placeholder tidak terbaca.

---

## A. Docker (Deployment / Production)

### 1. Build image

Tidak diperlukan konfigurasi tambahan. LibreOffice sudah ditambahkan ke `Dockerfile`:

```dockerfile
libreoffice font-liberation ttf-freefont
```

Jalankan build seperti biasa:

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
```

### 2. Verifikasi LibreOffice di dalam container

```bash
docker exec -it <container_name> libreoffice --version
# Output contoh: LibreOffice 7.x.x
```

### 3. Upload template PPTX

Upload file `.pptx` melalui API/frontend ke path `kegiatan/template_sertifikat/`.
Pastikan `storage/app/public/` ter-mount dan writable oleh container.

---

## B. Development Lokal di Windows (`composer run dev`)

Di Windows, ada **dua pilihan** converter. Pilih salah satu.

---

### Pilihan 1 — LibreOffice (Rekomendasi)

Gratis, tidak perlu Microsoft Office, bekerja persis seperti di Docker.

#### Langkah-langkah

**1. Download & install LibreOffice**

- Buka: https://www.libreoffice.org/download/download/
- Pilih versi terbaru → **Windows (64-bit)** → download `.msi`
- Jalankan installer, biarkan path default:
  `C:\Program Files\LibreOffice\`

**2. Verifikasi instalasi**

Buka **PowerShell** baru (penting: buka baru agar PATH ter-refresh):

```powershell
& "C:\Program Files\LibreOffice\program\soffice.exe" --version
# Output contoh: LibreOffice 7.x.x
```

**3. Jalankan server**

```bash
composer run dev
```

Aplikasi akan otomatis mendeteksi `soffice.exe` di path default. Tidak ada konfigurasi `.env` tambahan yang diperlukan.

---

### Pilihan 2 — Microsoft PowerPoint via COM

Gunakan ini jika sudah punya Microsoft Office terinstall dan tidak ingin install LibreOffice.

#### Langkah-langkah

**1. Pastikan Microsoft PowerPoint terinstall**

Cek di Start Menu → cari "PowerPoint". Jika sudah ada, lanjut ke langkah berikutnya.

**2. Aktifkan ekstensi `com_dotnet` di `php.ini`**

Cari lokasi `php.ini` yang aktif:

```powershell
php --ini
# Lihat baris: Loaded Configuration File: C:\...\php.ini
```

Buka file `php.ini` tersebut, cari baris berikut dan **hapus titik koma** di depannya:

```ini
; Sebelum (nonaktif):
;extension=com_dotnet

; Sesudah (aktif):
extension=com_dotnet
```

**3. Restart PHP dan jalankan server**

Tutup terminal lama, buka terminal baru:

```bash
composer run dev
```

**4. Verifikasi**

```powershell
php -m | Select-String "com_dotnet"
# Output: com_dotnet
```

#### Catatan penting untuk COM

- PowerPoint akan terbuka sebagai proses background saat generate sertifikat.
  Jangan tutup paksa proses tersebut selama konversi berlangsung.
- Jika muncul dialog "Enable Macros" dari PowerPoint, klik **Disable** atau
  nonaktifkan Protected View di **File → Options → Trust Center → Trust Center Settings
  → Protected View** → unchecklist semua opsi.

---

## Troubleshooting

### Error: LibreOffice tidak ditemukan

- Pastikan LibreOffice sudah diinstall di path default.
- Buka terminal **baru** setelah instalasi (PATH perlu di-refresh).
- Cek manual: `Test-Path "C:\Program Files\LibreOffice\program\soffice.exe"`

### Error: COM: Microsoft PowerPoint tidak tersedia

- Pastikan `extension=com_dotnet` sudah uncomment di `php.ini` (tanpa titik koma).
- Pastikan Microsoft PowerPoint adalah versi desktop (bukan Office 365 web).
- Restart terminal setelah mengubah `php.ini`.

### PNG tidak dihasilkan / slide kosong

- Pastikan placeholder diketik dalam satu run (jangan ganti format di tengah kata).
- Cek log Laravel di `storage/logs/laravel.log` untuk detail error.

### Template tidak ditemukan (500)

- Pastikan `template_sertifikat` berisi path relatif terhadap `storage/app/public/`.
- Contoh benar: `kegiatan/template_sertifikat/namafile.pptx`
- Cek file ada di disk: `storage/app/public/kegiatan/template_sertifikat/namafile.pptx`
