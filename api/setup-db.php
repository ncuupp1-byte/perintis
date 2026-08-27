<?php
/**
 * /api/setup-db
 *
 * GET ?secret=xxx  → buat semua tabel + seed data produk awal
 * Jalankan SEKALI setelah upload ke hosting.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo '{"error":"Method not allowed"}'; exit; }
if (($_GET['secret'] ?? '') !== SETUP_SECRET) { http_response_code(403); echo '{"error":"Forbidden"}'; exit; }

try {
    $db = get_db();

    // ── Tabel: products ──────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS products (
          id           TEXT         PRIMARY KEY,
          name         TEXT         NOT NULL,
          price        INTEGER      NOT NULL DEFAULT 0,
          description  TEXT         NOT NULL DEFAULT '',
          img          TEXT         NOT NULL DEFAULT '',
          status       VARCHAR(20)  NOT NULL DEFAULT 'ready',
          status_label VARCHAR(50)  NOT NULL DEFAULT 'Ready Stock',
          variants     TEXT         NOT NULL DEFAULT '[]',
          unit         VARCHAR(20)  NOT NULL DEFAULT 'mika',
          stock        INTEGER      NOT NULL DEFAULT -1,
          sort_order   INTEGER      NOT NULL DEFAULT 0,
          created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
          updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )
    ");

    // ── Tabel: orders ────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS orders (
          id             SERIAL      PRIMARY KEY,
          customer_name  TEXT        NOT NULL,
          customer_wa    VARCHAR(20) NOT NULL,
          customer_addr  TEXT        NOT NULL,
          items          TEXT        NOT NULL DEFAULT '[]',
          total          INTEGER     NOT NULL DEFAULT 0,
          payment_method VARCHAR(50) NOT NULL DEFAULT '',
          status         VARCHAR(20) NOT NULL DEFAULT 'pending',
          created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
          updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    // ── Tabel: digital_orders ────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS digital_orders (
          id                SERIAL       PRIMARY KEY,
          ref_id            VARCHAR(100) NOT NULL UNIQUE,
          customer_name     TEXT         NOT NULL,
          customer_wa       VARCHAR(20)  NOT NULL,
          target_number     VARCHAR(50)  NOT NULL,
          sku               VARCHAR(100) NOT NULL,
          game              VARCHAR(100) NOT NULL DEFAULT '',
          item_name         TEXT         NOT NULL DEFAULT '',
          provider          VARCHAR(50)  NOT NULL DEFAULT 'digiflazz',
          price             INTEGER      NOT NULL DEFAULT 0,
          notes             TEXT         NOT NULL DEFAULT '',
          status            VARCHAR(30)  NOT NULL DEFAULT 'pending',
          digiflazz_status  VARCHAR(30)  NOT NULL DEFAULT '',
          digiflazz_message TEXT         NOT NULL DEFAULT '',
          digiflazz_sn      TEXT         NOT NULL DEFAULT '',
          raw_response      TEXT         NOT NULL DEFAULT '{}',
          created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
          updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )
    ");

    // ── Seed produk awal ─────────────────────────────────────────
    $db->exec("
        INSERT INTO products (id, name, price, description, img, status, status_label, variants, unit, stock, sort_order)
        VALUES
          ('wajik', 'Dodol Wajik', 34000,
           'Wajik Manis legit, resep asli turun-temurun — 40 pcs/mika',
           '/images/wajik.jpeg', 'ready', 'Ready Stock',
           '[{\"id\":\"mika\",\"label\":\"1 Mika\",\"price\":34000,\"stockConvert\":1},{\"id\":\"500gr\",\"label\":\"per 500gr\",\"price\":16000,\"unit\":\"gram\",\"unitStep\":500,\"unitMin\":500,\"stockConvert\":0.5}]',
           'mika', -1, 1),
          ('burayot', 'Burayot', 40000,
           'Burayot Manis, Gurih dan wangi — 32 pcs/mika',
           '/images/Burayot.jpeg', 'preorder', 'Pre Order', '[]', 'mika', -1, 2),
          ('rengginang', 'Rengginang', 25000,
           'Gurih, Nikmat, Nyoss — 10 pcs/pack',
           '/images/RENGGINANG.jpg', 'ready', 'Ready Stock', '[]', 'pack', -1, 3)
        ON CONFLICT (id) DO UPDATE SET
          name=EXCLUDED.name, price=EXCLUDED.price,
          description=EXCLUDED.description,
          status_label=EXCLUDED.status_label,
          updated_at=NOW()
    ");

    $rows = $db->query('SELECT id, name, price FROM products ORDER BY sort_order')->fetchAll();
    echo json_encode([
        'success'        => true,
        'tables_created' => ['products', 'orders', 'digital_orders'],
        'products'       => $rows,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
