<?php
/**
 * export_handler.php
 * Solusi export ke Excel berbasis HTML Table (Lebih rapi, ada border dan warna)
 * Kompatibel dengan PHP 8.1 tanpa library vendor.
 */

session_start();
// Pastikan path ke db_connect.php benar
require_once 'db_connect.php'; 

// Cek akses (Hanya admin/manajer)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'manajer'])) {
    die("Akses ditolak.");
}

$report_type = $_GET['report'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if ($report_type === 'laporan_keluar' || $report_type === 'laporan_masuk') {
    
    $tipe_transaksi = ($report_type === 'laporan_keluar') ? 'keluar' : 'masuk';
    // Ekstensi diubah menjadi .xls
    $filename = "Laporan_Hadiah_" . ucfirst($tipe_transaksi) . "_" . date('Y-m-d') . ".xls";
    
    // Header untuk memaksa download sebagai file Excel
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    // --- MULAI OUTPUT TABEL HTML ---
    // (Excel akan otomatis menerjemahkan tag HTML ini menjadi sel yang rapi)
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    
    // TABEL DATA UTAMA
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; font-family: Arial, sans-serif;">';
    
    echo '<thead>';
    // Judul Utama Tabel (colspan disesuaikan, keluar = 7 kolom, masuk = 5 kolom)
    $colspan_header = ($tipe_transaksi === 'keluar') ? '7' : '5';
    echo '<tr>';
    echo '<th colspan="' . $colspan_header . '" style="background-color: #10B981; color: white; font-size: 16px; height: 40px; text-align: center;">';
    echo 'LAPORAN HADIAH ' . strtoupper($tipe_transaksi) . ' - GUDANG QUPRO';
    echo '</th>';
    echo '</tr>';
    
    // Sub-Judul (Periode Filter)
    if (!empty($start_date) || !empty($end_date)) {
        echo '<tr>';
        echo '<th colspan="' . $colspan_header . '" style="background-color: #D1FAE5; color: #065F46; text-align: center;">';
        echo 'Periode: ' . ($start_date ?: 'Awal') . ' s/d ' . ($end_date ?: 'Akhir');
        echo '</th>';
        echo '</tr>';
    }

    // Nama-Nama Kolom
    echo '<tr style="background-color: #34D399; color: black;">';
    if ($tipe_transaksi === 'keluar') {
        echo '<th>Tanggal</th><th>Hadiah</th><th>Donatur</th><th>Nominal Donasi</th><th>Jumlah Unit</th><th>Dicatat Oleh</th><th>Keterangan</th>';
    } else {
        echo '<th>Tanggal</th><th>Hadiah</th><th>Jumlah Unit</th><th>Dicatat Oleh</th><th>Keterangan</th>';
    }
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    // --- AMBIL DATA DARI DATABASE ---
    // Menambahkan subquery untuk mengambil nominal donasi donatur
    $sql = "SELECT t.tanggal_transaksi, h.nama_hadiah, d.nama_donatur, t.jumlah, u.name as nama_user, t.keterangan,
            (SELECT jumlah_donasi FROM donasi WHERE id_donatur = t.id_donatur ORDER BY tanggal_donasi DESC LIMIT 1) as nominal_donasi
            FROM transaksi_hadiah t
            JOIN hadiah h ON t.id_hadiah = h.id
            JOIN users u ON t.id_user = u.id
            LEFT JOIN donatur d ON t.id_donatur = d.id
            WHERE t.tipe_transaksi = ?";
            
    $params = [$tipe_transaksi];
    $types = 's';

    if (!empty($start_date)) {
        $sql .= " AND t.tanggal_transaksi >= ?";
        $params[] = $start_date . " 00:00:00"; 
        $types .= 's';
    }
    if (!empty($end_date)) {
        $sql .= " AND t.tanggal_transaksi <= ?";
        $params[] = $end_date . " 23:59:59";
        $types .= 's';
    }
    
    $sql .= " ORDER BY t.tanggal_transaksi DESC";
            
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $total_transaksi = 0;
    $total_unit = 0;
    $total_donasi = 0; // Variabel baru untuk menjumlahkan donasi langsung dari tabel

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $total_transaksi++;
            $total_unit += (int)$row['jumlah'];

            echo '<tr>';
            echo '<td style="text-align: center;">' . date('d-m-Y H:i', strtotime($row['tanggal_transaksi'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['nama_hadiah']) . '</td>';
            if ($tipe_transaksi === 'keluar') {
                echo '<td>' . htmlspecialchars($row['nama_donatur'] ?? 'N/A') . '</td>';
                
                // Format nominal donasi jika ada dan tambahkan ke Total
                $nominal_teks = '-';
                if (!empty($row['nominal_donasi'])) {
                    $nominal_teks = 'Rp ' . number_format($row['nominal_donasi'], 0, ',', '.');
                    $total_donasi += (float)$row['nominal_donasi']; // PENJUMLAHAN AKURAT
                }
                echo '<td style="text-align: right; color: #047857; font-weight: bold;">' . $nominal_teks . '</td>';
            }
            echo '<td style="text-align: center;">' . $row['jumlah'] . '</td>';
            echo '<td>' . htmlspecialchars($row['nama_user']) . '</td>';
            echo '<td>' . htmlspecialchars($row['keterangan']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="' . $colspan_header . '" style="text-align: center; color: #6B7280;">Tidak ada data pada periode ini.</td></tr>';
    }
    echo '</tbody>';
    echo '</table>';

    // --- TABEL RINGKASAN DI BAGIAN BAWAH ---
    echo '<br><br>'; 
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; font-family: Arial, sans-serif; width: 400px;">';
    echo '<thead>';
    echo '<tr><th colspan="2" style="background-color: #3B82F6; color: white; font-size: 14px; height: 30px;">RINGKASAN LAPORAN</th></tr>';
    echo '</thead>';
    echo '<tbody>';
    echo '<tr><td style="font-weight: bold;">Total Transaksi</td><td style="text-align: right;">' . number_format($total_transaksi) . ' kali</td></tr>';

    if ($tipe_transaksi === 'keluar') {
        echo '<tr><td style="font-weight: bold;">Total Hadiah Terdistribusi</td><td style="text-align: right;">' . number_format($total_unit) . ' unit</td></tr>';
        
        // Baris khusus donasi dengan warna highlight kuning/oranye
        // Sekarang menggunakan variabel $total_donasi yang dihitung langsung dari baris data Excel
        echo '<tr><td style="font-weight: bold; background-color: #FEF3C7;">Total Donasi Terkumpul</td><td style="text-align: right; font-weight: bold; background-color: #FEF3C7; color: #D97706;">Rp ' . number_format($total_donasi, 0, ',', '.') . '</td></tr>';
        
    } else {
        echo '<tr><td style="font-weight: bold;">Total Stok Masuk</td><td style="text-align: right;">' . number_format($total_unit) . ' unit</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</body></html>';

    fclose($output);
    exit;
} else {
    echo "Jenis laporan tidak valid.";
}