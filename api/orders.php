<?php
/**
 * /api/orders
 *
 * POST (no body / no action) → verify token admin
 * GET  ?status=all|pending|confirmed|cancelled → list pesanan (admin)
 * POST {action:"create-order", ...}            → buat pesanan baru (publik)
 * POST {action:"confirm", orderId}             → konfirmasi pesanan (admin)
 * POST {action:"cancel",  orderId}             → batalkan pesanan (admin)
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'];

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $token  = trim($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');

    // ── verify token (login admin) ─────────────────────────────
    if (!$action) {
        $adminToken = ADMIN_TOKEN;
        $superToken = SUPER_ADMIN_TOKEN;
        if (!$adminToken) json_response(['error' => 'Server tidak terkonfigurasi.'], 500);
        if ($superToken && $token === $superToken)
            json_response(['success' => true, 'role' => 'super_admin']);
        if ($token === $adminToken)
            json_response(['success' => true, 'role' => $superToken ? 'admin' : 'super_admin']);
        json_response(['error' => 'Token tidak valid.'], 401);
    }

    // ── create-order (publik) ──────────────────────────────────
    if ($action === 'create-order') {
        $customerName  = trim($body['customerName']  ?? '');
        $customerWA    = trim($body['customerWA']    ?? '');
        $customerAddr  = trim($body['customerAddr']  ?? '');
        $items         = $body['items']               ?? [];
        $total         = (int)($body['total']         ?? 0);
        $paymentMethod = trim($body['paymentMethod'] ?? '');

        if (!$customerName || !$customerWA || !$customerAddr)
            json_response(['error' => 'Data pelanggan tidak lengkap.'], 400);
        if (!$items || !is_array($items) || count($items) === 0)
            json_response(['error' => 'Pesanan kosong.'], 400);
        if ($total <= 0)
            json_response(['error' => 'Total tidak valid.'], 400);

        try {
            $db   = get_db();
            $stmt = $db->prepare(
                'INSERT INTO orders (customer_name, customer_wa, customer_addr, items, total, payment_method, status)
                 VALUES (?, ?, ?, ?, ?, ?, \'pending\')
                 RETURNING id'
            );
            $stmt->execute([$customerName, $customerWA, $customerAddr, json_encode($items), $total, $paymentMethod]);
            $orderId = (int)$stmt->fetchColumn();

            // Notif WA ke admin
            $itemLines = implode("\n", array_map(
                fn($i) => '• ' . $i['qty'] . '× ' . $i['name'] . ' = Rp ' . number_format($i['subtotal'], 0, ',', '.'),
                $items
            ));
            notify_admin(
                "🛒 *PESANAN BARU #$orderId*\n━━━━━━━━━━━━━━━━\n" .
                "👤 *Nama:* $customerName\n📱 *WA:* $customerWA\n📍 *Alamat:* $customerAddr\n" .
                "💳 *Bayar via:* $paymentMethod\n\n📦 *Item:*\n$itemLines\n\n" .
                "💰 *Total: Rp " . number_format($total, 0, ',', '.') . "*\n━━━━━━━━━━━━━━━━\n" .
                "Buka admin: https://digiflazz.enuyrasa.my.id/admin"
            );

            json_response(['success' => true, 'orderId' => (int)$orderId]);
        } catch (Throwable $e) {
            json_response(['error' => 'Gagal menyimpan pesanan: ' . $e->getMessage()], 500);
        }
    }

    // ── delete (admin only) ───────────────────────────────────
    if ($action === 'delete') {
        require_admin();
        $orderId = (int)($body['orderId'] ?? 0);
        if (!$orderId) json_response(['error' => 'Order ID wajib diisi.'], 400);
        try {
            $db = get_db();
            // Hanya boleh hapus pesanan yang sudah selesai (bukan pending)
            $row = $db->prepare('SELECT status FROM orders WHERE id=?');
            $row->execute([$orderId]);
            $o = $row->fetch();
            if (!$o) json_response(['error' => 'Pesanan tidak ditemukan.'], 404);
            if ($o['status'] === 'pending') json_response(['error' => 'Pesanan pending tidak bisa dihapus. Batalkan dulu.'], 400);
            $db->prepare('DELETE FROM orders WHERE id=?')->execute([$orderId]);
            json_response(['success' => true]);
        } catch (Throwable $e) {
            json_response(['error' => 'Gagal menghapus: ' . $e->getMessage()], 500);
        }
    }

    // ── confirm / cancel (admin only) ─────────────────────────
    if ($action === 'confirm' || $action === 'cancel') {
        require_admin();
        $orderId = (int)($body['orderId'] ?? 0);
        if (!$orderId) json_response(['error' => 'Order ID wajib diisi.'], 400);

        try {
            $db = get_db();

            if ($action === 'cancel') {
                $db->prepare("UPDATE orders SET status='cancelled', updated_at=NOW() WHERE id=?")
                   ->execute([$orderId]);
                json_response(['success' => true]);
            }

            // confirm — kurangi stok jika perlu
            $row = $db->prepare('SELECT * FROM orders WHERE id=?');
            $row->execute([$orderId]);
            $order = $row->fetch();
            if (!$order) json_response(['error' => 'Pesanan tidak ditemukan.'], 404);
            if ($order['status'] !== 'pending') json_response(['error' => 'Pesanan sudah diproses.'], 400);

            $items = json_decode($order['items'] ?? '[]', true) ?? [];
            foreach ($items as $item) {
                if (empty($item['productId'])) continue;
                $p = $db->prepare('SELECT stock FROM products WHERE id=?');
                $p->execute([$item['productId']]);
                $prod = $p->fetch();
                if (!$prod || (int)$prod['stock'] < 0) continue;
                $reduce = $item['qty'] * ($item['stockConvert'] ?? 1);
                $ns     = max(0, (int)$prod['stock'] - $reduce);
                $db->prepare('UPDATE products SET stock=?, status=?, status_label=?, updated_at=NOW() WHERE id=?')
                   ->execute([$ns, $ns === 0 ? 'habis' : 'ready', $ns === 0 ? 'Habis' : 'Ready Stock', $item['productId']]);
            }

            $db->prepare("UPDATE orders SET status='confirmed', updated_at=NOW() WHERE id=?")
               ->execute([$orderId]);
            json_response(['success' => true]);
        } catch (Throwable $e) {
            json_response(['error' => 'Gagal: ' . $e->getMessage()], 500);
        }
    }

    json_response(['error' => "Action \"$action\" tidak dikenal."], 400);
}

// ── GET: list pesanan (admin) ──────────────────────────────────
if ($method === 'GET') {
    require_admin();
    $status = $_GET['status'] ?? 'all';
    try {
        $db = get_db();
        if ($status === 'all') {
            $stmt = $db->query('SELECT * FROM orders ORDER BY created_at DESC');
        } else {
            $stmt = $db->prepare('SELECT * FROM orders WHERE status=? ORDER BY created_at DESC');
            $stmt->execute([$status]);
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$o) {
            $o['id']    = (int)$o['id'];
            $o['total'] = (int)$o['total'];
            $o['items'] = json_decode($o['items'] ?? '[]', true) ?? [];
        }
        unset($o);
        json_response($rows);
    } catch (Throwable $e) {
        json_response(['error' => 'Gagal memuat pesanan: ' . $e->getMessage()], 500);
    }
}

json_response(['error' => 'Method not allowed'], 405);
