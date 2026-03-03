<?php
/**
 * ajax/donasi_updates_qupro.php
 * Endpoint polling donasi baru (status_pembayaran 'PENDING' dan 'PAID')
 * Mengembalikan data donasi dengan id > ?after dalam format JSON.
 */

@session_start();

// PASTIKAN path ke db_connect.php ini sesuai dengan struktur folder Anda!
// Jika file db_connect.php ada di luar folder ajax, gunakan __DIR__ . '/../db_connect.php'
require_once __DIR__ . '/../db_connect.php'; 

header('Content-Type: application/json; charset=utf-8');

// Cek apakah koneksi database berhasil
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['error' => 'Koneksi database tidak tersedia. Periksa path db_connect.php']);
  exit;
}

// Otorisasi: Hanya admin dan staff yang diizinkan memantau realtime ini
$role = isset($_SESSION['role']) ? $_SESSION['role'] : null;
if (!in_array($role, ['staff','admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Akses ditolak. Silakan login sebagai staff atau admin.']);
    exit;
}

// Ambil ID terakhir yang dilihat oleh klien (JavaScript)
$after = isset($_GET['after']) ? (int)$_GET['after'] : 0;

// Query mengambil data PENDING (dari QR/Transfer) maupun PAID (dari Cash/divalidasi)
$sql = "SELECT d.id, d.tanggal_donasi, d.jumlah_donasi, d.status_hadiah, d.status_pembayaran, d.metode_pembayaran, d.bukti_transfer,
               don.nama_donatur, don.alamat,
               h.nama_hadiah AS nama_hadiah_diberikan
        FROM donasi d
        JOIN donatur don ON d.id_donatur = don.id
        LEFT JOIN hadiah h ON d.id_hadiah_diberikan = h.id
        WHERE d.status_pembayaran IN ('PENDING', 'PAID') AND d.id > ?
        ORDER BY d.id ASC
        LIMIT 50";

if (!($stmt = $mysqli->prepare($sql))) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyiapkan query database.']);
    exit;
}

$stmt->bind_param('i', $after);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$maxId = $after;

while ($r = $res->fetch_assoc()) {
    // Format data agar siap ditampilkan di HTML
    $r['tanggal_fmt'] = date('d/m/Y H:i', strtotime($r['tanggal_donasi']));
    $r['jumlah_fmt']  = number_format((int)$r['jumlah_donasi'], 0, ',', '.');
    
    // Sanitasi data untuk mencegah XSS saat disisipkan ke HTML via JavaScript
    $r['nama_donatur'] = htmlspecialchars($r['nama_donatur']);
    $r['alamat'] = htmlspecialchars($r['alamat'] ?? '-');
    $r['metode_pembayaran'] = htmlspecialchars($r['metode_pembayaran']);
    $r['nama_hadiah_diberikan'] = htmlspecialchars($r['nama_hadiah_diberikan'] ?? '');
    
    // Sanitasi URL bukti transfer jika donatur mengunggah file
    if (!empty($r['bukti_transfer'])) {
        $r['bukti_transfer'] = htmlspecialchars($r['bukti_transfer']);
    }
    
    $rows[] = $r;
    
    // Catat ID paling besar yang ditemukan untuk dikirim kembali ke JavaScript
    if ((int)$r['id'] > $maxId) {
        $maxId = (int)$r['id'];
    }
}
$stmt->close();

// Kembalikan response berupa JSON
echo json_encode(['rows' => $rows, 'max_id' => $maxId], JSON_UNESCAPED_UNICODE);