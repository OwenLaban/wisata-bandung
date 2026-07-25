# WisataBandung — Interactive Tourism Map & AI Itinerary Planner

A web platform that maps **253+ tourist destinations across Bandung** on an interactive map, and
turns a visitor's preferences into a ready-to-use multi-day itinerary using an LLM.

🔗 **Live:** <https://wisatabandung.duckdns.org>

---

## Features

- **Interactive map** — Leaflet + OpenStreetMap with category-coloured markers across 10 categories
  (attractions, food, scenery, museums, theme parks, shopping, galleries, zoo, camping, picnic, public art)
- **AI itinerary planner** — give it your interests, trip length, group size and budget, and it returns a
  **1–3 day plan** with cost estimates and the route drawn on the map
- **Smart search & filtering** — by category, name, or nearest to your current location
- **Turn-by-turn routing** between destinations
- **Rich destination data** — photos and ratings pulled from Google Places, with a Wikimedia fallback
- **User accounts** and an **admin dashboard** for managing destinations (CRUD)

## Tech stack

| Layer | Choice |
|---|---|
| Backend | PHP (no framework) |
| Database | MySQL — `wisata`, `users`, `admins` |
| Frontend | Vanilla JavaScript, HTML, CSS |
| Mapping | Leaflet + OpenStreetMap |
| Deployment | Docker + Docker Compose, served over HTTPS |

## Third-party API integrations

Five external services are integrated behind a **7-day response cache** and an **automatic fallback
chain**, which keeps the app fast, resilient when an upstream fails, and inside free-tier quotas.

| Service | Used for |
|---|---|
| **Groq** (LLM) | Generating the AI itinerary |
| **Google Places API (New)** | Destination photos and ratings |
| **Wikimedia Commons** | Photo fallback when Google returns nothing |
| **Nominatim** | Geocoding |
| **OSRM** | Route calculation between destinations |

## Architecture

The front end talks to a small set of JSON endpoints:

| Endpoint | Responsibility |
|---|---|
| `get_wisata.php` | List destinations |
| `get_nearby.php` | Destinations near a coordinate |
| `get_photo.php` | Photo lookup (Google → Wikimedia fallback, cached) |
| `ai_planner.php` | Build an itinerary via the LLM |
| `auth_user.php` | User authentication |
| `admin/api.php` | Admin CRUD |
| `admin/auth.php` | Admin authentication |

`import_geojson.php` seeds the database from `wisata.geojson`.

## Getting started

### Option A — XAMPP

1. Copy the project into `htdocs/`.
2. Create the MySQL database and import the SQL schema.
3. Copy the config template and fill in your keys:
   ```bash
   cp config.example.php config.php
   cp .env.example .env
   ```
4. Start Apache + MySQL, then open <http://localhost/wisata-main/>.

### Option B — Docker

```bash
cp .env.example .env    # fill in the values first
docker compose up -d --build
```

### Environment variables

| Variable | Purpose |
|---|---|
| `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD` | Database credentials |
| `GOOGLE_MAPS_API_KEY` | Google Places API (New) — enable it in Google Cloud |
| `GROQ_API_KEY` | Groq API key for the itinerary planner |

> Keys are read from the environment (`getenv`) — never hard-code them. `.env` is git-ignored.

## Credits

- **Josua Owen Fernandi Silaban** — sole developer: the entire application (map interface, backend,
  API integrations, AI planner, admin dashboard, authentication, deployment).
- **adipang** — collection and scraping of the destination dataset.

## Notes

This started as a two-person project. Ratings and review counts shown on the site are **aggregated from
Google Places**, not user-generated reviews on this platform.
