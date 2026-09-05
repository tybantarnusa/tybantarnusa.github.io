<?php
/**
 * todoist.php — tambah anime dari jadwal ke Todoist pribadi (project per musim).
 *
 * POST JSON: { "anilist_id": <int> }
 *
 * Flow:
 *   - lookup anime (judul + jadwal tayang) + platform dari DB
 *   - pilih simbol: 🅱️ Bstation > 🅼 Muse Indonesia > 1️⃣ Ani-One > 🏴☠️ lainnya
 *   - content = "[simbol] [judul]"
 *   - due berulang: airing JST jam 00-06 -> hari tayang; selain itu -> besoknya
 *     (contoh: tayang Selasa 01:30 -> "every Tuesday"; tayang Rabu 21:00 -> "every Thursday")
 *   - masuk ke project Todoist sesuai musim (#Summer / #Fall / #Winter / #Spring)
 *   - anti duplikat: kalau content yang sama udah ada, nggak bikin lagi
 *
 * Token Todoist dibaca dari .env (TODOIST_TOKEN), nggak pernah di frontend.
 */

// --- pembatasan origin: cuma https://tybantarnusa.com yang boleh akses ---
// POST wajib bawa Origin tybantarnusa.com (browser selalu kirim Origin buat POST,
// jadi request tanpa Origin / Origin lain = bukan dari situs kita, tolak).
const ALLOWED_ORIGIN = 'https://tybantarnusa.com';

function origin_allowed(): bool {
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return false;
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// .env loader (sama kayak schedule.php)
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
// HTTP helper (curl -> file_get_contents)
// ---------------------------------------------------------------------------
function http_request(string $url, array $opts = []) {
    $method = $opts['method'] ?? 'GET';
    $headers = $opts['headers'] ?? [];
    $body = $opts['body'] ?? null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'jadwal-anime/1.0 (+todoist)',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $resp];
    }

    $headerStr = '';
    foreach ($headers as $h) $headerStr .= $h . "\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerStr,
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
            'user_agent' => 'jadwal-anime/1.0 (+todoist)',
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return ['code' => ($resp === false) ? 0 : 200, 'body' => $resp];
}

// ---------------------------------------------------------------------------
// Baca .env + konek DB
// ---------------------------------------------------------------------------
$env = load_env(__DIR__ . '/.env');
$todoistToken = $env['TODOIST_TOKEN'] ?? '';

if ($todoistToken === '') {
    respond(['ok' => false, 'error' => 'TODOIST_TOKEN belum diset di .env']);
}

try {
    $pdo = new PDO(
        "mysql:host=" . ($env['DB_HOST'] ?? 'localhost') . ";dbname=" . ($env['DB_DATABASE'] ?? '') . ";charset=utf8mb4",
        $env['DB_USER'] ?? '',
        $env['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    respond(['ok' => false, 'error' => 'Gagal konek DB']);
}

$prefix = $env['DB_PREFIX'] ?? '';
$tblAnime = $prefix . 'anime';
$tblPlatforms = $prefix . 'platforms';

// ---------------------------------------------------------------------------
// Baca input
// ---------------------------------------------------------------------------
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$anilistId = (int) ($body['anilist_id'] ?? 0);
if ($anilistId <= 0) {
    respond(['ok' => false, 'error' => 'anilist_id wajib dikirim'], 400);
}

// ---------------------------------------------------------------------------
// Lookup anime + platform
// ---------------------------------------------------------------------------
$st = $pdo->prepare("SELECT anilist_id, title, status, airing_at, season, season_year FROM `{$tblAnime}` WHERE anilist_id = ?");
$st->execute([$anilistId]);
$anime = $st->fetch();

if (!$anime) {
    respond(['ok' => false, 'error' => 'Anime nggak ketemu di jadwal'], 404);
}

$stP = $pdo->prepare("SELECT platform FROM `{$tblPlatforms}` WHERE anilist_id = ?");
$stP->execute([$anilistId]);
$platformNames = array_column($stP->fetchAll(), 'platform');

$airingAt = $anime['airing_at'];
if ($airingAt === null || $airingAt === 0) {
    respond(['ok' => false, 'error' => 'Anime ini belum punya jadwal tayang (TBA)'], 400);
}

// ---------------------------------------------------------------------------
// Simbol + content
// ---------------------------------------------------------------------------
function pick_symbol(array $platforms): string {
    // Pakai \u{...} biar urutan codepoint presisi — kalau ZWJ (U+200D) ilang,
    // bendera bajak laut ke-render jadi dua emoji terpisah (bendera + skull).
    $BSTATION = "\u{1F171}\u{FE0F}";          // 🅱️
    $MUSE = "\u{1F17C}\u{FE0F}";              // 🅼
    $ANIONE = "1\u{FE0F}\u{20E3}";            // 1️⃣
    $PIRATE = "\u{1F3F4}\u{200D}\u{2620}\u{FE0F}"; // 🏴☠️ (flag + ZWJ + skull)

    foreach ($platforms as $p) {
        if (stripos($p, 'Bstation') !== false) return $BSTATION;
    }
    foreach ($platforms as $p) {
        if (stripos($p, 'Muse') !== false) return $MUSE;
    }
    foreach ($platforms as $p) {
        if (stripos($p, 'Ani-One') !== false) return $ANIONE;
    }
    return $PIRATE;
}

function todoist_due_string(int $airingAt): string {
    $dt = new DateTime('@' . $airingAt);
    $dt->setTimezone(new DateTimeZone('Asia/Tokyo'));
    $dayIdx = (int) $dt->format('w'); // 0=Sun .. 6=Sat
    $hour = (int) $dt->format('G');
    // jam 00-06 (dini hari) -> hari tayang itu sendiri; sisanya -> besoknya
    $dueIdx = ($hour < 6) ? $dayIdx : (($dayIdx + 1) % 7);
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return 'every ' . $days[$dueIdx];
}

$symbol = pick_symbol($platformNames);
$content = $symbol . ' ' . $anime['title'];
$dueString = todoist_due_string((int) $airingAt);
$projectName = ucfirst(strtolower($anime['season'])); // SUMMER -> Summer

// ---------------------------------------------------------------------------
// Todoist client
// ---------------------------------------------------------------------------
function todoist_call(string $token, string $method, string $path, array $payload = null): array {
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $opts = ['method' => $method, 'headers' => $headers];
    if ($payload !== null) $opts['body'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $res = http_request('https://api.todoist.com' . $path, $opts);
    $parsed = null;
    if (is_string($res['body']) && $res['body'] !== '') {
        $parsed = json_decode($res['body'], true);
    }
    return ['code' => (int) $res['code'], 'json' => $parsed, 'raw' => (string) $res['body']];
}

// cari project musim
$projRes = todoist_call($todoistToken, 'GET', '/api/v1/projects');
$projectId = null;
$projects = [];
if (is_array($projRes['json'])) {
    $projects = $projRes['json']['results'] ?? [];
    foreach ($projects as $p) {
        if (strcasecmp((string) ($p['name'] ?? ''), $projectName) === 0) {
            $projectId = $p['id'] ?? null;
            break;
        }
    }
}
if ($projectId === null) {
    respond(['ok' => false, 'error' => "Project Todoist '#{$projectName}' nggak ketemu"]);
}

// anti duplikat: cek task aktif dengan content sama
$exists = false;
$cursor = null;
for ($page = 0; $page < 8; $page++) {
    $path = '/api/v1/tasks?limit=50' . ($cursor ? '&cursor=' . urlencode($cursor) : '');
    $taskRes = todoist_call($todoistToken, 'GET', $path);
    $tasks = is_array($taskRes['json']) ? ($taskRes['json']['results'] ?? []) : [];
    foreach ($tasks as $t) {
        if (($t['content'] ?? '') === $content && empty($t['is_deleted'])) {
            $exists = true;
            break 2;
        }
    }
    $cursor = is_array($taskRes['json']) ? ($taskRes['json']['next_cursor'] ?? null) : null;
    if (!$cursor) break;
}

if ($exists) {
    respond(['ok' => true, 'created' => false, 'content' => $content, 'due' => $dueString, 'message' => 'Already in Todoist']);
}

// bikin task
$createRes = todoist_call($todoistToken, 'POST', '/api/v1/tasks', [
    'content' => $content,
    'project_id' => $projectId,
    'due_string' => $dueString,
    'due_lang' => 'en',
]);

if ($createRes['code'] < 200 || $createRes['code'] >= 300 || !is_array($createRes['json'])) {
    respond(['ok' => false, 'error' => 'Todoist API error (' . $createRes['code'] . '): ' . mb_substr($createRes['raw'], 0, 300)]);
}

respond([
    'ok' => true,
    'created' => true,
    'content' => $content,
    'due' => $dueString,
    'project' => $projectName,
    'message' => 'Added to Todoist',
]);
