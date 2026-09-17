<?php
/**
 * schedule.php — backend jadwal anime + platform streaming.
 *
 * Ambil data dari AniList (daftar anime per musim) + db.silveryasha.id (platform
 * streaming resmi), simpen ke MySQL, terus serve sebagai JSON buat frontend.
 *
 * Musim yang dilacak:
 *   - musim SEKARANG (dihitung dari JST), selalu.
 *   - musim DEPAN, cuma kalau udah deket (NEXT_SEASON_LEAD_DAYS hari sebelum
 *     musim depan mulai) — biar bisa ngeplan / nambahin ke Todoist lebih awal.
 * Musim lain yang masih nyangkut di DB dibersihin otomatis.
 *
 * Alur:
 *   - GET : serve dari DB. Ambil data dari sumber (AniList/silveryasha) CUMA kalau
 *           DB belum ada data musim itu, atau data musim itu udah basi (> 24 jam,
 *           biar countdown & status tetep akurat). Tombol refresh di frontend
 *           TIDAK maksa fetch ulang — kita nggak mau nyusahin 3rd party.
 *
 * .env (letakkan di folder yang sama, JANGAN di public_html kalau bisa):
 *   DB_HOST=localhost      <- di server, bukan IP remote
 *   DB_USER=...
 *   DB_PASS=...
 *   DB_DATABASE=<nama_db>
 *   DB_PREFIX=<prefix_app>
 */

// --- pembatasan origin: cuma https://tybantarnusa.com yang boleh akses ---
const ALLOWED_ORIGIN = 'https://tybantarnusa.com';

function origin_allowed(): bool {
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return true; // GET same-origin dari browser nggak kirim Origin
    return rtrim($origin, '/') === ALLOWED_ORIGIN;
}

if (!origin_allowed()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// --- token rahasia (header X-Api-Token, dicocokkan sama API_TOKEN di .env) ---
$env = load_env(__DIR__ . '/.env');

function api_token_ok(array $env): bool {
    $expected = (string) ($env['API_TOKEN'] ?? '');
    if ($expected === '') return true; // API_TOKEN belum diset di .env -> token nggak diwajibkan
    $sent = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
    return hash_equals($expected, $sent);
}

if (!api_token_ok($env)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const REFRESH_AGE_SECONDS = 86400; // anggap basi kalau > 24 jam sejak fetch terakhir
const MAX_PAGES = 6;
// Musim depan mulai ditampilkan kalau tinggal <= 21 hari lagi (3 minggu).
const NEXT_SEASON_LEAD_DAYS = 21;

function respond(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// .env loader (parse INI sederhana)
// ---------------------------------------------------------------------------
function load_env(string $path): array {
    if (!is_file($path)) return [];
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        $v = trim($v, "\"'");
        $env[$k] = $v;
    }
    return $env;
}

// ---------------------------------------------------------------------------
// HTTP helper (curl kalau ada, fallback file_get_contents)
// ---------------------------------------------------------------------------
function http_request(string $url, array $opts = []) {
    $method = $opts['method'] ?? 'GET';
    $headers = $opts['headers'] ?? [];
    $body = $opts['body'] ?? null;
    $ua = 'jadwal-anime/1.0 (+backend)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => $ua,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300) ? $resp : false;
    }

    $headerStr = '';
    foreach ($headers as $h) $headerStr .= $h . "\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerStr,
            'content' => $body,
            'timeout' => 45,
            'ignore_errors' => true,
            'user_agent' => $ua,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return ($resp !== false) ? $resp : false;
}

// ---------------------------------------------------------------------------
// Musim (JST)
// ---------------------------------------------------------------------------
function current_season(): array {
    $tz = new DateTimeZone('Asia/Tokyo');
    $now = new DateTime('now', $tz);
    $month = (int) $now->format('n');
    $year = (int) $now->format('Y');
    if ($month <= 3) $season = 'WINTER';
    elseif ($month <= 6) $season = 'SPRING';
    elseif ($month <= 9) $season = 'SUMMER';
    else $season = 'FALL';
    return [$season, $year];
}

function season_month(string $season): int {
    switch ($season) {
        case 'SPRING': return 4;
        case 'SUMMER': return 7;
        case 'FALL':   return 10;
        default:       return 1; // WINTER
    }
}

function season_after(string $season, int $year): array {
    switch ($season) {
        case 'WINTER': return ['SPRING', $year];
        case 'SPRING': return ['SUMMER', $year];
        case 'SUMMER': return ['FALL',   $year];
        default:       return ['WINTER', $year + 1]; // FALL -> Winter tahun depan
    }
}

function season_start_ts(string $season, int $year): int {
    $tz = new DateTimeZone('Asia/Tokyo');
    $dt = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, season_month($season)), $tz);
    return (int) $dt->getTimestamp();
}

function season_label(string $season, int $year): string {
    return ucfirst(strtolower($season)) . ' ' . $year;
}

// Musim yang diserve: musim sekarang + musim depan kalau udah deket.
function tracked_seasons(): array {
    list($season, $year) = current_season();
    $tracked = [['season' => $season, 'year' => $year, 'current' => true]];

    list($nextSeason, $nextYear) = season_after($season, $year);
    $secondsLeft = season_start_ts($nextSeason, $nextYear) - time();
    if ($secondsLeft <= NEXT_SEASON_LEAD_DAYS * 86400) {
        $tracked[] = ['season' => $nextSeason, 'year' => $nextYear, 'current' => false];
    }
    return $tracked;
}

// ---------------------------------------------------------------------------
// AniList (GraphQL)
// ---------------------------------------------------------------------------
function anilist_fetch(string $season, int $year): array {
    $all = [];
    for ($page = 1; $page <= MAX_PAGES; $page++) {
        $query = "query { Page(page: {$page}, perPage: 50) { media(season: {$season}, seasonYear: {$year}, type: ANIME, isAdult: false, status_in: [RELEASING, NOT_YET_RELEASED], sort: POPULARITY_DESC) { id title { romaji english native } status nextAiringEpisode { airingAt episode } } pageInfo { hasNextPage } } }";
        $resp = http_request('https://graphql.anilist.co', [
            'method' => 'POST',
            'headers' => ['Content-Type: application/json', 'Accept: application/json'],
            'body' => json_encode(['query' => $query]),
        ]);
        if ($resp === false) break;
        $json = json_decode($resp, true);
        $media = $json['data']['Page']['media'] ?? [];
        if (!is_array($media) || empty($media)) break;
        $all = array_merge($all, $media);
        if (!($json['data']['Page']['pageInfo']['hasNextPage'] ?? false)) break;
        usleep(400000);
    }
    return $all;
}

// ---------------------------------------------------------------------------
// db.silveryasha.id (parse HTML halaman season)
// ---------------------------------------------------------------------------
function silveryasha_fetch(string $season, int $year): array {
    $s = strtolower($season);           // SUMMER -> summer
    $s = ucfirst($s);                   // -> Summer
    $url = "https://db.silveryasha.id/anime/season/{$year}/{$s}";
    $html = http_request($url);
    if ($html === false || $html === '') return [];
    return parse_silveryasha($html);
}

function parse_silveryasha(string $html): array {
    if (!class_exists('DOMDocument')) return [];
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    $result = [];
    $cards = $xp->query("//div[contains(@class,'surface-plain')]");
    foreach ($cards as $card) {
        $t = $xp->query(".//a[contains(@class,'text-body-primary')]", $card);
        if ($t->length === 0) continue;
        $title = trim($t->item(0)->textContent);

        $platforms = [];
        // Cuma platform resmi (chip yang punya tanda check / "Proyek resmi"):
        //   - <a class="season-project-chip ...">        -> udah konfirmasi, ada link
        //   - <span class="season-project-chip--tentative ..."> -> belum konfirmasi
        //     (biasanya muncul di musim depan yang belum jalan), status "Tentatif"
        // Chip fansub ("Grup Penggarap") nggak punya check -> di-skip.
        $chips = $xp->query(
            ".//a[contains(@class,'season-project-chip')][.//svg[contains(@class,'season-project-chip__check')]]"
            . " | .//span[contains(@class,'season-project-chip--tentative')][.//svg[contains(@class,'season-project-chip__check')]]",
            $card
        );
        foreach ($chips as $chip) {
            $lbl = $xp->query(".//*[contains(@class,'season-project-chip__label')]", $chip);
            $name = $lbl->length ? trim($lbl->item(0)->textContent) : '';
            if ($name === '') continue;
            $status = trim(str_replace('Status:', '', (string) $chip->getAttribute('title')));
            if ($status === '') $status = 'Tentatif';
            $url = (string) $chip->getAttribute('href');
            $platforms[] = ['platform' => $name, 'status' => $status, 'url' => $url];
        }

        $result[] = ['title' => $title, 'platforms' => $platforms];
    }
    return $result;
}

// ---------------------------------------------------------------------------
// Matching judul AniList <-> silveryasha
// ---------------------------------------------------------------------------
function norm_title(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

function dedupe_platforms(array $plats): array {
    // prioritas kalau platform yang sama muncul lebih dari sekali:
    // "Jalan" (paling oke) > status lain (Tamat/Drop) > "Tentatif" (belum konfirmasi)
    $rank = function (string $status): int {
        if ($status === 'Jalan') return 3;
        if ($status === 'Tentatif') return 1;
        return 2;
    };
    $out = [];
    foreach ($plats as $p) {
        $name = $p['platform'];
        if (!isset($out[$name]) || $rank((string) $p['status']) > $rank((string) $out[$name]['status'])) {
            $out[$name] = $p;
        }
    }
    ksort($out);
    return array_values($out);
}

function match_platforms(array $anilist, array $sil): array {
    // map judul silveryasha (dinormalisasi) -> entry
    $sil_norm_map = [];
    $sil_keys = [];
    foreach ($sil as $s) {
        $n = norm_title($s['title']);
        if ($n === '') continue;
        $sil_norm_map[$n] = $s;
        $sil_keys[] = $n;
    }

    // override manual untuk judul yang beda jauh
    $overrides = [
        'Koukaku Kidoutai: THE GHOST IN THE SHELL' => 'Koukaku Kidoutai (TV)',
    ];

    $map = [];
    foreach ($anilist as $a) {
        $romaji = $a['title']['romaji'] ?? '';
        $english = $a['title']['english'] ?? '';
        $silEntry = find_sil_match($romaji, $english, $sil_norm_map, $sil_keys, $overrides);
        if ($silEntry !== null) {
            $map[(string) $a['id']] = dedupe_platforms($silEntry['platforms']);
        }
    }
    return $map;
}

function find_sil_match(string $romaji, string $english, array $sil_norm_map, array $sil_keys, array $overrides) {
    // override manual
    foreach ($overrides as $anilistTitle => $silTitle) {
        if ($romaji === $anilistTitle || $english === $anilistTitle) {
            foreach ($sil_norm_map as $n => $s) {
                if ($s['title'] === $silTitle) return $s;
            }
        }
    }

    $keys = [];
    foreach ([$romaji, $english] as $raw) {
        $n = norm_title($raw);
        if ($n !== '') $keys[] = $n;
    }

    // exact
    foreach ($keys as $k) {
        if (isset($sil_norm_map[$k])) return $sil_norm_map[$k];
    }
    // substring
    foreach ($keys as $k) {
        foreach ($sil_keys as $sk) {
            if ($sk === '') continue;
            if ((strpos($k, $sk) !== false || strpos($sk, $k) !== false) && min(strlen($k), strlen($sk)) >= 12) {
                return $sil_norm_map[$sk];
            }
        }
    }
    // fuzzy (similar_text)
    $bestKey = null;
    $bestScore = 0.0;
    foreach ($keys as $k) {
        foreach ($sil_keys as $sk) {
            similar_text($k, $sk, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $bestKey = $sk;
            }
        }
    }
    if ($bestScore >= 88.0 && $bestKey !== null) return $sil_norm_map[$bestKey];

    return null;
}

// ===========================================================================
// MAIN
// ===========================================================================
$env = load_env(__DIR__ . '/.env');

$dbHost = $env['DB_HOST'] ?? 'localhost';
$dbUser = $env['DB_USER'] ?? '';
$dbPass = $env['DB_PASS'] ?? '';
$dbName = $env['DB_DATABASE'] ?? '';
$prefix = $env['DB_PREFIX'] ?? '';

if ($dbUser === '' || $dbName === '') {
    respond(['ok' => false, 'error' => 'Konfigurasi DB belum lengkap (.env)']);
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Exception $e) {
    respond(['ok' => false, 'error' => 'Gagal konek DB']);
}

$tblAnime = $prefix . 'anime';
$tblPlatforms = $prefix . 'platforms';
$tblMeta = $prefix . 'meta';

// --- pastikan tabel ada ---
$pdo->exec("CREATE TABLE IF NOT EXISTS `{$tblAnime}` (
    anilist_id INT UNSIGNED NOT NULL,
    title VARCHAR(500) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT '',
    airing_at INT UNSIGNED NULL,
    season VARCHAR(20) NOT NULL,
    season_year INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (anilist_id),
    KEY idx_season (season, season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$tblPlatforms}` (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    anilist_id INT UNSIGNED NOT NULL,
    platform VARCHAR(100) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT '',
    url VARCHAR(1000) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_anime_platform (anilist_id, platform),
    KEY idx_anilist (anilist_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$tblMeta}` (
    k VARCHAR(64) NOT NULL,
    v TEXT NOT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function get_meta(PDO $pdo, string $tbl, string $k): ?string {
    $st = $pdo->prepare("SELECT v FROM `{$tbl}` WHERE k = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return ($v === false) ? null : (string) $v;
}

function set_meta(PDO $pdo, string $tbl, string $k, string $v): void {
    $st = $pdo->prepare("INSERT INTO `{$tbl}` (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    $st->execute([$k, $v]);
}

// --- musim yang dilacak (sekarang + depan kalau udah deket) ---
list($season, $year) = current_season();
$tracked = tracked_seasons();

// --- fetch dari sumber cuma kalau perlu, per musim ---
// Nggak ada param yang bisa maksa fetch manual dari luar — tombol refresh di
// frontend cuma baca DB. Kita juga nggak mau nyusahin AniList/silveryasha.
$refreshedSeasons = [];

foreach ($tracked as $t) {
    $s = $t['season'];
    $y = (int) $t['year'];

    $st = $pdo->prepare("SELECT COUNT(*) FROM `{$tblAnime}` WHERE season = ? AND season_year = ?");
    $st->execute([$s, $y]);
    $existing = (int) $st->fetchColumn();

    $metaKey = 'last_refresh:' . $s . ':' . $y;
    $last = (int) (get_meta($pdo, $tblMeta, $metaKey) ?? 0);
    $needRefresh = ($existing === 0) || ((time() - $last) >= REFRESH_AGE_SECONDS);
    if (!$needRefresh) continue;

    $anilist = anilist_fetch($s, $y);
    // jangan timpa data lama kalau fetch gagal (anilist kosong)
    if (empty($anilist)) continue;

    $sil = silveryasha_fetch($s, $y);
    $platformsMap = match_platforms($anilist, $sil);

    // id yang lama buat musim ini (buat bersihin platform-nya)
    $st = $pdo->prepare("SELECT anilist_id FROM `{$tblAnime}` WHERE season = ? AND season_year = ?");
    $st->execute([$s, $y]);
    $oldIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();
    if ($oldIds) {
        $in = implode(',', $oldIds);
        $pdo->exec("DELETE FROM `{$tblPlatforms}` WHERE anilist_id IN ({$in})");
    }
    $pdo->exec("DELETE FROM `{$tblAnime}` WHERE season = " . $pdo->quote($s) . " AND season_year = {$y}");

    $insA = $pdo->prepare("INSERT INTO `{$tblAnime}` (anilist_id, title, status, airing_at, season, season_year)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE title = VALUES(title), status = VALUES(status), airing_at = VALUES(airing_at)");
    $insP = $pdo->prepare("INSERT INTO `{$tblPlatforms}` (anilist_id, platform, status, url) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), url = VALUES(url)");

    foreach ($anilist as $a) {
        $id = (int) $a['id'];
        $title = $a['title']['romaji'] ?: ($a['title']['english'] ?: ($a['title']['native'] ?? ''));
        $status = $a['status'] ?? '';
        $airingAt = null;
        if (!empty($a['nextAiringEpisode']['airingAt'])) {
            $airingAt = (int) $a['nextAiringEpisode']['airingAt'];
        }
        $insA->execute([$id, $title, $status, $airingAt, $s, $y]);

        foreach ($platformsMap[(string) $id] ?? [] as $p) {
            $insP->execute([$id, $p['platform'], $p['status'], $p['url']]);
        }
    }

    $pdo->commit();
    set_meta($pdo, $tblMeta, $metaKey, (string) time());
    set_meta($pdo, $tblMeta, 'last_refresh', (string) time()); // info global
    $refreshedSeasons[] = season_label($s, $y);
}

// --- buang musim lama yang udah nggak dilacak ---
$conds = [];
foreach ($tracked as $t) {
    $conds[] = "(season = " . $pdo->quote($t['season']) . " AND season_year = " . (int) $t['year'] . ")";
}
$st = $pdo->query("SELECT anilist_id FROM `{$tblAnime}` WHERE NOT (" . implode(' OR ', $conds) . ")");
$staleIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
if ($staleIds) {
    $pdo->exec("DELETE FROM `{$tblPlatforms}` WHERE anilist_id IN (" . implode(',', $staleIds) . ")");
    $pdo->exec("DELETE FROM `{$tblAnime}` WHERE anilist_id IN (" . implode(',', $staleIds) . ")");
}

// --- serve dari DB ---
$seasonsOut = [];
$anime = [];
$animeIds = [];

foreach ($tracked as $t) {
    $s = $t['season'];
    $y = (int) $t['year'];

    $st = $pdo->prepare("SELECT anilist_id, title, status, airing_at FROM `{$tblAnime}` WHERE season = ? AND season_year = ? ORDER BY anilist_id");
    $st->execute([$s, $y]);
    $rows = $st->fetchAll();

    foreach ($rows as $r) {
        $id = (int) $r['anilist_id'];
        $animeIds[$id] = true;
        $anime[] = [
            'id' => $id,
            'title' => $r['title'],
            'status' => $r['status'],
            'airingAt' => $r['airing_at'] !== null ? (int) $r['airing_at'] : null,
            'season' => $s,
            'year' => $y,
        ];
    }

    $seasonsOut[] = [
        'season' => $s,
        'year' => $y,
        'label' => season_label($s, $y),
        'current' => (bool) $t['current'],
        'startsAt' => season_start_ts($s, $y),
        'count' => count($rows),
    ];
}

$currentCount = 0;
foreach ($seasonsOut as $so) {
    if ($so['current']) $currentCount = $so['count'];
}

if (empty($anime) || $currentCount === 0) {
    respond(['ok' => false, 'error' => 'Data belum tersedia, coba refresh lagi nanti.']);
}

$platformRows = $pdo->query("SELECT anilist_id, platform, status, url FROM `{$tblPlatforms}`")->fetchAll();

$platforms = [];
foreach ($platformRows as $r) {
    $id = (int) $r['anilist_id'];
    if (!isset($animeIds[$id])) continue; // jangan serve platform musim lama
    $key = (string) $id;
    if (!isset($platforms[$key])) $platforms[$key] = [];
    $platforms[$key][] = [
        'platform' => $r['platform'],
        'status' => $r['status'],
        'url' => $r['url'],
    ];
}

respond([
    'ok' => true,
    'season' => $season,
    'year' => $year,
    'refreshed' => $refreshedSeasons,
    'seasons' => $seasonsOut,
    'anime' => $anime,
    'platforms' => $platforms,
]);
