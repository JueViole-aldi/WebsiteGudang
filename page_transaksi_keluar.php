<?php
// page_transaksi_keluar.php

// Keamanan: Pastikan file ini tidak diakses secara langsung
defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

// Pastikan user memiliki akses
$is_accessible = has_access(['admin', 'staff']);
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_hadiah_keluar'])) {
    if ($is_accessible) {
        $id_hadiah = (int)$_POST['id_hadiah'];
        $jumlah = (int)$_POST['jumlah'];
        $keterangan = trim($_POST['keterangan']);
        $tujuan_pengeluaran = $_POST['tujuan_pengeluaran'];
        $id_user = $_SESSION['id'];

        $id_donatur = null;
        if ($tujuan_pengeluaran === 'apresiasi_donatur') {
            $id_donatur = (int)$_POST['id_donatur'];
            if (empty($id_donatur)) {
                $error_message = "Untuk apresiasi, donatur harus dipilih.";
            }
        }

        if (empty($error_message) && (empty($id_hadiah) || $jumlah <= 0)) {
            $error_message = "Harap lengkapi semua data yang diperlukan.";
        }

        if (empty($error_message)) {
            // Memulai transaksi database untuk memastikan konsistensi data
            $mysqli->begin_transaction();

            try {
                // 1. Cek stok saat ini (dengan lock untuk mencegah race condition)
                $stmt_check = $mysqli->prepare("SELECT nama_hadiah, stok FROM hadiah WHERE id = ? FOR UPDATE");
                $stmt_check->bind_param("i", $id_hadiah);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                $hadiah = $result_check->fetch_assoc();
                $stmt_check->close();

                if (!$hadiah || $hadiah['stok'] < $jumlah) {
                    throw new Exception("Stok untuk '" . htmlspecialchars($hadiah['nama_hadiah']) . "' tidak mencukupi. Sisa stok: " . ($hadiah['stok'] ?? 0));
                }

                // 2. Kurangi stok di tabel hadiah
                $stmt_update = $mysqli->prepare("UPDATE hadiah SET stok = stok - ? WHERE id = ?");
                $stmt_update->bind_param("ii", $jumlah, $id_hadiah);
                $stmt_update->execute();
                $stmt_update->close();

                // 3. Catat transaksi keluar
                $stmt_insert = $mysqli->prepare("INSERT INTO transaksi_hadiah (id_hadiah, id_user, id_donatur, tipe_transaksi, jumlah, keterangan) VALUES (?, ?, ?, 'keluar', ?, ?)");
                $stmt_insert->bind_param("iiiis", $id_hadiah, $id_user, $id_donatur, $jumlah, $keterangan);
                $stmt_insert->execute();
                $stmt_insert->close();
                
                // 4. Catat aktivitas dengan log yang lebih deskriptif
                $log_message = "Mencatat keluar {$jumlah} pcs '" . htmlspecialchars($hadiah['nama_hadiah']) . "'";
                if ($id_donatur) {
                    $donatur_query = $mysqli->query("SELECT nama_donatur FROM donatur WHERE id=" . $id_donatur);
                    $nama_donatur = $donatur_query->fetch_assoc()['nama_donatur'] ?? 'N/A';
                    $log_message .= " untuk donatur '{$nama_donatur}'.";
                } else {
                    $log_message .= " untuk tujuan: " . ucwords(str_replace('_', ' ', $tujuan_pengeluaran)) . ".";
                }
                log_activity($mysqli, $id_user, $log_message);

                // Jika semua berhasil, commit transaksi
                $mysqli->commit();
                $success_message = "Pencatatan hadiah keluar berhasil.";

            } catch (Exception $e) {
                // Jika ada kesalahan, batalkan semua perubahan
                $mysqli->rollback();
                $error_message = $e->getMessage();
            }
        }
    }
}

// Ambil daftar hadiah yang stoknya > 0
$hadiah_list = $mysqli->query("SELECT id, nama_hadiah, stok FROM hadiah WHERE stok > 0 ORDER BY nama_hadiah ASC");

// Ambil daftar donatur
$donatur_list = $mysqli->query("SELECT id, nama_donatur FROM donatur ORDER BY nama_donatur ASC");
?>

<div id="hadiah-keluar">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Pencatatan Hadiah Keluar</h1>

    <!-- Notifikasi -->
    <?php if ($success_message): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md" role="alert">
        <p><?php echo $success_message; ?></p>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert">
        <p><?php echo $error_message; ?></p>
    </div>
    <?php endif; ?>

    <?php if ($is_accessible): ?>
    <div class="bg-white p-8 rounded-xl shadow-md max-w-2xl mx-auto">
        <h2 class="text-xl font-semibold mb-6 text-gray-800">Formulir Hadiah Keluar</h2>
        <form method="POST" action="dashboard.php?page=transaksi_keluar" class="space-y-6">
            <div>
                <label for="id_hadiah" class="block text-sm font-medium text-gray-700 mb-1">Pilih Hadiah</label>
                <select name="id_hadiah" id="id_hadiah" required class="block w-full px-3 py-2 bg-white border border-slate-300 rounded-md text-sm shadow-sm focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500">
                    <option value="">-- Pilih Hadiah --</option>
                    <?php while($row = $hadiah_list->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['nama_hadiah']) . " (Stok: " . $row['stok'] . ")"; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label for="tujuan_pengeluaran" class="block text-sm font-medium text-gray-700 mb-1">Tujuan Pengeluaran</label>
                <select name="tujuan_pengeluaran" id="tujuan_pengeluaran" required class="block w-full px-3 py-2 bg-white border border-slate-300 rounded-md text-sm shadow-sm focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500">
                    <option value="apresiasi_donatur">Apresiasi Donatur</option>
                    <option value="kebutuhan_internal">Kebutuhan Internal</option>
                    <option value="barang_rusak_hilang">Barang Rusak / Hilang</option>
                </select>
            </div>

            <div id="donatur-field-wrapper">
                <label for="id_donatur" class="block text-sm font-medium text-gray-700 mb-1">Pilih Donatur</label>
                <select name="id_donatur" id="id_donatur" required class="block w-full px-3 py-2 bg-white border border-slate-300 rounded-md text-sm shadow-sm focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500">
                    <option value="">-- Pilih Donatur --</option>
                    <?php mysqli_data_seek($donatur_list, 0); while($row = $donatur_list->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['nama_donatur']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label for="jumlah" class="block text-sm font-medium text-gray-700 mb-1">Jumlah</label>
                <input type="number" name="jumlah" id="jumlah" min="1" required class="block w-full px-3 py-2 bg-white border border-slate-300 rounded-md text-sm shadow-sm placeholder-slate-400 focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500">
            </div>
            
            <div>
                <label for="keterangan" class="block text-sm font-medium text-gray-700 mb-1">Keterangan</label>
                <textarea name="keterangan" id="keterangan" rows="3" required class="block w-full px-3 py-2 bg-white border border-slate-300 rounded-md text-sm shadow-sm placeholder-slate-400 focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500" placeholder="Contoh: Hadiah untuk donasi, atau 'Digunakan untuk acara internal'"></textarea>
            </div>

            <div class="pt-4">
                 <button type="submit" name="submit_hadiah_keluar" class="btn w-full bg-teal-600 text-white px-4 py-3 rounded-md hover:bg-teal-700 flex items-center justify-center font-semibold">
                    <i data-lucide="package-minus" class="w-5 h-5 mr-2"></i>Catat Hadiah Keluar
                </button>
            </div>
        </form>
    </div>
    <?php else: ?>
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-md" role="alert">
            <p>Anda tidak memiliki hak akses untuk mencatat hadiah keluar.</p>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tujuanSelect = document.getElementById('tujuan_pengeluaran');
    const donaturWrapper = document.getElementById('donatur-field-wrapper');
    const donaturSelect = document.getElementById('id_donatur');

    function toggleDonaturField() {
        if (tujuanSelect.value === 'apresiasi_donatur') {
            donaturWrapper.style.display = 'block';
            donaturSelect.required = true;
        } else {
            donaturWrapper.style.display = 'none';
            donaturSelect.required = false;
        }
    }

    // Panggil saat halaman dimuat
    toggleDonaturField();

    // Panggil saat pilihan berubah
    tujuanSelect.addEventListener('change', toggleDonaturField);
});
</script>
