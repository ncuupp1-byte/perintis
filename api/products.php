<?php
/**
 * /api/products
 *
 * GET  → list semua produk (publik)
 * POST {action:"update", id, ...} → update produk (admin)
 * POST {action:"delete", id}      → hapus produk (admin)
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list produk (publik) ──────────────────────────────────
if ($method === 'GET') {
    try {
        $db   = get_db();
        $stmt = $db->query(
            'SELECT id, name, price, description AS "desc", img, status,
                    status_label AS "statusLabel", unit, stock, variants
             FROM products ORDER BY sort_order ASC, created_at ASC'
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$p) {
            $p['price'] = (int)$p['price'];
            $p['stock'] = (int)$p['stock'];
            $p['variants'] = json_decode($p['variants'] ?: '[]', true) ?? [];
        }
        unset($p);
        json_response($rows);
    } catch (Throwable $e) {
        json_response(['error' => 'Gagal memuat produk: ' . $e->getMessage()], 500);
    }
}

// ── POST: aksi admin ───────────────────────────────────────────
if ($method === 'POST') {
    require_admin();
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $id     = trim($body['id'] ?? '');
    if (!$id) json_response(['error' => 'ID produk wajib diisi.'], 400);

    try {
        $db = get_db();

        // ── delete ──────────────────────────────────────────────
        if ($action === 'delete') {
            $stmt = $db->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$id]);
            json_response(['success' => true]);
        }

        // ── update ──────────────────────────────────────────────
        if ($action === 'update') {
            $name        = trim($body['name']        ?? '');
            $price       = (int)($body['price']      ?? 0);
            $desc        = trim($body['desc']        ?? '');
            $img         = trim($body['img']         ?? '');
            $status      = trim($body['status']      ?? 'ready');
            $statusLabel = trim($body['statusLabel'] ?? 'Ready Stock');
            $variants    = isset($body['variants']) && is_array($body['variants'])
                               ? json_encode($body['variants']) : '[]';
            $stock       = isset($body['stock']) && is_numeric($body['stock'])
                               ? (int)$body['stock'] : -1;

            if (!$name)  json_response(['error' => 'Nama produk wajib diisi.'], 400);
            if ($price < 0) json_response(['error' => 'Harga tidak valid.'], 400);

            $stmt = $db->prepare(
                'UPDATE products SET name=?, price=?, description=?, img=?,
                 status=?, status_label=?, variants=?, stock=?, updated_at=NOW()
                 WHERE id=?'
            );
            $stmt->execute([$name, $price, $desc, $img, $status, $statusLabel, $variants, $stock, $id]);
            json_response(['success' => true]);
        }

        json_response(['error' => "Action \"$action\" tidak dikenal."], 400);
    } catch (Throwable $e) {
        json_response(['error' => 'Gagal: ' . $e->getMessage()], 500);
    }
}

json_response(['error' => 'Method not allowed'], 405);
