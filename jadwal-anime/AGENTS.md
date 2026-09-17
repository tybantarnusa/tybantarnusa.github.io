# AGENTS.md — jadwal-anime (jadwal anime musim berjalan + platform streaming + tombol Todoist)

A single-page dark "TV broadcast board" app listing the CURRENT season's anime schedule
(JST airing day/time) plus the NEXT season as a second tab once it is ≤ 3 weeks away, the official
streaming platforms per show (from db.silveryasha.id), a live NEXT UP countdown, and a per-row "+"
button that adds the anime as a recurring task in the user's personal Todoist (season project).

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

`schedule.php` (GET) serves from MySQL. **Two seasons can be tracked at once:**
- the CURRENT season (from JST) — always;
- the NEXT season — only when it is within `NEXT_SEASON_LEAD_DAYS = 21` days of its start
  (so the user can plan / add next-season shows to Todoist before they air). Outside that
  window the API returns a single season and the frontend shows no NEXT tab.

Per tracked season it fetches 3rd-party sources ONLY when:
1. DB has no rows for THAT season (first run / season changed / new season entered the window), OR
2. `meta['last_refresh:<SEASON>:<YEAR>']` is older than `REFRESH_AGE_SECONDS = 86400`
   (24h — keeps `airingAt` countdowns/status fresh).

Refresh is per season and replaces only that season's rows (delete its platform rows, delete its
anime rows, insert fresh). Seasons that are no longer tracked are purged automatically.

**There is NO manual/forced source refetch.** The frontend "Refresh" button just re-reads the
backend (DB). Do not add a force-refresh param/token — the user explicitly said he does NOT want
to burden 3rd parties ("aku gak pengen kita nyusahin 3rd party").

To force one refresh manually during dev: `DELETE FROM jadwalanime_meta WHERE k LIKE 'last_refresh%';`
then hit the API once.

Sources:
- **AniList** — POST `https://graphql.anilist.co`; REQUIRES a `User-Agent` header (403 without).
  Query: `Page { media(season, seasonYear, type:ANIME, isAdult:false,
  status_in:[RELEASING,NOT_YET_RELEASED], sort:POPULARITY_DESC) { id title{romaji english native}
  status nextAiringEpisode{airingAt episode} } }`, ~2 pages (73 Summer 2026 / 91 Fall 2026).
  For NOT_YET_RELEASED shows `nextAiringEpisode.airingAt` = the PREMIERE timestamp (ep 1) when
  known, NULL when TBA — that is what makes the next-season tab show premiere day/time.
- **db.silveryasha.id** — GET `https://db.silveryasha.id/anime/season/{year}/{Season}` (e.g.
  `/2026/Summer`), parse HTML with DOMDocument/DOMXPath: cards = `div.surface-plain`; title =
  `a.text-body-primary`; **official platforms only** = chips containing an
  `svg.season-project-chip__check` (fansub/"Grup Penggarap" chips have no checkmark — skip them).
  Two chip shapes both carry that check:
  - `a.season-project-chip` — confirmed, has `href`, status from `title` attr (`Jalan`/`Tamat`/`Drop`);
  - `span.season-project-chip--tentative` — NOT confirmed yet (typical for a season that has not
    started), no href, status "Tentatif". The frontend renders these as dashed non-clickable badges.
  Without the tentative case, an upcoming season shows ZERO platforms (real incident: Fall 2026 =
  69 tentative chips, 0 anchors → all platforms silently dropped).
- Title matching AniList↔silveryasha: normalized exact → substring (min len 12) → `similar_text`
  ≥ 88% → manual override `Koukaku Kidoutai: THE GHOST IN THE SHELL` ⇒ `Koukaku Kidoutai (TV)`.
  Summer 2026: 73 anime, 71 cards matched, 53 with ≥1 platform (144 entries, 4 tentative);
  Fall 2026: 91 anime, 73 matched, 29 with platforms (59 entries, all tentative).
  Sanity numbers drift every season — check `count` per season in the response, don't trust these.

API response shape (schedule.php):
```json
{ "ok": true, "season": "SUMMER", "year": 2026, "refreshed": ["Fall 2026"],
  "seasons": [ { "season": "SUMMER", "year": 2026, "label": "Summer 2026", "current": true,
                 "startsAt": 1782831600, "count": 73 },
               { "season": "FALL", "year": 2026, "label": "Fall 2026", "current": false,
                 "startsAt": 1790780400, "count": 91 } ],
  "anime": [ { "id": 178789, "title": "...", "status": "RELEASING", "airingAt": 1788706800,
               "season": "SUMMER", "year": 2026 } ],
  "platforms": { "178789": [ { "platform": "Bstation", "status": "Jalan", "url": "..." } ] } }
```
`airingAt` is a unix timestamp; JST day/time is derived client-side from it. `season`/`year` at the
top level still = the CURRENT season (back-compat). Platform entries cover every tracked season.

---

## 3. MySQL (shared DB — prefix everything)

- Single shared database `tybantar_db` on the cPanel host. All tables prefixed `jadwalanime_`.
- Tables (auto-created by `schedule.php`):
  - `jadwalanime_anime` — `anilist_id` PK, `title`, `status`, `airing_at` (unix, NULL = TBA),
    `season` (e.g. `SUMMER`), `season_year`; KEY idx_season(season, season_year). Holds rows for
    every TRACKED season (current + next while in the 21-day window); untracked seasons get purged.
  - `jadwalanime_platforms` — `anilist_id`, `platform`, `status`, `url`; UNIQUE(anilist_id, platform).
    No season column (ids are unique per anime); rows are deleted/re-inserted per season refresh.
    `status` may be `Jalan` / `Tamat` / `Drop` / `Tentatif` (Tentatif = platform announced by
    silveryasha but not confirmed yet, no URL).
  - `jadwalanime_meta` — `k`/`v`; `last_refresh:<SEASON>:<YEAR>` per season (`last_refresh` is kept
    in sync as a global "last fetch at" info value only).
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
  Sticky topbar (ON AIR badge, **season switch**, Refresh) → NEXT UP countdown bar → season note
  (next season only) → "Today on air" chips → day-nav pills (Mon..Sun + TBA) → day cards with rows
  [time | title+platform badges | status chip + "+" Todoist button] → footer.
- **Season switch** (topbar): one button per season from `data.seasons` (`#seasonSwitch .season-tab`);
  the current season tab is active by default, a next-season tab carries a small `NEXT` tag and its
  tooltip shows the start date. Clicking a tab re-renders from the ALREADY-FETCHED payload
  (`STATE.seasons` + `STATE.key` in memory, `renderSeason()`); no extra request. On mobile (≤620px)
  the brand name and the tab year are hidden so the topbar fits at 375px (verified: no overflow).
- Viewing the NEXT season: `#seasonNote` shows "Starts Oct 1, 2026 (JST) · day & time below = first
  episode slot · N titles announced · M still TBA"; the NEXT UP bar becomes `SEASON PREMIERE`
  (earliest premiere + date, countdown can be in days: `in 2d 12h 09m`); "Today on air" strip is
  hidden and no day pill is marked active (grouping = premiere weekday, times = first-episode slot).
- Platform badges: confirmed ones are `<a class="plat-badge">` (clickable). `Tentatif`/no-URL ones
  render as `<span class="plat-badge tentative">` (dashed, non-clickable, tooltip "not confirmed yet").
- UI language: ENGLISH (user preference). Day names Monday..Sunday.
- `<script src="config.js">` loads BEFORE the main inline `<script>`; every fetch sends
  `X-Api-Token` from `API_TOKEN`. `API_URL` / `TODOIST_API_URL` consts near the top of the script.
- `loadSchedule()` → `fetchSchedule()` (GET schedule.php) → seeds `STATE` (season list + payload,
  with a single-season fallback if `data.seasons` is missing) → `renderSeason()` → `processAnime`
  (JST day/time via `getJSTParts` from `airingAt`) → `render()`. No localStorage caching (DB is the
  cache). Footer: `<N> titles · <Season> <Year>`.
- "+" Todoist button only on rows that HAVE `airingAt` (TBA rows have none); `onclick` →
  `addToTodoist(id, this)` POST → button ✓ + toast. Next-season rows work too — `todoist.php` picks
  the Todoist project from the anime's own `season` column (a Fall show goes to the `Fall` project).
- **After ANY JS edit:** extract `<script>` body to a temp file and run `node --check` — a single
  parse error kills the whole page.

---

## 8. Local dev + verification

- Static preview works with `python -m http.server 8080`, but the API calls hit the LIVE server
  (config.js token + Origin must match) — the local static copy needs `config.js` present locally
  (it is) and will 403 only if Origin/token rules are violated.
- API tests: `curl` with headers `-H "Origin: https://tybantarnusa.com" -H "X-Api-Token: $TOKEN"`.
  Read `$TOKEN` from `.env` in the shell (`TOKEN=$(sed -n 's/^API_TOKEN=//p' .env)`), never echo it.
  Sanity check per season (numbers drift every season — read `seasons[].count`, don't trust these):
  Summer 2026 = 73 anime / 144 platform entries / 53 shows with ≥1; Fall 2026 = 91 / 59 / 29 (all
  tentative) → frontend rows 73 (current tab) or 91 (NEXT tab), `.todo-btn` 68 / 64.
- **Portable PHP for local linting/running** (no system PHP on this PC): `E:\Tools\php-8.4\php\php.exe`
  (NTS 8.4.25 + `php.ini` with curl/mbstring/openssl/pdo_mysql + `cacert.pem` for HTTPS).
  - Lint: `E:/Tools/php-8.4/php/php.exe -l backend/schedule.php` — do this BEFORE deploying, a PHP
    parse error takes the whole API down and the live check is the only other signal.
  - Real local run against the live DB: copy `schedule.php` + `.env` into a scratch dir and run
    `HTTP_X_API_TOKEN="$(sed -n 's/^API_TOKEN=//p' .env)" E:/Tools/php-8.4/php/php.exe schedule.php`
    (CLI needs the token in the env, otherwise it 403s like any untokened request). This refreshes
    the shared DB for real — it's the fastest way to validate fetch/parse logic end-to-end.
    Delete the scratch `.env` copy afterwards.
- Headless DOM checks: Edge CDP pattern in the `windows-headless-browser-testing` skill.
  Do NOT over-verify — the user protests token waste ("boros-boros token").
- Before any commit: `git check-ignore jadwal-anime/.env jadwal-anime/config.js` must list both,
  and scan staged files for real `.env` values (DB_PASS / TODOIST_TOKEN / API_TOKEN / DB_USER) —
  they must NOT appear anywhere in tracked files.

---

## 9. Known decisions (do not re-litigate)

- Next season is shown as a separate tab once it is ≤ 21 days from starting (`NEXT_SEASON_LEAD_DAYS`);
  its day grouping = premiere weekday, only the CURRENT season has "Today on air"/today pill.
- Tentative platforms (silveryasha "Tentatif") ARE shown for upcoming seasons, but as dashed,
  non-clickable badges — unconfirmed info must not look like a confirmed link.

- No forced refetch from 3rd parties; data refreshes automatically per season + ~daily staleness.
- Refresh button on the site = re-read DB, not a source fetch.
- Todoist tasks: symbol prefix, "every <day>" no clock time, season project, anti-dup.
- Security = Origin + X-Api-Token; locked to tybantarnusa.com (GitHub Pages copy intentionally
  cannot use the API).
- Emoji symbols are the user's chosen scheme (Bstation 🅱️ / Muse 🅼 / Ani-One 1️⃣ / else 🏴☠️).
- Seasonal data scope: the app always shows the CURRENT season computed from JST. Stored rows for
  older seasons are replaced on the next refresh.
