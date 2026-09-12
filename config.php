<?php
// Semua key dibaca dari environment variable (.env file di VPS)
// Jangan isi langsung di sini — gunakan .env

define('GOOGLE_MAPS_API_KEY', getenv('GOOGLE_MAPS_API_KEY') ?: '');

// Penyedia AI untuk itinerary planner, dicoba berurutan (lihat ai_planner.php):
// OpenRouter → Mistral → NVIDIA NIM → Gemini → Groq. Isi key yang dimiliki saja.
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
define('MISTRAL_API_KEY',     getenv('MISTRAL_API_KEY')     ?: '');
define('GEMINI_API_KEY',      getenv('GEMINI_API_KEY')      ?: '');
define('NVIDIA_API_KEY',      getenv('NVIDIA_API_KEY')      ?: '');
define('GROQ_API_KEY',        getenv('GROQ_API_KEY')        ?: '');

// Opsional: true untuk coba foto gratis dari Wikimedia kalau Google tidak menemukan foto.
define('ENABLE_WIKIMEDIA_FALLBACK', true);

// Cache hasil Places API supaya tidak boros request saat halaman dibuka berulang.
define('PLACE_INFO_CACHE_SECONDS', 604800);
