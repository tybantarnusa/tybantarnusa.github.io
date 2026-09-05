# AGENTS.md — jadwal-anime (jadwal anime musim berjalan + platform streaming + tombol Todoist)

A single-page dark "TV broadcast board" app listing the CURRENT season's anime schedule
(JST airing day/time), the official streaming platforms per show (from db.silveryasha.id), a live
NEXT UP countdown, and a per-row "+" button that adds the anime as a recurring task in the user's
personal Todoist (season project).

Live app (frontend + backend together): `https://tybantarnusa.com/lab/jadwal-anime/`
(= `public_html/lab/jadwal-anime/` on cPanel). Local repo folder:
`E:\Projects\tybantarnusa.github.io\jadwal-anime` (inside the GitHub Pages repo, branch master).

This AGENTS.md is the authoritative current-state doc for this folder.

---

## 1. Architecture + deploy facts (IMPORTANT)

- **Everything runs on cPanel, NOT GitHub Pages.** The repo only holds source. The GitHub Pages
  copy (`https://tybantarnusa.github.io/jadwal-anime/`) CANNOT call the API — see §5 (origin
  locked to tybantarnusa.com). Do not promise it works.
- Files on the server (same folder `public_html/lab/jadwal-anime/`):
  `index.html` (frontend), `config.js` (API token — GITIGNORED, must match `.env` API_TOKEN),
  `schedule.php` (read API), `todoist.php` (write API), `.env` (secrets — GITIGNORED),
  `.htaccess` (blocks direct `.env` download).
- Repo file inventory:
  - `index.html` — the frontend, all CSS/HTML + one inline `<script>` + loads `config.js`.
  - `backend/schedule.php` — fetch AniList + silveryasha → MySQL → serve JSON (see §2).
  - `backend/todoist.php` — add anime to Todoist (see §4).
  - `backend/.env.example`, `config.example.js` — templates (no secrets).
  - `config.js` — REAL token, gitignored, only on server + local.
  - `.env` — REAL creds, gitignored, only on server + local.
  - `platforms.json` — leftover snapshot from BEFORE the DB backend existed. Superseded,
    unused by the app, safe to delete.
- Repo root `E:\Projects\tybantarnusa.github.io` — commit prefix `jadwal-anime: ...`. Never stage
  unrelated folders (`linklike-story/`, `collage-maker/`, root `AGENTS.md` are separate).
- Deploy = FTP via the `web/ftp-deploy` skill:
  `python "C:\Users\User\AppData\Local\hermes\skills\web\ftp-deploy\scripts\ftp_deploy.py" <local_dir> public_html/lab/jadwal-anime`
  Creds file `E:\Projects\config\ftp-creds.txt` — NEVER read/print into chat.
  Staging dir must contain: `index.html`, `config.js`, `schedule.php`, `todoist.php`, `.env`
  (+ `.htaccess`). `.env` server = copy of local `.env` but `DB_HOST=localhost`.
- Deploy gating: user says when to deploy/commit ("boleh deploy"/"boleh commit"). Push goes to
  `master`. Commits so far were made only after his OK.

---

## 2. Data flow + refresh policy (do NOT change without asking)

`schedule.php` (GET) serves from MySQL. It fetches 3rd-party sources ONLY when:
1. DB has no rows for the CURRENT season (first run / season changed), OR
2. `meta.last_refresh` is older than `REFRESH_AGE_SECONDS = 86400` (24h — keeps `airingAt`
   countdowns/status fresh).

**There is NO manual/forced source refetch.** The frontend "Refresh" button just re-reads the
backend (DB). Do not add a force-refresh param/token — the user explicitly said he does NOT want
to burden 3rd parties ("aku gak pengen kita nyusahin 3rd party").

To force one refresh manually during dev: `UPDATE jadwalanime_meta SET v='0' WHERE k='last_refresh';`
(or delete the current-season rows) then hit the API once.

Sources:
- **AniList** — POST `https://graphql.anilist.co`; REQUIRES a `User-Agent` header (403 without).
  Query: `Page { media(season, seasonYear, type:ANIME, isAdult:false,
  status_in:[RELEASING,NOT_YET_RELEASED], sort:POPULARITY_DESC) { id title{romaji english native}
  status nextAiringEpisode{airingAt} } }`, ~2 pages (78 anime for a season).
- **db.silveryasha.id** — GET `https://db.silveryasha.id/anime/season/{year}/{Season}` (e.g.
  `/2026/Summer`), parse HTML with DOMDocument/DOMXPath: cards = `div.surface-plain`; title =
  `a.text-body-primary`; **official platforms only** = chips `a.season-project-chip` that contain
  an `svg.season-project-chip__check` (fansub groups have no checkmark — skip them).
- Title matching AniList↔silveryasha: normalized exact → substring (min len 12) → `similar_text`
  ≥ 88% → manual override `Koukaku Kidoutai: THE GHOST IN THE SHELL` ⇒ `Koukaku Kidoutai (TV)`.
  Expect ≈68/78 matched, ≈59 with ≥1 platform.

API response shape (schedule.php):
```json
{ "ok": true, "season": "SUMMER", "year": 2026, "refreshed": false,
  "anime": [ { "id": 178789, "title": "...", "status": "RELEASING", "airingAt": 1788706800 } ],
  "platforms": { "178789": [ { "platform": "Bstation", "status": "Jalan", "url": "..." } ] } }
```
`airingAt` is a unix timestamp; JST day/time is derived client-side from it.

---

## 3. MySQL (shared DB — prefix everything)

- Single shared database `tybantar_db` on the cPanel host. All tables prefixed `jadwalanime_`.
- Tables (auto-created by `schedule.php`):
  - `jadwalanime_anime` — `anilist_id` PK, `title`, `status`, `airing_at` (unix, NULL = TBA),
    `season` (e.g. `SUMMER`), `season_year`.
  - `jadwalanime_platforms` — `anilist_id`, `platform`, `status`, `url`; UNIQUE(anilist_id, platform).
  - `jadwalanime_meta` — `k`/`v`; currently stores `last_refresh`.
- Local connection (this PC): Remote MySQL via `pymysql`, host `103.185.53.44`, user/pass/db from
  `.env`. From PHP on the server: `DB_HOST=localhost`. The public IP is NOT a valid server-side host.

---

## 4. Todoist integration (todoist.php) + EMOJI PITFALL

`todoist.php` (POST JSON `{ "anilist_id": N }`) → creates ONE recurring task per anime.

- **Content format:** `"[symbol] [title]"` — symbol chosen by platform priority:
  🅱️ if Bstation → 🅼 if Muse Indonesia → 1️⃣ if Ani-One Asia/Indonesia → 🏴☠️ otherwise (incl. no platform).
- **Recurring day** (from `airing_at`, JST): hour < 6 ("dini hari") → recurring on the airing day
  itself; hour ≥ 6 → recurring the NEXT day. Task title has NO clock time, just "every Tuesday"
  style strings. Example: airs Tue 01:30 → "every Tuesday"; airs Wed 21:00 → "every Thursday".
- **Project:** the season project named after the anime's season (`Summer`/`Fall`/`Winter`/`Spring`)
  — resolved dynamically via Todoist `/projects` (do NOT hardcode project IDs).
- **Anti-duplicate:** scans active tasks (paginated, 50/page via `next_cursor`) for an identical
  `content`; skips creation if found → responds `{created:false, message:"Already in Todoist"}`.
- **Todoist API v1 endpoint** (`api.todoist.com/api/v1/tasks`, `/api/v1/projects`). `rest/v2` and
  `sync/v9` are DEPRECATED ("This endpoint is deprecated"). Create = POST `/api/v1/tasks` with
  `{content, project_id, due_string:"every tuesday", due_lang:"en"}`. Token from `.env`
  `TODOIST_TOKEN`, never in the frontend. The user's account already uses this exact
  "🅱️ <title>" + "every <day>" task format.

### EMOJI PITFALL (real incident — read before touching symbols)
🏴☠️ is 4 codepoints including ZWJ `U+200D`. Writing emojis LITERALLY in PHP source strips the
ZWJ/variation selectors → the stored title renders as TWO separate emojis (flag + skull) in
Todoist. In `pick_symbol()` ALWAYS return `\u{...}` escapes:
```php
"\u{1F171}\u{FE0F}"            // 🅱️
"\u{1F17C}\u{FE0F}"            // 🅼
"1\u{FE0F}\u{20E3}"            // 1️⃣
"\u{1F3F4}\u{200D}\u{2620}\u{FE0F}" // 🏴☠️
```

---

## 5. Security (do not loosen without asking)

- **Origin allowlist** = `https://tybantarnusa.com` only (`const ALLOWED_ORIGIN`, both PHP files).
  - `schedule.php` (GET): empty Origin allowed (same-origin browser GETs send none); any other
    Origin → 403.
  - `todoist.php` (POST): Origin REQUIRED and must equal ALLOWED_ORIGIN (browsers always send
    Origin on POST); missing/mismatch → 403.
- **API token**: header `X-Api-Token` must equal `API_TOKEN` in `.env`, compared with
  `hash_equals`. Frontend value lives in `config.js` (gitignored) and MUST match the server `.env`.
  Rotating = regenerate token, update `.env` (server) + `config.js` (server), redeploy both.
  If `API_TOKEN` is empty in `.env`, the check fails open (token optional) — set it to enforce.
- CORS: `Access-Control-Allow-Origin: https://tybantarnusa.com`, `Vary: Origin`,
  `Access-Control-Allow-Headers: Content-Type, X-Api-Token`.
- Honest model (tell the user if asked): the token ships in public frontend JS — it stops casual
  bots/scripts that don't read the source; the Origin check stops other sites' browsers
  (hotlink/CSRF). It is NOT auth-grade security. Rate limiting was offered but not implemented.

---

## 6. .env (server, same folder as the PHP files — NEVER commit)

```
DB_HOST=localhost        # ON THE SERVER. 103.185.53.44 is only for remote/local access
DB_USER=...
DB_PASS=...
DB_DATABASE=tybantar_db
DB_PREFIX=jadwalanime_
TODOIST_TOKEN=...
API_TOKEN=...
```
Local working copy: `jadwal-anime/.env`. Template: `backend/.env.example`. Both `.env` and
`config.js` are gitignored — before committing, re-scan staged files for `.env` values (see §8).

---

## 7. Frontend (index.html)

- One file, dark "broadcast board" design: Space Grotesk + JetBrains Mono, coral accent
  `#ff4d5c`→`#ff9a3d` on near-black. Deliberately NOT the blue-purple gradient used elsewhere.
  Sticky topbar (ON AIR badge, season pill, Refresh) → NEXT UP countdown bar → "Today on air"
  chips → day-nav pills (Mon..Sun + TBA) → day cards with rows [time | title+platform badges |
  status chip + "+" Todoist button] → footer.
- UI language: ENGLISH (user preference). Day names Monday..Sunday.
- `<script src="config.js">` loads BEFORE the main inline `<script>`; every fetch sends
  `X-Api-Token` from `API_TOKEN`. `API_URL` / `TODOIST_API_URL` consts near the top of the script.
- `loadSchedule()` → `fetchSchedule()` (GET schedule.php) → `processAnime` (JST day/time via
  `getJSTParts` from `airingAt`) → render. No localStorage caching (DB is the cache).
- "+" Todoist button only on rows that HAVE `airingAt` (TBA rows have none); `onclick` →
  `addToTodoist(id, this)` POST → button ✓ + toast.
- **After ANY JS edit:** extract `<script>` body to a temp file and run `node --check` — a single
  parse error kills the whole page.

---

## 8. Local dev + verification

- Static preview works with `python -m http.server 8080`, but the API calls hit the LIVE server
  (config.js token + Origin must match) — the local static copy needs `config.js` present locally
  (it is) and will 403 only if Origin/token rules are violated.
- API tests: `curl` with headers `-H "Origin: https://tybantarnusa.com" -H "X-Api-Token: $TOKEN"`.
  Read `$TOKEN` from `.env` in the shell (`TOKEN=$(sed -n 's/^API_TOKEN=//p' .env)`), never echo it.
  Expected sanity counts: `anime` = 78, `platforms` entries = 151 (59 shows with ≥1); frontend
  rows = 78, `.todo-btn` = 71.
- Headless DOM checks: Edge CDP pattern in the `windows-headless-browser-testing` skill.
  Do NOT over-verify — the user protests token waste ("boros-boros token").
- Before any commit: `git check-ignore jadwal-anime/.env jadwal-anime/config.js` must list both,
  and scan staged files for real `.env` values (DB_PASS / TODOIST_TOKEN / API_TOKEN / DB_USER) —
  they must NOT appear anywhere in tracked files.

---

## 9. Known decisions (do not re-litigate)

- No forced refetch from 3rd parties; data refreshes automatically per season + ~daily staleness.
- Refresh button on the site = re-read DB, not a source fetch.
- Todoist tasks: symbol prefix, "every <day>" no clock time, season project, anti-dup.
- Security = Origin + X-Api-Token; locked to tybantarnusa.com (GitHub Pages copy intentionally
  cannot use the API).
- Emoji symbols are the user's chosen scheme (Bstation 🅱️ / Muse 🅼 / Ani-One 1️⃣ / else 🏴☠️).
- Seasonal data scope: the app always shows the CURRENT season computed from JST. Stored rows for
  older seasons are replaced on the next refresh.
