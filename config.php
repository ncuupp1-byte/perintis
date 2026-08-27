<?php
/**
 * config.php — Konfigurasi terpusat untuk seluruh aplikasi
 * Database: PostgreSQL (Domainesia cPanel)
 *
 * JANGAN commit file ini ke Git setelah diisi nilai aslinya!
 * Alternatif: simpan nilai sensitif di .htaccess via SetEnv
 */

// ─── Database PostgreSQL (Domainesia) ────────────────────────────────────────
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '5432');
define('DB_NAME',    getenv('DB_NAME')    ?: 'digiflaz_enuycommerce');
define('DB_USER',    getenv('DB_USER')    ?: 'digiflaz_tokoenuy');
define('DB_PASS',    getenv('DB_PASS')    ?: 'EnuyToko2024!');

// ─── Admin Auth ───────────────────────────────────────────────────────────────
define('ADMIN_TOKEN',       getenv('ADMIN_TOKEN')       ?: 'EnuyAdmin2024!');
define('SUPER_ADMIN_TOKEN', getenv('SUPER_ADMIN_TOKEN') ?: '');

// ─── Notifikasi WhatsApp (Fonnte) ─────────────────────────────────────────────
define('FONNTE_TOKEN', getenv('FONNTE_TOKEN') ?: 'XYRscHiJSdJ8woxssaaX');
define('ADMIN_WA',     getenv('ADMIN_WA')     ?: '6281313172199');

// ─── Digiflazz ────────────────────────────────────────────────────────────────
define('DIGIFLAZZ_USERNAME',       getenv('DIGIFLAZZ_USERNAME')       ?: 'japuhuope0GD');
define('DIGIFLAZZ_API_KEY',        getenv('DIGIFLAZZ_API_KEY')        ?: '9f4980e9-11fb-5f76-b8fb-698883b38cad');
define('DIGIFLAZZ_WEBHOOK_SECRET', getenv('DIGIFLAZZ_WEBHOOK_SECRET') ?: '');
define('DIGIFLAZZ_TESTING',        getenv('DIGIFLAZZ_TESTING')        ?: 'false');

// ─── Markup Harga Digital ─────────────────────────────────────────────────────
define('MARKUP_PERCENT', (float)(getenv('MARKUP_PERCENT') ?: 5));
define('MARKUP_FLAT',    (int)  (getenv('MARKUP_FLAT')    ?: 0));
define('MARKUP_ROUND',   (int)  (getenv('MARKUP_ROUND')   ?: 100));

// ─── Apigames ─────────────────────────────────────────────────────────────────
define('APIGAMES_MERCHANT_ID', getenv('APIGAMES_MERCHANT_ID') ?: '');
define('APIGAMES_SECRET_KEY',  getenv('APIGAMES_SECRET_KEY')  ?: '');

// ─── Setup Secret ─────────────────────────────────────────────────────────────
define('SETUP_SECRET', getenv('SETUP_SECRET') ?: 'setup2024');

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Buat koneksi PDO PostgreSQL, singleton */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** Kirim JSON response dan exit */
function json_response(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Cek token admin; exit dengan error jika tidak valid */
function require_admin(bool $allowSuper = true): void {
    $token      = trim($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    $adminToken = ADMIN_TOKEN;
    $superToken = SUPER_ADMIN_TOKEN;
    if (!$adminToken) json_response(['error' => 'Server tidak terkonfigurasi.'], 500);
    if ($allowSuper && $superToken && $token === $superToken) return;
    if ($token === $adminToken) return;
    json_response(['error' => 'Token tidak valid.'], 401);
}

/** Kirim notif WhatsApp via Fonnte */
function send_wa(string $target, string $message): void {
    if (!FONNTE_TOKEN || !$target) return;
    $ch = curl_init('https://api.fonnte.com/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . FONNTE_TOKEN, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['target' => $target, 'message' => $message]),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/** Kirim notif WA ke semua nomor admin */
function notify_admin(string $message): void {
    if (!ADMIN_WA) return;
    foreach (array_filter(array_map('trim', explode(',', ADMIN_WA))) as $wa) {
        send_wa($wa, $message);
    }
}

/** Hitung harga jual dari harga modal Digiflazz */
function apply_markup(int $modal): int {
    $afterPct  = $modal * (1 + MARKUP_PERCENT / 100);
    $afterFlat = $afterPct + MARKUP_FLAT;
    $round     = MARKUP_ROUND;
    if ($round <= 1) return (int)ceil($afterFlat);
    return (int)(ceil($afterFlat / $round) * $round);
}
