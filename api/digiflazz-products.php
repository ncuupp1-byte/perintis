<?php
/**
 * /api/digiflazz-products
 *
 * GET ?type=pulsa|data|game|all
 * Ambil price list dari Digiflazz, terapkan markup, return ke frontend.
 * Harga modal TIDAK pernah dikirim ke client.
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_response(['error' => 'Method not allowed'], 405);

$username   = DIGIFLAZZ_USERNAME;
$prodApiKey = DIGIFLAZZ_API_KEY;
if (!$username || !$prodApiKey)
    json_response(['error' => 'Layanan digital belum dikonfigurasi.'], 503);

$sign = md5($username . $prodApiKey . 'pricelist');

$ch = curl_init('https://api.digiflazz.com/v1/price-list');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(['cmd' => 'prepaid', 'username' => $username, 'sign' => $sign]),
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$raw) json_response(['error' => 'Gagal menghubungi Digiflazz.'], 502);

$json = json_decode($raw, true);
if (empty($json['data'])) json_response(['error' => 'Digiflazz tidak mengembalikan data.'], 502);

// ── Whitelist SKU aktif dari akun Digiflazz buyer ─────────────────
$ACTIVE_SKUS = [
    'if2','if3g30d','if5g30d',
    'pln100','pln1000','pln20','pln50',
    'pre33585845','pre33585846','pre33585847','pre33585848','pre33585849','pre33585851','pre33585853',
    'pre33585868','pre33585869','pre33585871','pre33585873','pre33585874','pre33585875',
    'pre33585881','pre33585882','pre33585883','pre33585884','pre33585885','pre33585886',
    'pre33586243','pre33586244','pre33586245','pre33586246','pre33586247','pre33586248',
    'pre33586262','pre33586263','pre33586264','pre33586265','pre33586266','pre33586267',
    'pre33586332','pre33586333','pre33586334','pre33586335','pre33586336','pre33586337',
    'pre33586342','pre33586343','pre33586344','pre33586345','pre33586346','pre33586347',
    'pre33587621','pre33587622','pre33587623','pre33587624','pre33587625','pre33587626',
    'pre33587627','pre33587628','pre33587629','pre33587630','pre33587631','pre33587632',
    'pre33587633','pre33587634','pre33587635','pre33587636','pre33587637','pre33587638',
    'pre33587691','pre33587692','pre33587693','pre33587694','pre33587695','pre33587696',
    'pre33587697','pre33587698','pre33587699','pre33587700','pre33587701','pre33587702',
    'pre33587787','pre33587788','pre33587789',
    'smdu1','smdu2','vflexs','yellow1'
];

$PLN_CATS   = ['PLN', 'Token Listrik', 'Listrik'];
$typeFilter = strtolower($_GET['type'] ?? 'all');

// Filter hanya SKU yang diaktifkan
$allActive = array_filter($json['data'], fn($p) => in_array($p['buyer_sku_code'], $ACTIVE_SKUS, true));

$items = match ($typeFilter) {
    'pulsa' => array_filter($allActive, fn($p) => $p['category'] === 'Pulsa'),
    'data'  => array_filter($allActive, fn($p) => in_array($p['category'], ['Data', 'Paket Data', 'Paket Telepon'], true)),
    'game'  => array_filter($allActive, fn($p) => $p['category'] === 'Games'),
    'pln'   => array_filter($allActive, fn($p) => in_array($p['category'], $PLN_CATS, true)),
    default => $allActive,
};

$products = array_values(array_map(fn($p) => [
    'sku'      => $p['buyer_sku_code'],
    'name'     => $p['product_name'],
    'category' => $p['category'],
    'brand'    => $p['brand'],
    'type'     => $p['type'],
    'price'    => apply_markup((int)$p['price']),
    'desc'     => $p['desc'] ?? '',
    'stock'    => $p['unlimited_stock'] ? 'unlimited' : ($p['stock'] ?? 'unknown'),
    'multi'    => $p['multi'] ?? false,
], $items));

usort($products, fn($a, $b) => strcmp($a['brand'], $b['brand']) ?: $a['price'] - $b['price']);

header('Cache-Control: no-store, no-cache, must-revalidate');
json_response($products);
