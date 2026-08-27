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

$PULSA_DATA = ['Pulsa', 'Data', 'Paket Data', 'Paket Telepon'];
$GAME_CATS  = ['Games', 'Game', 'Voucher Game', 'Hiburan', 'E-Money'];
$PLN_CATS   = ['PLN', 'Token Listrik', 'Listrik'];
$ALL_CATS   = [...$PULSA_DATA, ...$GAME_CATS, ...$PLN_CATS];
$typeFilter = strtolower($_GET['type'] ?? 'all');

// Tampilkan semua produk yang diaktifkan seller (tidak filter buyer_product_status)
$items = array_filter($json['data'], fn($p) => $p['seller_product_status'] === true);

$items = match ($typeFilter) {
    'pulsa' => array_filter($items, fn($p) => $p['category'] === 'Pulsa'),
    'data'  => array_filter($items, fn($p) => in_array($p['category'], ['Data', 'Paket Data', 'Paket Telepon'], true)),
    'game'  => array_filter($items, fn($p) => in_array($p['category'], $GAME_CATS, true)),
    'pln'   => array_filter($items, fn($p) => in_array($p['category'], $PLN_CATS, true)),
    default => array_filter($items, fn($p) => in_array($p['category'], $ALL_CATS, true)),
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
