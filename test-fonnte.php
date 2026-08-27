<?php
/**
 * test-fonnte.php — Test kirim WA via Fonnte
 * HAPUS file ini setelah selesai debug!
 * Akses: https://digiflazz.enuyrasa.my.id/test-fonnte.php?secret=debug2024
 */

if (($_GET['secret'] ?? '') !== 'debug2024') {
    http_response_code(403); die('Forbidden');
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== TEST FONNTE ===\n\n";
echo "FONNTE_TOKEN : " . (FONNTE_TOKEN ? substr(FONNTE_TOKEN, 0, 6) . '***' : '(kosong!)') . "\n";
echo "ADMIN_WA     : " . (ADMIN_WA ?: '(kosong!)') . "\n\n";

if (!FONNTE_TOKEN || !ADMIN_WA) {
    echo "ERROR: Token atau nomor WA kosong di config.php!\n";
    echo "Pastikan config.php sudah diupload versi terbaru.\n";
    exit;
}

// Test kirim WA
echo "Mengirim pesan test ke " . ADMIN_WA . "...\n";

$ch = curl_init('https://api.fonnte.com/send');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Authorization: ' . FONNTE_TOKEN,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS     => json_encode([
        'target'  => ADMIN_WA,
        'message' => '✅ Test notif dari Dapur Tradisional Ibu Enuy — ' . date('H:i:s'),
    ]),
]);
$raw     = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code : $httpCode\n";
echo "cURL Error: " . ($curlErr ?: '(none)') . "\n";
echo "Response  : $raw\n\n";

$resp = json_decode($raw, true);
if (!empty($resp['status']) && $resp['status'] === true) {
    echo "✅ BERHASIL! Cek WA " . ADMIN_WA . "\n";
} else {
    echo "❌ GAGAL.\n";
    echo "Kemungkinan:\n";
    echo "- Token Fonnte salah atau expired\n";
    echo "- Nomor WA belum terhubung ke perangkat di Fonnte\n";
    echo "- Cek dashboard Fonnte: https://app.fonnte.com\n";
}
