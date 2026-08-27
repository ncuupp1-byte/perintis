<?php
/**
 * /api/digiflazz-admin
 *
 * GET ?cmd=balance  → cek saldo Digiflazz (admin)
 * GET ?cmd=test     → test koneksi + sample price list (admin)
 * Header: X-Admin-Token
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_response(['error' => 'Method not allowed'], 405);

require_admin();

$username   = DIGIFLAZZ_USERNAME;
$prodApiKey = DIGIFLAZZ_API_KEY;
if (!$username || !$prodApiKey)
    json_response(['error' => 'Digiflazz belum dikonfigurasi.'], 503);

$cmd = $_GET['cmd'] ?? 'balance';

// Helper: kirim request ke Digiflazz langsung
function digiflazz_post(string $endpoint, array $body): array {
    $ch = curl_init("https://api.digiflazz.com/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw ?: '{}', true) ?? [];
}

// ── CEK SALDO ────────────────────────────────────────────────
if ($cmd === 'balance') {
    $sign = md5($username . $prodApiKey . 'depo');
    $json = digiflazz_post('cek-saldo', ['cmd' => 'deposit', 'username' => $username, 'sign' => $sign]);
    json_response(['balance' => $json['data']['deposit'] ?? null, 'username' => $json['data']['username'] ?? $username, 'raw' => $json]);
}

// ── TEST KONEKSI ─────────────────────────────────────────────
if ($cmd === 'test') {
    $refId    = 'TEST-' . time();
    $signTx   = md5($username . $prodApiKey . $refId);
    $signPl   = md5($username . $prodApiKey . 'pricelist');

    $txData = digiflazz_post('transaction', [
        'username' => $username, 'buyer_sku_code' => 'xld10',
        'customer_no' => '087800001232', 'ref_id' => $refId, 'sign' => $signTx, 'testing' => true,
    ]);
    $plData = digiflazz_post('price-list', ['cmd' => 'prepaid', 'username' => $username, 'sign' => $signPl]);

    json_response([
        'env' => ['username' => $username, 'apiKeySet' => (bool)$prodApiKey, 'testingMode' => DIGIFLAZZ_TESTING === 'true'],
        'transactionTest' => ['response' => $txData],
        'priceListTest'   => [
            'totalItems' => count($plData['data'] ?? []),
            'sample' => array_map(
                fn($p) => ['sku' => $p['buyer_sku_code'], 'name' => $p['product_name'], 'price' => $p['price']],
                array_slice($plData['data'] ?? [], 0, 3)
            ),
        ],
    ]);
}

json_response(['error' => 'cmd harus "balance" atau "test"'], 400);
