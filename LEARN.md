# Catatan Belajar — WisataBandung

Changelog konsep di balik perubahan base-code. Ditulis supaya kalau nanti lupa,
tinggal baca ulang bagian yang relevan.

---

## 2026-08-03 — Deploy ulang ke VM Azure, menumpang server gondangfarm

### Latar belakang

Server AWS lama (`18.142.236.223`) mati total — ping 100% loss. Konfigurasi deploy-nya
(nginx, SSL) tidak pernah di-commit, jadi ikut hilang bersama servernya. Pelajaran
pertama, sebelum menyentuh kode apa pun:

> **Kalau konfigurasi deploy hanya ada di server, maka ia hilang saat server hilang.**
> Yang tidak masuk git, tidak benar-benar ada.

Karena itu file di bawah ini sekarang menjadi bagian dari repo, bukan lagi hasil
utak-atik langsung di server.

### Apa yang berubah

| File | Status | Isi |
|---|---|---|
| `docker-compose.prod.yml` | baru | Stack produksi: app + MariaDB, tanpa port publik |
| `docker/nginx/wisatabandung.conf` | baru | Blok nginx, ditempel ke config gondangfarm |
| `.env.example` | diubah | (`SITE_DOMAIN`/`ACME_EMAIL` sisa rencana Caddy yang dibatalkan) |

`docker-compose.yml` yang lama **tidak diubah** — masih dipakai untuk XAMPP/laptop.

### Bentuk akhirnya

```
Internet :80/:443
      │
      ▼
  nginx  (milik gondangfarm, sudah ada sebelumnya)
      ├── gondangfarm.duckdns.org  → Django :8000 → Postgres
      └── wisatabandung.duckdns.org → wisata-app :80 → MariaDB
```

Satu VM, satu nginx, dua situs.

### Konsep 1 — Reverse proxy dan kenapa Caddy dibatalkan

Rencana awal memakai Caddy (HTTPS otomatis). Dibatalkan setelah melihat kondisi VM:
**nginx sudah memegang port 80 dan 443**. Dua program tidak bisa mendengarkan port
yang sama — Caddy akan gagal start, atau lebih buruk, membuat nginx gagal.

Pelajarannya bukan "Caddy jelek", tapi:

> Periksa dulu apa yang sudah berjalan di server, baru rancang. Rancangan yang bagus
> di atas kertas bisa mustahil di lingkungan nyata.

Karena nginx sudah ada dan sudah punya sertifikat, menumpang jauh lebih murah daripada
mengganti.

### Konsep 2 — `ports` vs `expose`

```yaml
# docker-compose.yml (laptop) — port bocor ke luar
ports:
  - "8080:80"

# docker-compose.prod.yml — tidak ada `ports` sama sekali
```

Container wisata **tidak membuka port apa pun ke internet**. Satu-satunya jalan masuk
adalah lewat nginx. Kalau ada `ports: "8080:80"` di server, orang bisa melewati nginx
dengan mengetik `http://20.255.56.74:8080` — melewatkan HTTPS dan semua header keamanan.

Container tetap bisa saling bicara lewat jaringan Docker tanpa membuka port ke publik.

### Konsep 3 — Tabrakan nama di jaringan Docker ⚠️

Ini bug nyata yang muncul saat deploy, dan paling berharga untuk diingat.

Percobaan pertama memakai nama service `db`, seperti di compose lokal. Hasilnya:

```
mysqli_sql_exception: Connection refused
```

Bukan "host tidak ditemukan" — tapi "**ditolak**". Bedanya penting: nama `db` berhasil
di-resolve, tapi yang menjawab bukan yang kita maksud.

Sebabnya: `wisata-app` tersambung ke **dua** jaringan sekaligus —
jaringannya sendiri, dan `gondangfarm_default` (supaya nginx bisa menjangkaunya).
Dan gondangfarm juga punya service bernama `db`, yaitu Postgres-nya.

```
wisata-app  ──"db"──> ??? ──> gondangfarm-db (Postgres, port 5432)  ✗ bukan ini
                          └─> wisata-db      (MariaDB,  port 3306)  ✓ yang dimaksud
```

Karena Postgres tidak mendengarkan di 3306, koneksi ditolak.

**Perbaikannya**: beri nama unik — service `db` → `wisata-db`, lalu `DB_HOST: wisata-db`.

> **Aturan praktis:** begitu sebuah container ikut jaringan bersama, nama servicenya
> tidak lagi milik dia sendiri. Pakai nama berawalan project (`wisata-db`, bukan `db`).

### Konsep 4 — `mem_limit` sebagai pagar antar-tetangga

VM ini cuma **1 GiB RAM** dan sudah dipakai server hidroponik yang jalan 24/7.

```yaml
mem_limit: 128m   # app
mem_limit: 256m   # database
```

Tanpa batas ini, kalau wisata bocor memori, kernel Linux akan menjalankan **OOM killer**
dan memilih korban berdasarkan pemakaian memori — bisa saja yang dibunuh adalah backend
Django hidroponik, bukan wisata yang bermasalah.

Dengan `mem_limit`, container yang melewati batasnya dimatikan **duluan dan sendirian**.
Kerusakan terkurung di wisata.

Hasil pengukuran sesungguhnya setelah jalan:

| Container | Terpakai | Batas |
|---|---|---|
| wisata-app | 11,8 MB | 128 MB |
| wisata-db | 67 MB | 256 MB |

Jauh di bawah batas — artinya batasnya aman, bukan mencekik.

### Konsep 5 — Menyetel MariaDB untuk server kecil

```yaml
command: >
  --innodb-buffer-pool-size=64M
  --performance-schema=OFF
  --max-connections=30
```

Setelan bawaan database mengasumsikan server lapang dengan RAM berlimpah.
`innodb-buffer-pool-size` adalah cache data di memori — bawaannya 128 MB ke atas.
`performance-schema` adalah alat diagnostik yang memakan puluhan MB dan tidak dipakai
di project sekecil ini.

Dipilih **MariaDB**, bukan MySQL 8, karena lebih ringan dan **kompatibel penuh dengan
`mysqli`** — 51 pemanggilan database di 9 file PHP tidak perlu diubah satu baris pun.

### Konsep 6 — Validasi sebelum reload

Menambah blok ke `nginx.conf` milik project lain itu berisiko: salah ketik = nginx
gagal, dan situs hidroponik ikut mati. Urutan yang dipakai:

```bash
cp nginx.conf nginx.conf.bak-$(date +%Y%m%d-%H%M%S)   # 1. backup
cat wisatabandung.conf >> nginx.conf                   # 2. tambahkan
docker exec gondangfarm-nginx-1 nginx -t               # 3. VALIDASI
docker exec gondangfarm-nginx-1 nginx -s reload        # 4. reload (hanya jika lolos)
```

`nginx -t` memeriksa syntax tanpa menerapkannya. `nginx -s reload` bersifat *graceful* —
proses lama tetap melayani koneksi yang sedang berjalan sampai selesai, jadi tidak ada
permintaan yang terputus.

> Untuk perubahan yang bisa menjatuhkan layanan orang lain: **backup → validasi → terapkan**.
> Jangan pernah langsung terapkan.

### Konsep 7 — `sed -i` merusak file yang di-bind-mount ⚠️

Bug kedua yang muncul saat deploy, dan penyebabnya sangat tidak intuitif.

Setelah blok HTTPS ditambahkan, `nginx -t` lolos, reload sukses, isi file di host sudah
benar — **tapi nginx tetap menyajikan konfigurasi lama**. `nginx -T` (menampilkan config
yang benar-benar dimuat) membuktikannya: blok :443 masih ter-komentar.

Sebabnya ada di cara Docker mem-bind-mount **satu file**:

> Bind mount sebuah file mengikat **inode**, bukan nama path.

Dan `sed -i` ternyata **tidak** mengedit di tempat, meski namanya "in-place":
ia membuat file sementara, lalu me-rename-nya menimpa file asli. Nama path-nya sama,
tapi **inode-nya baru**. Container masih memegang inode lama:

```
Host:      nginx.conf  ──> inode BARU (isi benar)
Container: default.conf ──> inode LAMA (isi usang)   ← masih terikat ke sini
```

Append pertama memakai `>>` dan berhasil — karena `>>` menulis ke inode yang sama.

**Aturannya:**

| Perintah | Inode | Aman untuk file yang di-bind-mount? |
|---|---|---|
| `echo x >> file` | tetap | ✅ |
| `cat sumber > file` | tetap | ✅ |
| `sed -i`, `mv`, editor tertentu | **berganti** | ❌ |

Kalau terlanjur, satu-satunya obat adalah **restart container** supaya ia mengikat ulang.
Menjalankan `nginx -s reload` tidak menolong — ia tetap membaca inode lama.

Pelajaran yang lebih besar: **`nginx -t` hanya memeriksa file yang dilihat container,
bukan file yang kamu edit.** Kalau ragu, pakai `nginx -T` untuk melihat config yang
sungguh-sungguh aktif.

### Kenapa `server_name` tidak bentrok

Blok gondangfarm memakai `server_name gondangfarm.duckdns.org _;` — tanda `_` itu
catch-all, artinya "terima host apa pun". Sekilas ini terlihat akan menelan wisata juga.

Tapi nginx **selalu mendahulukan `server_name` yang cocok persis** sebelum jatuh ke
catch-all. Jadi `wisatabandung.duckdns.org` masuk ke bloknya sendiri, sisanya ke
gondangfarm. Tidak perlu mengubah blok yang lama sama sekali.

### Konsep 8 — Sertifikat tanpa merebut port 80 (webroot)

Rencana awal memakai DNS-01 (butuh token DuckDNS). Ternyata ada cara lebih sederhana.

Let's Encrypt perlu bukti bahwa kita memiliki domain. Metode HTTP-01 membuktikannya
dengan meminta sebuah file di `http://domain/.well-known/acme-challenge/<token>`.
Certbot `--standalone` menyediakan file itu dengan menjalankan web server sendiri —
karena itu ia butuh port 80, dan gagal saat port itu sudah dipakai nginx.

`--webroot` tidak menjalankan server apa pun. Ia hanya **menaruh file di folder**,
dan membiarkan web server yang sudah ada menyajikannya:

```yaml
volumes:
  - ./webroot/.well-known:/var/www/html/.well-known:ro
```

```bash
sudo certbot certonly --webroot -w /home/azureuser/wisata/webroot \
     -d wisatabandung.duckdns.org
```

Certbot menulis token ke `webroot/.well-known/acme-challenge/`, folder itu terlihat oleh
container wisata, nginx meneruskan permintaannya ke sana, Let's Encrypt membacanya. Nol
downtime, tanpa token DuckDNS, tanpa menyentuh gondangfarm.

Syaratnya satu, dan gampang terlewat — di blok :80, path ACME **tidak boleh ikut
di-redirect** ke HTTPS:

```nginx
location /.well-known/acme-challenge/ { proxy_pass http://$wisata_upstream; }
location / { return 301 https://$host$request_uri; }
```

Kalau ikut di-redirect, perpanjangan otomatis tiap 60 hari akan gagal diam-diam, dan
baru ketahuan saat sertifikat mati.

### Hasil akhir

| | |
|---|---|
| URL | https://wisatabandung.duckdns.org |
| Sertifikat | `CN=wisatabandung.duckdns.org`, berlaku s/d 31 Okt 2026 |
| HTTP | 301 → HTTPS |
| Data | 252 destinasi, 11 kategori |
| RAM terpakai | 79 MB (app 11,8 + db 67) |
| gondangfarm | tetap normal |

### Sisa pekerjaan

- [ ] Isi `GOOGLE_MAPS_API_KEY` & `GROQ_API_KEY` di `~/wisata/.env` di VM, lalu
      `docker compose -f docker-compose.prod.yml up -d` untuk menerapkan
- [ ] **Sertifikat gondangfarm kedaluwarsa 6 Agustus 2026** dan auto-renew-nya rusak
      (`authenticator = standalone`, port 80 dipegang nginx). Sekarang sudah terbukti
      `--webroot` berhasil di server ini — cara yang sama bisa dipakai untuk gondangfarm
