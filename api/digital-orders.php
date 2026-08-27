<?php
/**
 * /api/digital-orders
 *
 * GET  ?status=all|success|pending|failed|cancelled  &limit=100
 *      Daftar transaksi top-up digital untuk admin.
 *
 * POST { action:"delete", orderId }
 *      Hapus satu record digital order (admin only).
 *      Hanya boleh hapus status: success, failed, cancelled.
 *      pending_payment tidak boleh dihapus — batalkan dulu.
 *
 * Header: X-Admin-Token
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_admin();

// ── POST: delete ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $action  = $body['action'] ?? '';
    $orderId = (int)($body['orderId'] ?? 0);

    if ($action !== 'delete') json_response(['error' => "Action \"$action\" tidak dikenal."], 400);
    if (!$orderId)            json_response(['error' => 'orderId wajib diisi.'], 400);

    try {
        $db  = get_db();
        $row = $db->prepare('SELECT status FROM digital_orders WHERE id=?');
        $row->execute([$orderId]);
        $o   = $row->fetch();
        if (!$o) json_response(['error' => 'Order tidak ditemukan.'], 404);
        if ($o['status'] === 'pending_payment')
            json_response(['error' => 'Order pending tidak bisa dihapus. Batalkan dulu.'], 400);
        $db->prepare('DELETE FROM digital_orders WHERE id=?')->execute([$orderId]);
        json_response(['success' => true]);
    } catch (Throwable $e) {
        json_response(['error' => 'Gagal menghapus: ' . $e->getMessage()], 500);
    }
}

// ── GET: list ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_response(['error' => 'Method not allowed'], 405);

$status = $_GET['status'] ?? 'all';
$limit  = min((int)($_GET['limit'] ?? 100), 200);

try {
    $db = get_db();
    if ($status === 'all') {
        $stmt = $db->prepare('SELECT * FROM digital_orders ORDER BY created_at DESC LIMIT ?');
        $stmt->execute([$limit]);
    } else {
        $stmt = $db->prepare('SELECT * FROM digital_orders WHERE status=? ORDER BY created_at DESC LIMIT ?');
        $stmt->execute([$status, $limit]);
    }
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['price'] = (int)$r['price']; }
    unset($r);
    json_response($rows);
} catch (Throwable $e) {
    json_response(['error' => 'Gagal memuat riwayat: ' . $e->getMessage()], 500);
}
