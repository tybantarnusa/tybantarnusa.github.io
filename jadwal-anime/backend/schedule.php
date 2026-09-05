<?php
/**
 * schedule.php — backend jadwal anime + platform streaming.
 *
 * Ambil data dari AniList (daftar anime musim ini) + db.silveryasha.id (platform
 * streaming resmi), simpen ke MySQL, terus serve sebagai JSON buat frontend.
 *
 * Alur:
 *   - GET : serve dari DB. Ambil data dari sumber (AniList/silveryasha) CUMA kalau
 *           DB belum ada data musim ini, atau data udah basi (> 24 jam, biar
 *           countdown & status tetep akurat). Tombol refresh di frontend TIDAK
 *           maksa fetch ulang — kita nggak mau nyusahin 3rd party.
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
// Season (JST)
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

// ---------------------------------------------------------------------------
// AniList (GraphQL)
// ---------------------------------------------------------------------------
function anilist_fetch(string $season, int $year): array {
    $all = [];
    for ($page = 1; $page <= MAX_PAGES; $page++) {
        $query = "query { Page(page: {$page}, perPage: 50) { media(season: {$season}, seasonYear: {$year}, type: ANIME, isAdult: false, status_in: [RELEASING, NOT_YET_RELEASED], sort: POPULARITY_DESC) { id title { romaji english native } status nextAiringEpisode { airingAt } } pageInfo { hasNextPage } } }";
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
        $chips = $xp->query(".//a[contains(@class,'season-project-chip')]", $card);
        foreach ($chips as $chip) {
            // cuma platform resmi (punya tanda check)
            $check = $xp->query(".//svg[contains(@class,'season-project-chip__check')]", $chip);
            if ($check->length === 0) continue;
            $lbl = $xp->query(".//*[contains(@class,'season-project-chip__label')]", $chip);
            $name = $lbl->length ? trim($lbl->item(0)->textContent) : '';
            $status = trim(str_replace('Status:', '', (string) $chip->getAttribute('title')));
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
    $out = [];
    foreach ($plats as $p) {
        $name = $p['platform'];
        if (!isset($out[$name]) || ($p['status'] === 'Jalan' && $out[$name]['status'] !== 'Jalan')) {
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
    PRIMARY KEY (anilist_id)
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

// --- tentukan season sekarang ---
list($season, $year) = current_season();

// --- apakah perlu ambil data dari sumber? ---
// Fetch dari AniList/silveryasha CUMA kalau: (1) DB belum ada data musim ini,
// atau (2) data udah basi (lewat REFRESH_AGE_SECONDS). Nggak ada param yang bisa
// maksa fetch manual dari luar — tombol refresh di frontend cuma baca DB.
$st = $pdo->prepare("SELECT COUNT(*) FROM `{$tblAnime}` WHERE season = ? AND season_year = ?");
$st->execute([$season, $year]);
$existing = (int) $st->fetchColumn();

$last = (int) (get_meta($pdo, $tblMeta, 'last_refresh') ?? 0);
$needRefresh = ($existing === 0) || ((time() - $last) >= REFRESH_AGE_SECONDS);

$refreshed = false;

if ($needRefresh) {
    $anilist = anilist_fetch($season, $year);

    // jangan timpa data lama kalau fetch gagal (anilist kosong)
    if (!empty($anilist)) {
        $sil = silveryasha_fetch($season, $year);
        $platformsMap = match_platforms($anilist, $sil);

        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM `{$tblPlatforms}`");
        $pdo->exec("DELETE FROM `{$tblAnime}`");

        $insA = $pdo->prepare("INSERT INTO `{$tblAnime}` (anilist_id, title, status, airing_at, season, season_year) VALUES (?, ?, ?, ?, ?, ?)");
        $insP = $pdo->prepare("INSERT INTO `{$tblPlatforms}` (anilist_id, platform, status, url) VALUES (?, ?, ?, ?)");

        foreach ($anilist as $a) {
            $id = (int) $a['id'];
            $title = $a['title']['romaji'] ?: ($a['title']['english'] ?: ($a['title']['native'] ?? ''));
            $status = $a['status'] ?? '';
            $airingAt = null;
            if (!empty($a['nextAiringEpisode']['airingAt'])) {
                $airingAt = (int) $a['nextAiringEpisode']['airingAt'];
            }
            $insA->execute([$id, $title, $status, $airingAt, $season, $year]);

            foreach ($platformsMap[(string) $id] ?? [] as $p) {
                $insP->execute([$id, $p['platform'], $p['status'], $p['url']]);
            }
        }

        $pdo->commit();
        set_meta($pdo, $tblMeta, 'last_refresh', (string) time());
        $refreshed = true;
    }
}

// --- serve dari DB ---
$st = $pdo->prepare("SELECT anilist_id, title, status, airing_at FROM `{$tblAnime}` WHERE season = ? AND season_year = ?");
$st->execute([$season, $year]);
$animeRows = $st->fetchAll();

$platformRows = $pdo->query("SELECT anilist_id, platform, status, url FROM `{$tblPlatforms}`")->fetchAll();

if (empty($animeRows)) {
    respond(['ok' => false, 'error' => 'Data belum tersedia, coba refresh lagi nanti.']);
}

$anime = [];
foreach ($animeRows as $r) {
    $anime[] = [
        'id' => (int) $r['anilist_id'],
        'title' => $r['title'],
        'status' => $r['status'],
        'airingAt' => $r['airing_at'] !== null ? (int) $r['airing_at'] : null,
    ];
}

$platforms = [];
foreach ($platformRows as $r) {
    $id = (string) $r['anilist_id'];
    if (!isset($platforms[$id])) $platforms[$id] = [];
    $platforms[$id][] = [
        'platform' => $r['platform'],
        'status' => $r['status'],
        'url' => $r['url'],
    ];
}

respond([
    'ok' => true,
    'season' => $season,
    'year' => $year,
    'refreshed' => $refreshed,
    'anime' => $anime,
    'platforms' => $platforms,
]);
