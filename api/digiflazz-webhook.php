<?php
/**
 * /api/digiflazz-webhook
 *
 * POST — callback dari Digiflazz saat status transaksi berubah.
 * Set Callback URL di dashboard Digiflazz → https://digiflazz.enuyrasa.my.id/api/digiflazz-webhook
 * Signature verifikasi: HMAC-SHA1(secret, body)
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$username   = DIGIFLAZZ_USERNAME;
$prodApiKey = DIGIFLAZZ_API_KEY;
if (!$username || !$prodApiKey) { http_response_code(503); exit; }

$rawBody = file_get_contents('php://input');

// ── Verifikasi signature (opsional, jika Digiflazz mengirimnya) ──
$hubSignature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
if ($hubSignature) {
    $webhookSecret = DIGIFLAZZ_WEBHOOK_SECRET ?: $prodApiKey;
    $provided      = preg_replace('/^sha1=/', '', $hubSignature);
    $computed      = hash_hmac('sha1', $rawBody, $webhookSecret);
    if (!hash_equals($computed, $provided)) {
        http_response_code(401);
        echo json_encode(['error' => 'Signature tidak valid.']);
        exit;
    }
}

$payload = json_decode($rawBody, true) ?? [];
$tx      = $payload['data'] ?? [];
$refId   = $tx['ref_id'] ?? '';

if (!$refId) {
    http_response_code(400);
    echo json_encode(['error' => 'ref_id tidak ditemukan.']);
    exit;
}

$digiflazzStatus = $tx['status'] ?? 'Gagal';
$internalStatus  = match ($digiflazzStatus) {
    'Sukses'  => 'success',
    'Pending' => 'pending',
    default   => 'failed',
};

header('Content-Type: application/json; charset=utf-8');
try {
    $db   = get_db();
    $stmt = $db->prepare('
        UPDATE digital_orders
        SET status=?, digiflazz_status=?, digiflazz_message=?,
            digiflazz_sn=?, raw_response=?, updated_at=NOW()
        WHERE ref_id=?
    ');
    $stmt->execute([
        $internalStatus, $digiflazzStatus,
        $tx['message'] ?? '', $tx['sn'] ?? '',
        json_encode($payload), $refId,
    ]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['received' => true, 'warning' => 'ref_id tidak ditemukan.']);
        exit;
    }

    // Ambil data order untuk notif WA
    $row = $db->prepare('SELECT * FROM digital_orders WHERE ref_id=?');
    $row->execute([$refId]);
    $order = $row->fetch();

    // Notif WA ke admin hanya jika status final
    if ($order && $internalStatus !== 'pending') {
        $emoji = $internalStatus === 'success' ? '✅' : '❌';
        notify_admin(
            "$emoji *UPDATE TOP-UP #{$order['id']}*\n━━━━━━━━━━━━━━━━\n" .
            "👤 {$order['customer_name']}\n📲 {$order['target_number']}\n📦 {$order['sku']}\n" .
            "💰 Rp " . number_format((int)$order['price'], 0, ',', '.') . "\n" .
            "📊 Status: *$digiflazzStatus*\n" .
            ($tx['sn']      ? "✅ SN: {$tx['sn']}\n"       : '') .
            ($tx['message'] ? "💬 {$tx['message']}\n"        : '') .
            "🔖 Ref: $refId"
        );
    }

    echo json_encode(['received' => true, 'orderId' => $order['id'] ?? null, 'status' => $internalStatus]);
} catch (Throwable $e) {
    error_log('[digiflazz-webhook] ' . $e->getMessage());
    // Return 200 agar Digiflazz tidak retry
    echo json_encode(['received' => true, 'error' => $e->getMessage()]);
}
