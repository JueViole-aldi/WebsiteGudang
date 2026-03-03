<?php
// page_transaksi_masuk.php

// Keamanan: Pastikan file ini tidak diakses secara langsung
defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$is_accessible = has_access(['admin', 'staff']);
$error_message = '';
$success_message = '';

// Handle form submission untuk menambah stok
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_hadiah_masuk'])) {
    if ($is_accessible) {
        $id_hadiah = (int)$_POST['id_hadiah'];
        $jumlah = (int)$_POST['jumlah'];
        $keterangan = trim($_POST['keterangan']);
        $id_user = $_SESSION['id'];

        if (empty($id_hadiah) || $jumlah <= 0) {
            $error_message = "Harap pilih hadiah dan masukkan jumlah yang valid.";
        } else {
            $mysqli->begin_transaction();
            try {
                // 1. Tambah stok di tabel hadiah
                $stmt_update = $mysqli->prepare("UPDATE hadiah SET stok = stok + ? WHERE id = ?");
                $stmt_update->bind_param("ii", $jumlah, $id_hadiah);
                $stmt_update->execute();
                $stmt_update->close();

                // 2. Catat transaksi masuk
                $stmt_insert = $mysqli->prepare("INSERT INTO transaksi_hadiah (id_hadiah, id_user, tipe_transaksi, jumlah, keterangan) VALUES (?, ?, 'masuk', ?, ?)");
                $stmt_insert->bind_param("iiis", $id_hadiah, $id_user, $jumlah, $keterangan);
                $stmt_insert->execute();
                $stmt_insert->close();

                // Ambil nama hadiah untuk log
                $hadiah_query = $mysqli->query("SELECT nama_hadiah FROM hadiah WHERE id = $id_hadiah");
                $nama_hadiah = $hadiah_query->fetch_assoc()['nama_hadiah'] ?? 'N/A';
                
                // 3. Catat aktivitas
                log_activity($mysqli, $id_user, "Mencatat masuk {$jumlah} pcs hadiah '{$nama_hadiah}'.");
                
                $mysqli->commit();
                $success_message = "Pencatatan hadiah masuk berhasil. Stok telah diperbarui.";

            } catch (Exception $e) {
                $mysqli->rollback();
                $error_message = "Terjadi kesalahan: " . $e->getMessage();
            }
        }
    }
}


// Ambil daftar hadiah (hanya yang aktif)
$hadiah_list = $mysqli->query("SELECT id, nama_hadiah FROM hadiah WHERE is_active = 1 ORDER BY nama_hadiah ASC");
?>
<div id="hadiah-masuk">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Pencatatan Hadiah Masuk</h1>

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
        <h2 class="text-xl font-semibold mb-6 text-gray-800">Formulir Hadiah Masuk</h2>
        <form method="POST" action="dashboard.php?page=transaksi_masuk" class="space-y-6">
             <div>
                <label for="id_hadiah" class="block text-sm font-medium text-gray-700 mb-1">Pilih Hadiah</label>
                <select name="id_hadiah" id="id_hadiah" required class="block w-full px-3 py-2 bg-white border border-slate-300 rounded-md text-sm shadow-sm focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500">
                    <option value="">-- Pilih Hadiah --</option>
                     <?php while($row = $hadiah_list->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['nama_hadiah']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div>
                <label for="jumlah" class="block text-sm font-medium text-gray-700 mb-1">Jumlah</label>
                <input type="number" name="jumlah" id="jumlah" min="1" required class="block w-full px-3 py-2 bg-white border border-slate-300 rounded-md">
            </div>
            <div>
                <label for="keterangan" class="block text-sm font-medium text-gray-700 mb-1">Keterangan (Opsional)</label>
                <textarea name="keterangan" id="keterangan" rows="3" class="block w-full px-3 py-2 bg-white border border-slate-300 rounded-md" placeholder="Contoh: Pengadaan dari Vendor X"></textarea>
            </div>
            <div class="pt-4">
                 <button type="submit" name="submit_hadiah_masuk" class="btn w-full bg-teal-600 text-white px-4 py-3 rounded-md hover:bg-teal-700 flex items-center justify-center font-semibold">
                    <i data-lucide="package-plus" class="w-5 h-5 mr-2"></i>Catat Hadiah Masuk
                </button>
            </div>
        </form>
    </div>
    <?php else: ?>
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-md" role="alert">
            <p>Anda tidak memiliki hak akses.</p>
        </div>
    <?php endif; ?>
</div>
