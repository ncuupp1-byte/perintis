<?php
/**
 * /api/digiflazz-topup
 *
 * POST { customerName, customerWA, targetNumber, sku, price, notes? }
 * Proses top-up pulsa / paket data via Digiflazz.
 * Signature: MD5(username + production_api_key + ref_id)
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$customerName = trim($body['customerName'] ?? '');
$customerWA   = trim($body['customerWA']   ?? '');
$targetNumber = trim($body['targetNumber'] ?? '');
$sku          = trim($body['sku']          ?? '');
$price        = (int)($body['price']       ?? 0);
$notes        = trim($body['notes']        ?? '');

if (!$customerName || !$customerWA) json_response(['error' => 'Data pelanggan tidak lengkap.'], 400);
if (!$targetNumber || !$sku)        json_response(['error' => 'Nomor tujuan dan produk wajib diisi.'], 400);

$username   = DIGIFLAZZ_USERNAME;
$prodApiKey = DIGIFLAZZ_API_KEY;
if (!$username || !$prodApiKey) json_response(['error' => 'Layanan digital belum dikonfigurasi.'], 503);

$refId    = 'DIG-' . time() . rand(100, 999);
$sign     = md5($username . $prodApiKey . $refId);
$isTesting = DIGIFLAZZ_TESTING === 'true';

$txBody = array_filter([
    'username'       => $username,
    'buyer_sku_code' => $sku,
    'customer_no'    => $targetNumber,
    'ref_id'         => $refId,
    'sign'           => $sign,
    'testing'        => $isTesting ?: null,   // hanya kirim jika true
], fn($v) => $v !== null);

// ── Kirim ke Digiflazz langsung (hosting ini sudah punya IP statis) ──
$ch = curl_init('https://api.digiflazz.com/v1/transaction');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($txBody),
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($raw === false) json_response(['error' => 'Gagal menghubungi Digiflazz: ' . $err], 502);

$digiData = json_decode($raw, true) ?? [];
$tx       = $digiData['data'] ?? [];

$digiflazzStatus = $tx['status'] ?? 'Gagal';
$internalStatus  = match ($digiflazzStatus) {
    'Sukses'  => 'success',
    'Pending' => 'pending',
    default   => 'failed',
};

// ── Simpan ke DB ─────────────────────────────────────────────
$orderId = null;
try {
    $db   = get_db();
    $stmt = $db->prepare('
        INSERT INTO digital_orders
          (ref_id, customer_name, customer_wa, target_number, sku, price,
           notes, status, digiflazz_status, digiflazz_message, digiflazz_sn, raw_response)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ');
    $stmt->execute([
        $refId, $customerName, $customerWA, $targetNumber, $sku, $price,
        $notes, $internalStatus, $digiflazzStatus,
        $tx['message'] ?? '', $tx['sn'] ?? '', json_encode($digiData),
    ]);
    $orderId = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    // DB error tidak membatalkan respons ke frontend
    error_log('[digiflazz-topup] DB error: ' . $e->getMessage());
}

// ── Notif WA admin ─────────────────────────────────────────────
$statusEmoji = ['success' => '✅', 'pending' => '⏳', 'failed' => '❌'][$internalStatus] ?? '❓';
notify_admin(
    "$statusEmoji *TOP-UP DIGITAL #$orderId*\n━━━━━━━━━━━━━━━━\n" .
    "👤 *Pelanggan:* $customerName\n📱 *WA:* $customerWA\n" .
    "📲 *Nomor Tujuan:* $targetNumber\n📦 *SKU:* $sku\n" .
    "💰 *Harga:* Rp " . number_format($price, 0, ',', '.') . "\n" .
    "📊 *Status:* $digiflazzStatus\n🔖 *Ref ID:* $refId\n" .
    ($tx['sn']      ? "✅ *SN:* {$tx['sn']}\n"       : '') .
    ($tx['message'] ? "💬 *Pesan:* {$tx['message']}\n" : '') .
    "━━━━━━━━━━━━━━━━\nLihat admin: https://digiflazz.enuyrasa.my.id/admin"
);

json_response([
    'success' => $internalStatus !== 'failed',
    'orderId' => $orderId,
    'refId'   => $refId,
    'status'  => $internalStatus,
    'message' => $tx['message'] ?? '',
    'sn'      => $tx['sn']      ?? '',
    'rc'      => $tx['rc']      ?? '',
]);
