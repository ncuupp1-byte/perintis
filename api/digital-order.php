<?php
/**
 * POST /api/digital-order
 *
 * Buat pesanan digital baru — simpan ke DB dengan status pending_payment.
 * TIDAK langsung kirim ke Digiflazz. Admin yang konfirmasi setelah cek bayar.
 *
 * Body: { customerName, customerWA, targetNumber, sku, price, operator?, type?, notes? }
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$customerName = trim($body['customerName'] ?? '');
$customerWA   = trim($body['customerWA']   ?? '');
$targetNumber = trim($body['targetNumber'] ?? '');
$sku          = trim($body['sku']          ?? '');
$price        = (int)($body['price']       ?? 0);
$notes        = trim($body['notes']        ?? '');

if (!$customerName || !$customerWA) json_response(['error' => 'Data pelanggan tidak lengkap.'], 400);
if (!$targetNumber || !$sku)        json_response(['error' => 'Nomor tujuan dan produk wajib diisi.'], 400);
if ($price <= 0)                    json_response(['error' => 'Harga tidak valid.'], 400);

$refId = 'DIG-' . time() . rand(100, 999);

try {
    $db   = get_db();
    // PostgreSQL: gunakan RETURNING id karena lastInsertId() butuh nama sequence
    $stmt = $db->prepare('
        INSERT INTO digital_orders
          (ref_id, customer_name, customer_wa, target_number, sku, price, notes, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ');
    $stmt->execute([
        $refId, $customerName, $customerWA,
        $targetNumber, $sku, $price, $notes,
        'pending_payment'
    ]);
    $orderId = (int)$stmt->fetchColumn();

    // Notif WA ke admin
    notify_admin(
        "🛒 *ORDER DIGITAL BARU #$orderId*\n━━━━━━━━━━━━━━━━\n" .
        "👤 *Nama:* $customerName\n📱 *WA:* $customerWA\n" .
        "📲 *Nomor Tujuan:* $targetNumber\n📦 *Produk:* $sku\n" .
        "💰 *Harga:* Rp " . number_format($price, 0, ',', '.') . "\n" .
        ($notes ? "📝 *Keterangan:* $notes\n" : '') .
        "━━━━━━━━━━━━━━━━\n" .
        "⚠️ Cek pembayaran lalu konfirmasi di:\nhttps://digiflazz.enuyrasa.my.id/admin"
    );

    json_response([
        'success' => true,
        'orderId' => $orderId,
        'refId'   => $refId,
        'price'   => $price,
        'message' => 'Pesanan berhasil dibuat. Silakan lakukan pembayaran.',
    ]);
} catch (Throwable $e) {
    // Sertakan detail error untuk debugging — hapus setelah production stable
    json_response([
        'error'  => 'Gagal menyimpan pesanan: ' . $e->getMessage(),
        'detail' => $e->getFile() . ':' . $e->getLine(),
        'trace'  => substr($e->getTraceAsString(), 0, 500),
    ], 500);
}
