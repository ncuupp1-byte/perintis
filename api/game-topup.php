<?php
/**
 * /api/game-topup
 *
 * POST { gameId, sku, targetNumber, zoneNumber?, customerName, customerWA, price, notes? }
 * Top-up game via apigames.id
 * Env: APIGAMES_MERCHANT_ID, APIGAMES_SECRET_KEY
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);

$merchantId = APIGAMES_MERCHANT_ID;
$secretKey  = APIGAMES_SECRET_KEY;
if (!$merchantId || !$secretKey)
    json_response(['error' => 'Layanan top-up game belum dikonfigurasi.', 'code' => 'NO_APIGAMES_CONFIG'], 503);

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$gameId       = trim($body['gameId']       ?? '');
$sku          = trim($body['sku']          ?? '');
$targetNumber = trim($body['targetNumber'] ?? '');
$zoneNumber   = trim($body['zoneNumber']   ?? '');
$customerName = trim($body['customerName'] ?? '');
$customerWA   = trim($body['customerWA']   ?? '');
$price        = (int)($body['price']       ?? 0);
$notes        = trim($body['notes']        ?? '');

if (!$sku || !$targetNumber || !$customerName || !$customerWA)
    json_response(['error' => 'Data tidak lengkap.'], 400);

$refId  = 'AG' . time() . rand(100, 999);
$sign   = md5($merchantId . $secretKey);
$tujuan = $zoneNumber ? "$targetNumber|$zoneNumber" : $targetNumber;

$txBody = ['ref_id' => $refId, 'merchant_id' => $merchantId, 'produk' => $sku,
           'tujuan' => $tujuan, 'server_id' => $zoneNumber ?: '', 'signature' => $sign];

$ch = curl_init('https://v1.apigames.id/v2/transaksi');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($txBody),
]);
$raw    = curl_exec($ch);
$agData = json_decode($raw ?: '{}', true) ?? [];
curl_close($ch);

// ── Simpan ke DB ──────────────────────────────────────────────
$status = match ((int)($agData['rc'] ?? 0)) { 200 => 'success', 201 => 'pending', default => 'failed' };
try {
    $db = get_db();
    $db->prepare('
        INSERT INTO digital_orders
          (ref_id, sku, game, item_name, target_number, customer_name, customer_wa,
           price, status, provider, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'apigames\', ?)
    ')->execute([$refId, $sku, $gameId ?: 'unknown', $notes ?: $sku,
                 $tujuan, $customerName, $customerWA, $price, $status, $notes]);
} catch (Throwable $e) { error_log('[game-topup] DB: ' . $e->getMessage()); }

// ── Handle response ──────────────────────────────────────────
if ($agData['rc'] === 200) {
    notify_admin("✅ *Game Topup BERHASIL*\nRef: $refId\nGame: $gameId · $sku\nTarget: $tujuan\nCustomer: $customerName ($customerWA)\nHarga: Rp " . number_format($price, 0, ',', '.'));
    send_wa($customerWA,
        "✅ *Top-up Berhasil!*\nHalo $customerName, top-up kamu sudah berhasil!\n" .
        "Item: " . ($notes ?: $sku) . "\nID: $targetNumber" . ($zoneNumber ? " (Zone: $zoneNumber)" : '') .
        "\nRef: $refId\n\nTerima kasih! 🎮"
    );
    json_response(['status' => 'success', 'refId' => $refId, 'message' => 'Top-up berhasil!', 'sn' => $agData['data']['sn'] ?? null]);
}

if ($agData['rc'] === 201) {
    notify_admin("⏳ *Game Topup PENDING*\nRef: $refId\nGame: $gameId · $sku\nTarget: $tujuan\nCustomer: $customerName ($customerWA)");
    json_response(['status' => 'pending', 'refId' => $refId, 'message' => 'Pesanan sedang diproses.']);
}

$errMsg = $agData['error_msg'] ?? $agData['message'] ?? 'Transaksi gagal.';
notify_admin("❌ *Game Topup GAGAL*\nRef: $refId\nGame: $gameId · $sku\nTarget: $tujuan\nCustomer: $customerName\nError: $errMsg (rc={$agData['rc']})");
json_response(['status' => 'failed', 'message' => $errMsg, 'refId' => $refId, 'rc' => $agData['rc']], 400);
