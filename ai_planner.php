<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input       = json_decode(file_get_contents('php://input'), true);
$durasi      = intval($input['durasi'] ?? 1);
$minat       = implode(', ', (array)($input['minat'] ?? ['Alam']));
$budget      = $input['budget'] ?? 'Sedang';
$orang       = intval($input['orang'] ?? 2);
$lokasi_awal = trim($input['lokasi_awal'] ?? 'Pusat Kota Bandung');
$lokasi_lat  = isset($input['lokasi_lat']) ? (float)$input['lokasi_lat'] : null;
$lokasi_lng  = isset($input['lokasi_lng']) ? (float)$input['lokasi_lng'] : null;

if ($durasi < 1 || $durasi > 3) $durasi = 1;
if ($lokasi_awal === '') $lokasi_awal = 'Pusat Kota Bandung';

// ── Deteksi zona user dari koordinat ─────────────────────────────────────
function detectUserZone($lat, $lng) {
    if ($lat === null || $lng === null) return null;
    // ZONA UTARA: Lembang, Subang area (lat > -6.84)
    if ($lat > -6.84 && $lng >= 107.55 && $lng <= 107.72) return 'UTARA';
    // ZONA SELATAN: Ciwidey, Rancabali (lat < -7.02)
    if ($lat < -7.02) return 'SELATAN';
    // ZONA TIMUR ATAS: Dago, Punclut (lat -6.87 s.d -6.84, lng > 107.62)
    if ($lat >= -6.87 && $lat <= -6.84 && $lng > 107.62) return 'TIMUR_ATAS';
    // ZONA BARAT: Padalarang, Cimahi barat (lng < 107.52)
    if ($lng < 107.52) return 'BARAT';
    // Default: pusat kota
    return 'KOTA';
}
$user_zone = detectUserZone($lokasi_lat, $lokasi_lng);

// Label zona untuk prompt
$zone_labels = [
    'UTARA'      => 'ZONA UTARA (Lembang)',
    'SELATAN'    => 'ZONA SELATAN (Ciwidey)',
    'TIMUR_ATAS' => 'ZONA TIMUR ATAS (Dago)',
    'BARAT'      => 'ZONA BARAT (Padalarang)',
    'KOTA'       => 'ZONA KOTA (Pusat Bandung)',
];
$user_zone_label = $user_zone ? ($zone_labels[$user_zone] ?? 'tidak diketahui') : null;

// Instruksi zona hari 1 — hanya jika koordinat tersedia (singkat)
$zona_hari1_instruksi = $user_zone
    ? "PENTING: User di $user_zone_label. Hari 1 WAJIB semua destinasi + makan di $user_zone_label. Jangan ke pusat kota untuk makan."
    : "";

// Kuliner per zona (ringkas)
$kuliner_zona = "Kuliner per zona — gunakan sesuai zona hari itu:
- UTARA: Floating Market Lembang, The Lodge Maribaya, Sari Ater Hot Spring, warung Lembang
- SELATAN: warung lokal Ciwidey, resto area Situ Patengan
- TIMUR ATAS: Cafe D Pakar, Resto Kampung Daun
- KOTA: Batagor Kingsley, Warung Nasi Bancakan, Sindang Reret Naripan, Mie Kocok Mang Dadeng, Cendol Elizabeth, Sate Hadori, Warung Nasi Ampera, Surabi Enhaii, Kopi Progo, Warung Bu Eha, Batagor Riri, Es Oyen, Nasi Goreng Mafia
- BARAT: warung lokal Padalarang";

// Daftar tempat di database (nama persis)
$db_places_hint = "Tempat wisata di sistem (nama PERSIS):
UTARA: Tangkuban Perahu, De Ranch Lembang, Floating Market Lembang, The Lodge Maribaya, Situ Lembang, Sari Ater Hot Spring, Dusun Bambu, Bukit Moko, Bumi Perkemahan Cikole
SELATAN: Kawah Putih, Situ Patengan, Ciwidey Valley Hot Spring, Ranca Upas, Gunung Patuha, Glamping Lakeside Rancabali
TIMUR ATAS: Tebing Keraton, Bukit Bintang Bandung, Puncak Bintang, Cafe D Pakar, Resto Kampung Daun
BARAT: Stone Garden Citatah
KOTA: Gedung Sate, Museum Geologi, Museum Konfrensi Asia Afrika, Alun Alun Bandung, Trans Studio Bandung, Jalan Braga, Jalan Cihampelas, Kebun Binatang Bandung, Saung Angklung Udjo, Pasar Baru Trade Center, Cihampelas Walk CiWalk, Paris Van Java Mall
Camping: Ranca Upas, Bumi Perkemahan Cikole, Glamping Lakeside Rancabali
Lain: Curug Cimahi, Curug Malela, Stone Garden Citatah, The Peak Resort
$kuliner_zona";

$prompt = "Kamu pemandu wisata Bandung profesional. Buat itinerary REALISTIS $durasi hari untuk $orang orang, minat: $minat, budget: $budget, start dari: $lokasi_awal.
$zona_hari1_instruksi

ATURAN ZONA (wajib patuhi — 1 hari = 1 zona):
- UTARA/Lembang (45-60 mnt dari kota): Tangkuban Perahu, De Ranch, Floating Market, The Lodge, Situ Lembang, Sari Ater, Dusun Bambu, Bukit Moko
- SELATAN/Ciwidey (1,5-2 jam): Kawah Putih, Situ Patengan, Ciwidey Valley, Ranca Upas, Gunung Patuha
- TIMUR ATAS/Dago (30-45 mnt): Tebing Keraton, Bukit Bintang, Puncak Bintang
- BARAT/Padalarang (30-45 mnt): Stone Garden Citatah
- KOTA: museum, gedung bersejarah, mall, kuliner kota
LARANGAN: Tangkuban+Kawah Putih 1 hari | kuliner kota di hari zona luar kota | antar tempat >45 mnt dalam 1 hari.

WAKTU KUNJUNGAN: Tangkuban/Kawah Putih/Situ Patengan=2-3j | Trans Studio/De Ranch/Dusun Bambu=3-4j | museum=1-1,5j | kuliner=1j | belanja=1,5j | curug=2j.
BERANGKAT: zona Selatan wajib 06.00-07.00 | zona Utara 06.30-07.00 (kecuali penginapan sudah di sana) | max 4-5 destinasi/hari termasuk makan.
Tiap hari mulai dari $lokasi_awal, sebutkan waktu tempuh ke destinasi pertama, dan waktu kembali malam hari.

$db_places_hint

FORMAT WAJIB — gunakan tabel markdown untuk jadwal setiap hari:

## 🗓️ Itinerary Bandung $durasi Hari
**Tema:** ... | **Budget:** Rp.../orang | **Zona:** Hari1=..., Hari2=...

### 📅 Hari 1: [Judul Hari] — Zona [nama]
| Waktu | Tempat | Aktivitas | Durasi | Estimasi |
|-------|--------|-----------|--------|----------|
| 07.00 | 🚗 Berangkat dari $lokasi_awal | Menuju [zona], ±Xmnt | ±Xmnt | - |
| 07.30 | [Nama Tempat] | [deskripsi singkat] | ±Xj | Rp xxx |
| 12.30 | 🍽️ [Nama Resto/Warung] | Makan siang | ±1j | Rp xxx |
| 17.30 | 🚗 Kembali ke $lokasi_awal | Perjalanan pulang | ±Xmnt | - |
| 19.00 | 🍽️ [Nama Resto] | Makan malam | ±1j | Rp xxx |

[ulangi tabel untuk hari berikutnya — zona berbeda]

### 💡 Tips Perjalanan
- [tips singkat]
### 🚗 Transportasi
- [rekomendasi]

Setelah itinerary tambahkan TEPAT seperti ini:
##PLACES_JSON##
[{\"jam\":\"07.30\",\"hari\":1,\"nama\":\"Nama Persis\"},{\"jam\":\"10.00\",\"hari\":1,\"nama\":\"Nama Persis\"}]
##END_PLACES##
Jangan masukkan restoran yang tidak ada di daftar ke PLACES_JSON. Jawab dalam Bahasa Indonesia.";

// Pemilihan penyedia AI.
//
// Groq memblokir wilayah tempat server produksi berada (Azure East Asia): setiap
// permintaan dari sana dijawab 403 Forbidden bahkan sebelum API key diperiksa —
// key palsu pun ditolak dengan cara yang sama. Jadi Groq tidak bisa dipakai dari
// server, meski jalan normal dari komputer lokal.
//
// Gemini dipilih sebagai penyedia utama karena bisa diakses dari sana dan
// menyediakan endpoint OpenAI-compatible, sehingga bentuk permintaan dan
// pembacaan respons di bawah tidak perlu berubah.
//
// Groq tetap dipertahankan sebagai cadangan: kalau GEMINI_API_KEY kosong tapi
// GROQ_API_KEY terisi, Groq yang dipakai. Berguna saat mengembangkan di laptop,
// atau kalau server nanti pindah ke region yang tidak diblokir.
$geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
$groqKey   = defined('GROQ_API_KEY')   ? GROQ_API_KEY   : '';

if ($geminiKey !== '') {
    $apiKey = $geminiKey;
    $url    = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
    $model  = 'gemini-2.0-flash';
} elseif ($groqKey !== '') {
    $apiKey = $groqKey;
    $url    = 'https://api.groq.com/openai/v1/chat/completions';
    $model  = 'meta-llama/llama-4-scout-17b-16e-instruct';
} else {
    http_response_code(500);
    echo json_encode(['error' => 'API key AI belum dikonfigurasi. Isi GEMINI_API_KEY (atau GROQ_API_KEY) di file .env.']);
    exit;
}

// Batas panjang jawaban disesuaikan durasi trip: 1hr=2000, 2hr=3200, 3hr=4500
$max_tokens = [1 => 2000, 2 => 3200, 3 => 4500][$durasi] ?? 2000;

$body = json_encode([
    'model'       => $model,
    'messages'    => [['role' => 'user', 'content' => $prompt]],
    'temperature' => 0.7,
    'max_tokens'  => $max_tokens,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    $detail = $response ? json_decode($response, true) : null;
    $rawMsg = $detail['error']['message'] ?? '';
    // Terjemahkan pesan rate-limit ke bahasa yang ramah
    if ($httpCode === 429 || stripos($rawMsg, 'rate limit') !== false || stripos($rawMsg, 'tokens per day') !== false) {
        // Coba ekstrak waktu tunggu dari pesan
        $wait = '';
        if (preg_match('/try again in ([\d]+m[\d.]+s)/i', $rawMsg, $wm)) {
            $wait = ' Coba lagi dalam ±' . $wm[1] . '.';
        }
        $msg = '⏳ Kuota AI harian sedang penuh.' . $wait . ' Silakan tunggu beberapa menit lalu coba lagi.';
    } else {
        $msg = $rawMsg ?: 'Gagal menghubungi AI. Coba lagi.';
    }
    http_response_code(500);
    echo json_encode(['error' => $msg]);
    exit;
}

$data     = json_decode($response, true);
$fullText = $data['choices'][0]['message']['content'] ?? '';

if (!$fullText) {
    http_response_code(500);
    echo json_encode(['error' => 'AI tidak menghasilkan respons.']);
    exit;
}

// ── Pisahkan itinerary text dari places JSON ──────────────────────────────
$placesRaw = [];
$itinerary = $fullText;

if (preg_match('/##PLACES_JSON##\s*([\s\S]*?)\s*##END_PLACES##/i', $fullText, $m)) {
    $itinerary = trim(str_replace($m[0], '', $fullText));
    $decoded   = json_decode(trim($m[1]), true);
    if (is_array($decoded)) $placesRaw = $decoded;
}

// ── Geocode via Nominatim (OpenStreetMap) ─────────────────────────────────
function nominatimGeocode($name) {
    $q   = urlencode($name . ', Bandung, Jawa Barat, Indonesia');
    // Gunakan countrycodes=id saja, hindari viewbox dengan karakter minus yang bisa rusak encoding
    $url = "https://nominatim.openstreetmap.org/search?q={$q}&format=json&limit=3&countrycodes=id&accept-language=id";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: WisataBandung/1.0 (contact@wisatabandung.com)'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    if (!$res) return null;
    $data = json_decode($res, true);
    if (!empty($data[0]['lat'])) {
        return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
    }
    return null;
}

// ── Cari koordinat dari database ─────────────────────────────────────────
$places = [];
if (!$conn->connect_error && count($placesRaw) > 0) {
    foreach ($placesRaw as $p) {
        $nama = trim($p['nama'] ?? '');
        if (!$nama) continue;

        // Coba exact match → LIKE → reverse LIKE (nama DB ada di dalam nama AI)
        $stmt = $conn->prepare(
            "SELECT nama, latitude, longitude, kategori
             FROM wisata
             WHERE nama = ? OR nama LIKE ? OR ? LIKE CONCAT('%', nama, '%')
             ORDER BY CASE WHEN nama = ? THEN 0 WHEN nama LIKE ? THEN 1 ELSE 2 END
             LIMIT 1"
        );
        $like = '%' . $nama . '%';
        $stmt->bind_param('sssss', $nama, $like, $nama, $nama, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $places[] = [
                'jam'       => $p['jam']  ?? '',
                'hari'      => intval($p['hari'] ?? 1),
                'nama'      => $row['nama'],
                'latitude'  => (float)$row['latitude'],
                'longitude' => (float)$row['longitude'],
                'kategori'  => $row['kategori'],
            ];
        } else {
            // Tempat tidak ada di DB → coba geocode via Nominatim (gratis)
            // Nominatim mensyaratkan max 1 req/detik — wajib jeda
            usleep(1200000); // 1.2 detik
            $coords = nominatimGeocode($nama);
            $places[] = [
                'jam'      => $p['jam']  ?? '',
                'hari'     => intval($p['hari'] ?? 1),
                'nama'     => $nama,
                'latitude' => $coords ? $coords['lat'] : null,
                'longitude'=> $coords ? $coords['lng'] : null,
                'kategori' => '',
            ];
        }
    }
}

echo json_encode([
    'result' => $itinerary,
    'places' => $places,
    'durasi' => $durasi,
], JSON_UNESCAPED_UNICODE);
