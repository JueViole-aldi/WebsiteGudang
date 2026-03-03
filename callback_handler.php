<?php
// callback_handler.php (File Logika di Latar Belakang)

// Keamanan: Memastikan file-file penting dimuat dengan path absolut
// Ini memperbaiki error dimana file tidak ditemukan.
require_once __DIR__ . '/db_connect_public.php';
require_once __DIR__ . '/helpers.php';

// Memulai session jika belum ada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// Validasi input: pastikan invoice_id ada dan merupakan angka
if (!isset($_GET['invoice_id']) || !filter_var($_GET['invoice_id'], FILTER_VALIDATE_INT)) {
    header("Location: donasi.php?error=invalid_callback");
    exit;
}
$id_donasi = (int)$_GET['invoice_id'];

// Update status donasi menjadi 'PAID'
$stmt = $mysqli->prepare("UPDATE donasi SET status_pembayaran = 'PAID' WHERE id = ? AND status_pembayaran = 'PENDING'");
$stmt->bind_param("i", $id_donasi);
$stmt->execute();

// Cek apakah ada baris yang terpengaruh oleh query update
if ($stmt->affected_rows > 0) {
    // Jika update berhasil (transaksi baru pertama kali dibayar)
    
    // Ambil data donasi untuk notifikasi
    $donasi_data_query = $mysqli->query("SELECT jumlah_donasi FROM donasi WHERE id = " . $id_donasi);
    $jumlah_donasi_rp = "Rp " . number_format($donasi_data_query->fetch_assoc()['jumlah_donasi'] ?? 0, 0, ',', '.');

    // Kirim notifikasi ke semua staff
    $staff_ids = get_user_ids_by_role($mysqli, 'staff');
    foreach($staff_ids as $staff_id) {
        $pesan = "Donasi baru sebesar {$jumlah_donasi_rp} telah masuk dan perlu diproses.";
        create_notification($mysqli, $staff_id, $pesan, "dashboard.php?page=proses_apresiasi", $id_donasi);
    }
    
    // Arahkan ke halaman sukses
    header("Location: donasi.php?sukses=1&id=" . $id_donasi);
    exit;
} else {
    // Jika gagal update (kemungkinan karena halaman di-refresh atau transaksi sudah PAID)
    // Tetap arahkan ke halaman sukses agar pengguna melihat invoicenya.
    header("Location: donasi.php?sukses=1&id=" . $id_donasi . "&info=already_processed");
    exit;
}

