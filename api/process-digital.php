<?php
/**
 * /api/process-digital
 *
 * POST { orderId, cancel? }
 * Admin konfirmasi pembayaran → kirim pulsa/data ke Digiflazz.
 * Header: X-Admin-Token
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);

require_admin();

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = (int)($body['orderId'] ?? 0);
if (!$orderId) json_response(['error' => 'orderId wajib diisi.'], 400);

$db = get_db();

// ── Cancel ────────────────────────────────────────────────────
if (!empty($body['cancel'])) {
    $db->prepare("UPDATE digital_orders SET status='cancelled', updated_at=NOW() WHERE id=? AND status='pending_payment'")
       ->execute([$orderId]);
    json_response(['success' => true, 'message' => 'Order dibatalkan.']);
}

// ── Ambil order ───────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM digital_orders WHERE id=? LIMIT 1');
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) json_response(['error' => 'Order tidak ditemukan.'], 404);
if ($order['status'] !== 'pending_payment')
    json_response(['error' => 'Order sudah berstatus: ' . $order['status']], 400);

$username   = DIGIFLAZZ_USERNAME;
$prodApiKey = DIGIFLAZZ_API_KEY;
if (!$username || !$prodApiKey) json_response(['error' => 'Digiflazz belum dikonfigurasi.'], 503);

$refId = $order['ref_id'];
$sign  = md5($username . $prodApiKey . $refId);

// ── Kirim ke Digiflazz langsung (IP hosting sudah statis) ─────
$ch = curl_init('https://api.digiflazz.com/v1/transaction');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'username'       => $username,
        'buyer_sku_code' => $order['sku'],
        'customer_no'    => $order['target_number'],
        'ref_id'         => $refId,
        'sign'           => $sign,
    ]),
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw      = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if (!$raw) json_response(['error' => 'Gagal menghubungi Digiflazz: ' . $curlErr], 502);

$digiData       = json_decode($raw, true) ?? [];
$tx             = $digiData['data'] ?? [];
$digiStatus     = $tx['status'] ?? 'Gagal';
$internalStatus = match ($digiStatus) {
    'Sukses'  => 'success',
    'Pending' => 'pending',
    default   => 'failed',
};

// ── Update DB ─────────────────────────────────────────────────
$db->prepare('
    UPDATE digital_orders SET
      status=?, digiflazz_status=?, digiflazz_message=?,
      digiflazz_sn=?, raw_response=?, updated_at=NOW()
    WHERE id=?
')->execute([
    $internalStatus, $digiStatus,
    $tx['message'] ?? '', $tx['sn'] ?? '',
    json_encode($digiData), $orderId,
]);

// ── Notif WA ke admin ─────────────────────────────────────────
$hargaFmt    = 'Rp ' . number_format((int)$order['price'], 0, ',', '.');
$statusEmoji = ['success' => '✅', 'pending' => '⏳', 'failed' => '❌'][$internalStatus] ?? '❓';
$statusLabel = ['success' => 'SUKSES', 'pending' => 'PENDING', 'failed' => 'GAGAL'][$internalStatus] ?? $digiStatus;
notify_admin(
    "$statusEmoji *PROSES DIGITAL #$orderId — $statusLabel*\n━━━━━━━━━━━━━━━━\n" .
    "👤 *Pelanggan:* {$order['customer_name']}\n" .
    "📲 *Nomor Tujuan:* {$order['target_number']}\n" .
    "📦 *SKU:* {$order['sku']}\n" .
    "💰 *Harga:* $hargaFmt\n" .
    "🔖 *Ref:* $refId\n" .
    ($tx['sn']      ? "✅ *SN:* {$tx['sn']}\n"         : '') .
    ($tx['message'] ? "💬 *Pesan:* {$tx['message']}\n"  : '') .
    "━━━━━━━━━━━━━━━━"
);

// ── Notif WA ke pelanggan ─────────────────────────────────────
if ($order['customer_wa']) {
    if ($internalStatus === 'success') {
        send_wa($order['customer_wa'],
            "✅ *Pulsa Berhasil Dikirim!*\nHalo *{$order['customer_name']}*!\n" .
            "Pulsa $hargaFmt sudah masuk ke *{$order['target_number']}*.\n" .
            "SN: " . ($tx['sn'] ?: '-') . "\nRef: $refId\n\nTerima kasih! 🙏"
        );
    } elseif ($internalStatus === 'pending') {
        send_wa($order['customer_wa'],
            "⏳ *Pulsa Sedang Diproses*\nHalo *{$order['customer_name']}*, pulsa sedang diproses provider. Ditunggu ya!\nRef: $refId"
        );
    } else {
        send_wa($order['customer_wa'],
            "❌ *Proses Pulsa Gagal*\nHalo *{$order['customer_name']}*, maaf terjadi kendala. Admin akan menghubungi kamu segera.\nRef: $refId"
        );
    }
}

json_response([
    'success' => $internalStatus !== 'failed',
    'status'  => $internalStatus,
    'message' => $tx['message'] ?? '',
    'sn'      => $tx['sn']      ?? '',
    'refId'   => $refId,
]);
