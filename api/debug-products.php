<?php
require_once __DIR__ . '/../config.php';

$username   = DIGIFLAZZ_USERNAME;
$prodApiKey = DIGIFLAZZ_API_KEY;
$sign = md5($username . $prodApiKey . 'pricelist');

$ch = curl_init('https://api.digiflazz.com/v1/price-list');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(['cmd' => 'prepaid', 'username' => $username, 'sign' => $sign]),
]);
$raw = curl_exec($ch);
curl_close($ch);

$json = json_decode($raw, true);
$data = $json['data'] ?? [];

// Ambil 3 produk pertama dan tampilkan SEMUA field-nya
$sample = array_slice($data, 0, 3);

header('Content-Type: application/json');
echo json_encode([
    'total' => count($data),
    'sample_fields' => $sample
], JSON_PRETTY_PRINT);
