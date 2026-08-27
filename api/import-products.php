<?php
/**
 * /api/import-products
 *
 * POST (multipart/form-data, field "file") — upload CSV atau Excel
 * Header: X-Admin-Token
 *
 * PHP tidak punya library xlsx bawaan, jadi kita pakai:
 *   - CSV: parse_csv() native
 *   - Excel .xlsx: extract XML dari ZIP (tidak perlu library tambahan)
 *   - Excel .xls: hanya didukung jika ada ekstensi php-spreadsheet
 *     (di Domainesia shared hosting biasanya tidak tersedia, anjurkan pakai CSV/xlsx)
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);

require_admin();

// ─── Cek file ──────────────────────────────────────────────────
if (empty($_FILES['file'])) json_response(['error' => 'File tidak ditemukan dalam request.'], 400);
$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) json_response(['error' => 'Upload error: ' . $file['error']], 400);

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true))
    json_response(['error' => 'Format tidak didukung. Gunakan CSV (.csv) atau Excel (.xlsx).'], 400);

// ─── Parse file ────────────────────────────────────────────────
$rows = $ext === 'csv'
    ? parse_csv_file($file['tmp_name'])
    : parse_xlsx_file($file['tmp_name']);

if (empty($rows)) json_response(['error' => 'File kosong atau hanya berisi header.'], 400);

// ─── Validasi & normalisasi baris ─────────────────────────────
$validRows = [];
$skipped   = [];

foreach ($rows as $row) {
    $rowNum = $row['__row'];
    $name   = $row['name'] ?? $row['nama'] ?? '';
    if (!$name) { $skipped[] = ['row' => $rowNum, 'reason' => "Kolom 'name' kosong"]; continue; }

    $rawPrice = $row['price'] ?? $row['harga'] ?? '';
    $price    = (int)preg_replace('/[^0-9]/', '', (string)$rawPrice);
    if (!$rawPrice || $price < 0) {
        $skipped[] = ['row' => $rowNum, 'reason' => "Kolom 'price' tidak valid: \"$rawPrice\""]; continue;
    }

    $rawId = $row['id'] ?? '';
    $id    = $rawId ? slugify($rawId) : slugify($name);
    if (!$id) { $skipped[] = ['row' => $rowNum, 'reason' => 'Tidak bisa membuat id']; continue; }

    $desc        = $row['desc'] ?? $row['description'] ?? $row['deskripsi'] ?? '';
    $img         = $row['img'] ?? $row['image'] ?? $row['gambar'] ?? '';
    $rawStatus   = strtolower($row['status'] ?? 'ready');
    $status      = in_array($rawStatus, ['ready', 'preorder'], true) ? $rawStatus : 'ready';
    $statusLabel = $row['statuslabel'] ?? $row['status_label'] ?? $row['label']
                   ?? ($status === 'preorder' ? 'Pre Order' : 'Ready Stock');

    $validRows[] = compact('id', 'name', 'price', 'desc', 'img', 'status', 'statusLabel');
}

if (empty($validRows)) json_response(['error' => 'Tidak ada baris valid.', 'skipped' => $skipped], 400);

// ─── UPSERT ke database ────────────────────────────────────────
try {
    $db  = get_db();
    $cnt = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();

    $stmt = $db->prepare(
        'INSERT INTO products (id, name, price, description, img, status, status_label, sort_order, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           name=VALUES(name), price=VALUES(price), description=VALUES(description),
           img=VALUES(img), status=VALUES(status), status_label=VALUES(status_label), updated_at=NOW()'
    );

    foreach ($validRows as $p) {
        $stmt->execute([$p['id'], $p['name'], $p['price'], $p['desc'], $p['img'], $p['status'], $p['statusLabel'], ++$cnt]);
    }

    json_response(['updated' => count($validRows), 'skipped' => $skipped]);
} catch (Throwable $e) {
    json_response(['error' => 'Gagal menyimpan: ' . $e->getMessage()], 500);
}

// ─── Helpers ──────────────────────────────────────────────────

function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[\x{0300}-\x{036f}]/u', '', normalizer_normalize($text, Normalizer::FORM_D) ?: $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim(substr($text, 0, 60), '-');
}

function parse_csv_file(string $path): array {
    $handle = fopen($path, 'r');
    if (!$handle) return [];
    $headers = null;
    $rows    = [];
    $rowNum  = 1;
    while (($line = fgetcsv($handle, 0, ',')) !== false) {
        if ($headers === null) { $headers = array_map(fn($h) => trim(strtolower($h)), $line); $rowNum++; continue; }
        if (array_filter($line, fn($v) => $v !== '' && $v !== null) === []) { $rowNum++; continue; }
        $obj = ['__row' => $rowNum++];
        foreach ($headers as $i => $h) $obj[$h] = isset($line[$i]) ? trim($line[$i]) : '';
        $rows[] = $obj;
    }
    fclose($handle);
    return $rows;
}

function parse_xlsx_file(string $path): array {
    // Baca xlsx sebagai ZIP dan ambil sheet1 XML
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->si as $si) {
            $t = '';
            foreach ($si->r as $r) $t .= (string)($r->t ?? '');
            if ((string)($si->t ?? '') !== '') $t = (string)$si->t;
            $sharedStrings[] = $t;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return [];

    $sheet   = simplexml_load_string($sheetXml);
    $allRows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowArr = [];
        foreach ($row->c as $cell) {
            $t = (string)($cell['t'] ?? '');
            $v = (string)($cell->v ?? '');
            if ($t === 's') $v = $sharedStrings[(int)$v] ?? '';
            $rowArr[] = $v;
        }
        $allRows[] = $rowArr;
    }
    if (count($allRows) < 2) return [];

    $headers = array_map(fn($h) => strtolower(trim($h)), $allRows[0]);
    $rows    = [];
    for ($i = 1; $i < count($allRows); $i++) {
        $line = $allRows[$i];
        if (array_filter($line, fn($v) => $v !== '') === []) continue;
        $obj = ['__row' => $i + 1];
        foreach ($headers as $j => $h) $obj[$h] = isset($line[$j]) ? trim($line[$j]) : '';
        $rows[] = $obj;
    }
    return $rows;
}
