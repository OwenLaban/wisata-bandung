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
[{\"jam\":\"07.30\",\"hari\":1,\"nama\":\"Tangkuban Perahu\"},{\"jam\":\"10.00\",\"hari\":1,\"nama\":\"Floating Market Lembang\"}] (contoh format saja — GANTI dengan nama destinasi sebenarnya dari itinerary di atas)
##END_PLACES##
Jangan masukkan restoran yang tidak ada di daftar ke PLACES_JSON. DILARANG menulis proses berpikir, analisis, atau rencana kasar — langsung tulis itinerary final. Jawab dalam Bahasa Indonesia.";

// Pemilihan penyedia AI — rantai fallback.
//
// Setiap provider yang punya key dicoba berurutan; yang gagal (timeout,
// 4xx, 5xx, respons kosong) dilewati ke berikutnya. Yang pertama sukses
// dipakai. Urutan: OpenRouter → Gemini → NVIDIA → Groq.
//
// Kenapa urutan ini: dari server produksi (Azure East Asia) Groq menjawab
// 403 sebelum key diperiksa, dan Gemini menolak lokasi ("User location is
// not supported"). OpenRouter lolos karena upstream melihat IP OpenRouter,
// bukan IP server. Dari laptop semua provider jalan, jadi urutan di sana
// hanya soal kecepatan, bukan bisa/tidak.
//
// Model dibaca dari env agar ganti ID tidak perlu edit kode:
// OPENROUTER_MODEL (daftar koma, default di bawah), GEMINI_MODEL
// (default gemini-3.6-flash), NVIDIA_MODEL (default z-ai/glm-5.2).
// Catatan: gemini-2.0-flash dan 2.5-flash sudah retired untuk project baru.
$openrouterKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
$groqKey       = defined('GROQ_API_KEY')       ? GROQ_API_KEY       : '';
$geminiKey     = defined('GEMINI_API_KEY')     ? GEMINI_API_KEY     : '';
$nvidiaKey     = defined('NVIDIA_API_KEY')     ? NVIDIA_API_KEY     : '';

// OPENROUTER_MODEL boleh daftar koma: dicoba satu per satu, misal
// "nvidia/nemotron-3-ultra-550b-a55b:free,nvidia/nemotron-3.5-lightning:free".
// Berguna saat satu model upstream sedang overload / rate-limit.
$openrouterModels = array_filter(array_map('trim', explode(',', getenv('OPENROUTER_MODEL') ?: 'nvidia/nemotron-3.5-lightning:free,nvidia/nemotron-3-ultra-550b-a55b:free,google/gemma-4-31b-it:free,poolside/laguna-s-2.1:free,cohere/north-mini-code:free')));
$geminiModel      = getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash';
$nvidiaModel      = getenv('NVIDIA_MODEL') ?: 'z-ai/glm-5.2';

$providers = [];
if ($openrouterKey !== '') {
    foreach ($openrouterModels as $m) {
        $providers[] = [
            'name'    => 'openrouter',
            'url'     => 'https://openrouter.ai/api/v1/chat/completions',
            'key'     => $openrouterKey,
            'model'   => $m,
            'headers' => [
                'HTTP-Referer: https://wisatabandung.duckdns.org',
                'X-Title: WisataBandung',
            ],
        ];
    }
}
if ($geminiKey !== '') {
    $providers[] = [
        'name'  => 'gemini',
        'url'   => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        'key'   => $geminiKey,
        'model' => $geminiModel,
    ];
}
if ($nvidiaKey !== '') {
    // NVIDIA NIM langsung (build.nvidia.com) — infra beda dari OpenRouter,
    // jadi tetap berguna saat upstream OpenRouter seret. Konteks s/d 1M.
    $providers[] = [
        'name'  => 'nvidia',
        'url'   => 'https://integrate.api.nvidia.com/v1/chat/completions',
        'key'   => $nvidiaKey,
        'model' => $nvidiaModel,
    ];
}
if ($groqKey !== '') {
    // Groq diblokir (403) dari region server produksi; hanya berguna di laptop
    // atau kalau server pindah region.
    $providers[] = [
        'name'  => 'groq',
        'url'   => 'https://api.groq.com/openai/v1/chat/completions',
        'key'   => $groqKey,
        'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
    ];
}

if (!$providers) {
    http_response_code(500);
    echo json_encode(['error' => 'API key AI belum dikonfigurasi. Isi OPENROUTER_API_KEY, GEMINI_API_KEY, NVIDIA_API_KEY, atau GROQ_API_KEY di file .env.']);
    exit;
}

// Batas panjang jawaban disesuaikan durasi trip: 1hr=2000, 2hr=3200, 3hr=4500
$max_tokens = [1 => 2000, 2 => 3200, 3 => 4500][$durasi] ?? 2000;

// Ambil pesan error dari respons provider dalam berbagai format:
// {"error":{"message":"..."}} (OpenRouter/Groq),
// [{"error":{"code":...,"message":"..."}}] (Gemini native, dibungkus array),
// {"error":"..."} (string langsung), {"message":"..."}.
function extractProviderError($response, $curlErr, $httpCode) {
    if ($response === false || $response === '') {
        return $curlErr !== '' ? ('Jaringan: ' . $curlErr) : ('HTTP ' . $httpCode . ' tanpa respons.');
    }
    $detail = json_decode($response, true);
    if (is_array($detail) && isset($detail[0]['error'])) $detail = $detail[0]; // bungkus array ala Gemini
    if (is_array($detail)) {
        if (isset($detail['error']['message'])) return (string)$detail['error']['message'];
        if (isset($detail['error']) && is_string($detail['error'])) return $detail['error'];
        if (isset($detail['message']) && is_string($detail['message'])) return $detail['message'];
    }
    $trimmed = trim(preg_replace('/\s+/', ' ', (string)$response));
    return $trimmed !== '' ? substr($trimmed, 0, 200) : ('HTTP ' . $httpCode . ' tanpa pesan.');
}

$fullText  = '';
$lastCode  = 0;
$lastError = '';
$t0        = microtime(true);
foreach ($providers as $p) {
    // Anggaran total ±55 detik supaya PHP selalu sempat menjawab JSON
    // sebelum nginx (proxy_read_timeout 60s) memutus dengan halaman 504
    // yang memicu "Unexpected token '<'" di frontend.
    $timeout = (int)max(8, min(40, 55 - (microtime(true) - $t0)));
    if ($lastError !== '' && $timeout <= 8) break;
    $payload = [
        'model'       => $p['model'],
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
        'max_tokens'  => $max_tokens,
    ];
    // Model reasoning (Nemotron dkk) kadang menaruh chain-of-thought di
    // content; minta OpenRouter membuangnya agar yang datang itinerary jadi.
    if ($p['name'] === 'openrouter') $payload['reasoning'] = ['exclude' => true];
    $body = json_encode($payload);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $p['key'],
    ];
    if (!empty($p['headers'])) $headers = array_merge($headers, $p['headers']);

    $ch = curl_init($p['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $httpCode === 200) {
        $data      = json_decode($response, true);
        $candidate = $data['choices'][0]['message']['content'] ?? '';
        // Tolak jawaban yang bukan itinerary jadi (mis. chain-of-thought
        // mentah): wajib ada tabel markdown DAN blok PLACES_JSON.
        if ($candidate !== '' && strpos($candidate, '|') !== false
            && stripos($candidate, '##PLACES_JSON##') !== false
            && stripos($candidate, '##END_PLACES##') !== false) {
            $fullText = $candidate;
            break;
        }
        $lastCode  = $httpCode;
        $lastError = 'Format tak lengkap dari ' . $p['name'] . '/' . $p['model'] . '.';
        error_log('[ai_planner] coba gagal: format tak lengkap ' . $p['name'] . '/' . $p['model']);
        continue;
    }

    $lastCode  = $httpCode;
    $lastError = '[' . $p['name'] . '/' . $p['model'] . '] ' . extractProviderError($response, $curlErr, $httpCode);
    error_log('[ai_planner] coba gagal: http=' . $httpCode . ' ' . substr($lastError, 0, 160));
}

if (!$fullText) {
    $rawMsg = $lastError;
    // Terjemahkan pesan rate-limit ke bahasa yang ramah
    if ($lastCode === 429 || stripos($rawMsg, 'rate limit') !== false || stripos($rawMsg, 'tokens per day') !== false) {
        // Coba ekstrak waktu tunggu dari pesan
        $wait = '';
        if (preg_match('/try again in ([\d]+m[\d.]+s)/i', $rawMsg, $wm)) {
            $wait = ' Coba lagi dalam ±' . $wm[1] . '.';
        }
        $msg = '⏳ Kuota AI harian sedang penuh.' . $wait . ' Silakan tunggu beberapa menit lalu coba lagi.';
    } elseif (stripos($rawMsg, 'no longer available') !== false || stripos($rawMsg, 'No endpoints found') !== false) {
        $msg = '⏳ Model AI sedang tidak tersedia. Coba lagi beberapa saat.';
    } else {
        $msg = $rawMsg !== '' ? $rawMsg : 'Gagal menghubungi AI. Coba lagi.';
    }
    error_log('[ai_planner] semua provider gagal: ' . $rawMsg);
    http_response_code(500);
    echo json_encode(['error' => $msg]);
    exit;
}

// ── Pisahkan itinerary text dari places JSON ──────────────────────────────
$placesRaw = [];
$itinerary = $fullText;

if (preg_match('/##PLACES_JSON##\s*([\s\S]*?)\s*##END_PLACES##/i', $fullText, $m)) {
    $itinerary = trim(str_replace($m[0], '', $fullText));
    $jsonText  = trim($m[1]);
    // Model kadang membungkus JSON dalam fence ```json ... ``` — kupas dulu.
    $jsonText  = preg_replace('/^```[a-z]*\s*/i', '', $jsonText);
    $jsonText  = preg_replace('/\s*```$/', '', $jsonText);
    $decoded   = json_decode(trim($jsonText), true);
    if (!is_array($decoded) && preg_match('/\[[\s\S]*\]/', $jsonText, $mm)) {
        $decoded = json_decode($mm[0], true); // fallback: ambil larik [...] pertama
    }
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
