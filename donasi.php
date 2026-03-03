<?php
session_start();
require_once 'db_connect_public.php'; // Menggunakan koneksi publik

$success_message = '';
$error_message = '';

// --- OTOMATIS TAMBAH KOLOM BUKTI TRANSFER KE DATABASE JIKA BELUM ADA ---
$check_col = $mysqli->query("SHOW COLUMNS FROM donasi LIKE 'bukti_transfer'");
if ($check_col && $check_col->num_rows == 0) {
    $mysqli->query("ALTER TABLE donasi ADD COLUMN bukti_transfer VARCHAR(255) NULL AFTER status_pembayaran");
}

// --- BAGIAN 1: Handle form submission untuk membuat donasi PENDING ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_donasi'])) {
    $nama_donatur = trim((string) ($_POST['nama_donatur'] ?? ''));
    $kontak = trim((string) ($_POST['kontak'] ?? ''));
    $alamat = trim((string) ($_POST['alamat'] ?? ''));
    $jumlah_donasi = filter_var($_POST['jumlah_donasi'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $metode_pembayaran = "QR Code / Transfer Bank"; // Ditetapkan otomatis
    
    if (empty($nama_donatur) || empty($kontak) || empty($alamat) || empty($jumlah_donasi)) {
        $error_message = "Semua field teks wajib diisi.";
    } else {
        // Logika Upload Bukti Transfer (SEKARANG OPSIONAL)
        $bukti_path = null;
        if (isset($_FILES['bukti_transfer']) && $_FILES['bukti_transfer']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
            
            $file_ext = strtolower(pathinfo($_FILES['bukti_transfer']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (in_array($file_ext, $allowed_exts)) {
                $new_filename = 'bukti_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
                $dest_path = $upload_dir . $new_filename;
                if (move_uploaded_file($_FILES['bukti_transfer']['tmp_name'], $dest_path)) {
                    $bukti_path = $dest_path;
                } else {
                    $error_message = "Gagal menyimpan file bukti transfer ke server.";
                }
            } else {
                $error_message = "Format file tidak didukung. Harap unggah JPG, PNG, atau PDF.";
            }
        } elseif (isset($_FILES['bukti_transfer']) && $_FILES['bukti_transfer']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Jika ada error selain "tidak ada file yang diunggah"
            $error_message = "Terjadi kesalahan saat mengunggah file. Silakan coba lagi.";
        }
        // Jika UPLOAD_ERR_NO_FILE (tidak ada file), proses tetap lanjut karena opsional.

        // Lanjut simpan data jika tidak ada pesan error
        if (empty($error_message)) {
            $mysqli->begin_transaction();
            try {
                // Cek atau buat donatur baru
                $stmt_cek = $mysqli->prepare("SELECT id FROM donatur WHERE kontak = ?");
                $stmt_cek->bind_param("s", $kontak);
                $stmt_cek->execute();
                $result_cek = $stmt_cek->get_result();
                $id_donatur = null;

                if ($result_cek->num_rows > 0) {
                    $id_donatur = $result_cek->fetch_assoc()['id'];
                    $stmt_update = $mysqli->prepare("UPDATE donatur SET nama_donatur = ?, alamat = ? WHERE id = ?");
                    $stmt_update->bind_param("ssi", $nama_donatur, $alamat, $id_donatur);
                    $stmt_update->execute();
                } else {
                    $stmt_insert_donatur = $mysqli->prepare("INSERT INTO donatur (nama_donatur, kontak, alamat, level_donasi) VALUES (?, ?, ?, 'Standard')");
                    $stmt_insert_donatur->bind_param("sss", $nama_donatur, $kontak, $alamat);
                    $stmt_insert_donatur->execute();
                    $id_donatur = $stmt_insert_donatur->insert_id;
                }
                $stmt_cek->close();

                // Simpan donasi dengan path gambar (bisa null jika tidak upload)
                $stmt_insert_donasi = $mysqli->prepare(
                    "INSERT INTO donasi (id_donatur, jumlah_donasi, metode_pembayaran, status_pembayaran, bukti_transfer) VALUES (?, ?, ?, 'PENDING', ?)"
                );
                $stmt_insert_donasi->bind_param("idss", $id_donatur, $jumlah_donasi, $metode_pembayaran, $bukti_path);
                $stmt_insert_donasi->execute();
                $id_donasi_baru = $stmt_insert_donasi->insert_id;

                $mysqli->commit();
                
                // Arahkan ke halaman proses loading
                header("Location: proses_pembayaran.php?invoice_id=" . $id_donasi_baru);
                exit;

            } catch (Exception $e) {
                $mysqli->rollback();
                $error_message = "Terjadi kesalahan pada sistem. Error: " . $e->getMessage();
            }
        }
    }
}

// --- BAGIAN 2: Handle redirect setelah pembayaran sukses ---
if (isset($_GET['sukses']) && $_GET['sukses'] == 1 && isset($_GET['id'])) {
    $id_donasi_sukses = (int)$_GET['id'];
    $stmt = $mysqli->prepare("
        SELECT d.jumlah_donasi, d.metode_pembayaran, d.tanggal_donasi, d.status_pembayaran, don.nama_donatur, don.alamat, don.kontak
        FROM donasi d
        JOIN donatur don ON d.id_donatur = don.id
        WHERE d.id = ? AND d.status_pembayaran IN ('PENDING', 'PAID')
    ");
    $stmt->bind_param("i", $id_donasi_sukses);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $data_sukses = $result->fetch_assoc();
        $nama = htmlspecialchars($data_sukses['nama_donatur']);
        $jumlah = $data_sukses['jumlah_donasi'];
        $metode = htmlspecialchars($data_sukses['metode_pembayaran']);
        $tanggal = date('d F Y', strtotime($data_sukses['tanggal_donasi']));
        $alamat_donatur = htmlspecialchars($data_sukses['alamat']);
        $kontak_donatur = htmlspecialchars($data_sukses['kontak']);
        $status_pembayaran = $data_sukses['status_pembayaran'];
        $id_donasi_tampil = $id_donasi_sukses;
        $success_message = "Data ditemukan.";
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulir Donasi - Apresiasi QUPRO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { 
            font-family: 'Inter', sans-serif;
            background: linear-gradient(-45deg, #f0fdfa, #dcfce7, #ccfbf1, #cffafe);
            background-size: 400% 400%;
            animation: gradientBG 20s ease infinite;
        }
        @keyframes gradientBG { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .form-container, .success-container { animation: fadeIn 0.8s ease-out forwards; backdrop-filter: blur(10px); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .input-group { position: relative; }
        .input-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .form-input { padding-left: 3rem; border: 1px solid #d1d5db; transition: all 0.2s ease-in-out; }
        .form-input:focus { border-color: #0d9488; box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.2); }
        .main-button { transition: all 0.3s ease-in-out; }
        .main-button:hover { transform: translateY(-4px); box-shadow: 0 10px 20px -5px rgba(13, 148, 136, 0.4); }
        
        @media print {
            body { background: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .container { padding: 0 !important; }
            .success-container { box-shadow: none; border: 1px solid #e5e7eb; width: 100%; max-width: 100%; margin: 0; padding: 20px; border-radius: 0; }
            #action-buttons, .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="container mx-auto p-4 md:p-8 flex items-center justify-center min-h-screen">
        
        <?php if ($success_message): ?>
        <!-- Tampilan Bukti Transaksi -->
        <div class="w-full max-w-2xl">
            <div id="receipt" class="success-container bg-white rounded-2xl shadow-2xl p-8 md:p-12">
                <!-- Header -->
                <div class="flex justify-between items-start pb-6 border-b">
                    <div>
                        <h1 class="text-3xl font-bold text-teal-600">QUPRO</h1>
                        <p class="text-gray-500">Jl. Tole Iskandar No.9, Depok</p>
                    </div>
                    <div class="text-right">
                        <h2 class="text-2xl font-bold text-gray-700 uppercase tracking-wider">E-Kwitansi</h2>
                        <p class="text-gray-500 mt-1">#<?php echo str_pad($id_donasi_tampil, 6, '0', STR_PAD_LEFT); ?></p>
                    </div>
                </div>

                <!-- Info Donatur & Tanggal -->
                <div class="grid md:grid-cols-2 gap-8 mt-6">
                    <div>
                        <p class="text-sm text-gray-500 font-semibold">Ditujukan Kepada:</p>
                        <p class="font-bold text-gray-800 text-lg"><?php echo $nama; ?></p>
                        <p class="text-gray-600"><?php echo $alamat_donatur; ?></p>
                        <p class="text-gray-600"><?php echo $kontak_donatur; ?></p>
                    </div>
                    <div class="text-left md:text-right">
                        <p class="text-sm text-gray-500 font-semibold">Tanggal Transaksi:</p>
                        <p class="font-medium text-gray-800"><?php echo $tanggal; ?></p>
                        <p class="text-sm text-gray-500 font-semibold mt-4">Metode Pembayaran:</p>
                        <p class="font-medium text-gray-800"><?php echo $metode; ?></p>
                    </div>
                </div>

                <!-- Tabel Detail Transaksi -->
                <div class="mt-8">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-slate-100">
                                <th class="text-left font-semibold p-3">Deskripsi</th>
                                <th class="text-right font-semibold p-3">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b">
                                <td class="p-3">Donasi Program Apresiasi QUPRO</td>
                                <td class="text-right p-3">Rp <?php echo number_format($jumlah, 0, ',', '.'); ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="font-bold">
                                <td class="text-right p-3 text-lg">Total</td>
                                <td class="text-right p-3 text-xl text-teal-600">Rp <?php echo number_format($jumlah, 0, ',', '.'); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Status Pembayaran -->
                <div class="mt-8 text-center bg-gray-50 p-4 rounded-xl border border-gray-100">
                    <?php if ($status_pembayaran === 'PAID'): ?>
                        <span class="bg-green-100 text-green-700 text-lg font-bold px-6 py-2 rounded-full border border-green-200"><i data-lucide="check-circle" class="inline-block w-5 h-5 mr-1 -mt-1"></i> LUNAS</span>
                        <p class="text-sm text-gray-500 mt-3">Pembayaran berhasil diverifikasi. Hadiah Anda segera diproses!</p>
                    <?php else: ?>
                        <span class="bg-amber-100 text-amber-700 text-lg font-bold px-6 py-2 rounded-full border border-amber-200"><i data-lucide="clock" class="inline-block w-5 h-5 mr-1 -mt-1"></i> MENUNGGU VALIDASI</span>
                        <p class="text-sm text-gray-600 mt-3">Terima kasih! Data donasi Anda telah kami terima dan <strong>sedang menunggu proses verifikasi oleh admin.</strong></p>
                    <?php endif; ?>
                </div>

                <!-- Footer -->
                <div class="mt-8 pt-6 border-t text-center text-gray-500 text-xs">
                    <p>Terima kasih atas dukungan Anda. Simpan halaman ini sebagai bukti transaksi yang sah.</p>
                </div>
            </div>

            <div id="action-buttons" class="mt-6 flex flex-col md:flex-row gap-4 no-print">
                <a href="donasi.php" class="main-button flex-1 inline-block py-3 px-4 rounded-lg text-lg font-semibold text-white bg-gradient-to-r from-teal-500 to-cyan-600 text-center">
                    Selesai & Kembali
                </a>
                <button onclick="window.print()" class="main-button flex-1 inline-block py-3 px-4 rounded-lg text-lg font-semibold text-teal-700 bg-teal-100 hover:bg-teal-200">
                    <i data-lucide="printer" class="inline-block w-5 h-5 mr-2 -mt-1"></i>Cetak E-Kwitansi
                </button>
            </div>
        </div>

        <?php else: ?>
        <!-- Tampilan Formulir Donasi -->
        <div class="form-container w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden border border-gray-200">
            <div class="p-8 md:p-10">
                <div class="text-center mb-8">
                    <svg class="w-16 h-16 text-teal-600 mx-auto mb-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="currentColor" fill-opacity="0.3"/><path d="M16.5 3c1.74 0 3.41.81 4.5 2.09C22.19 3.81 20.52 3 18.78 3H16.5c-1.74 0-3.41.81-4.5 2.09C10.91 3.81 9.24 3 7.5 3 4.42 3 2 5.42 2 8.5c0 3.78 3.4 6.86-8.55 11.54L12 21.35l1.45-1.32C18.6 15.36 22 12.28 22 8.5c0-1.66-.59-3.19-1.55-4.41" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <h1 class="text-3xl md:text-4xl font-extrabold text-gray-800">Formulir Apresiasi</h1>
                    <p class="text-gray-600 mt-2">Setiap dukungan Anda sangat berarti bagi kami.</p>
                </div>

                <?php if ($error_message): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow-sm" role="alert">
                    <p class="font-medium"><?php echo $error_message; ?></p>
                </div>
                <?php endif; ?>

                <!-- Tambahkan enctype multipart/form-data untuk upload file -->
                <form action="donasi.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <div class="input-group">
                        <i data-lucide="user" class="input-icon"></i>
                        <input type="text" name="nama_donatur" placeholder="Nama Lengkap" required class="form-input w-full pr-4 py-3 rounded-lg">
                    </div>
                    <div class="input-group">
                        <i data-lucide="mail" class="input-icon"></i>
                        <input type="text" name="kontak" placeholder="Email / No. Telepon Aktif" required class="form-input w-full pr-4 py-3 rounded-lg">
                    </div>
                    <div class="input-group">
                        <i data-lucide="home" class="input-icon top-4 -translate-y-0"></i>
                        <textarea name="alamat" rows="3" placeholder="Alamat Pengiriman Hadiah" required class="form-input w-full pr-4 py-3 rounded-lg"></textarea>
                    </div>
                    <div class="input-group">
                        <span class="input-icon font-bold !left-4">Rp</span>
                        <input type="number" name="jumlah_donasi" min="10000" step="1000" placeholder="Jumlah Donasi (min. 10.000)" required class="form-input w-full pr-4 py-3 rounded-lg !pl-12">
                    </div>
                    
                    <!-- AREA QRIS/BANK DAN UPLOAD BUKTI -->
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-6 text-center space-y-4">
                        <h3 class="font-bold text-gray-800 text-lg flex justify-center items-center">
                            <i data-lucide="qr-code" class="mr-2 text-teal-600"></i> Scan Pembayaran
                        </h3>
                        <p class="text-xs text-gray-500 px-2">Silakan transfer via Mobile Banking atau E-Wallet menggunakan kode di bawah ini.</p>
                        
                        <!-- Gambar QR Code Bank Palsu/Dummy -->
                        <div class="bg-white p-4 rounded-xl shadow-sm inline-block border border-gray-100 relative group">
                            <!-- Menggunakan API QR Server untuk membuat QR code palsu bertuliskan rekening dummy -->
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=TRANSFER-BANK-CONTOH-123456789" alt="QR Code Bank" class="mx-auto w-40 h-40">
                            <p class="font-bold text-blue-800 mt-3 tracking-widest text-sm uppercase">BANK CONTOH</p>
                            <p class="text-xs text-gray-500 font-mono mt-1">No. Rek: 123-456-7890</p>
                        </div>

                        <div class="text-left mt-4 border-t border-gray-200 pt-4">
                            <label class="block text-sm font-bold text-gray-700 mb-2">Unggah Bukti Transfer <span class="text-gray-400 font-normal ml-1">(Opsional)</span></label>
                            <!-- Hapus atribut 'required' agar opsional -->
                            <input type="file" name="bukti_transfer" accept="image/*,.pdf" class="w-full text-sm text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100 border border-gray-200 rounded-lg bg-white cursor-pointer transition-colors focus:ring-2 focus:ring-teal-500 outline-none">
                            <p class="text-[11px] text-gray-400 mt-1.5"><i data-lucide="info" class="w-3 h-3 inline-block -mt-0.5"></i> Anda dapat melewati ini jika belum mentransfer. Format: JPG, PNG, PDF.</p>
                        </div>
                    </div>
                    
                    <div class="pt-2">
                        <button type="submit" name="submit_donasi" class="main-button w-full flex items-center justify-center py-4 px-4 border border-transparent rounded-lg shadow-lg text-lg font-semibold text-white bg-gradient-to-r from-teal-500 to-cyan-600">
                            <i data-lucide="check-circle" class="w-5 h-5 mr-3"></i>
                            Selesai & Proses Donasi
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            lucide.createIcons();
        });
    </script>
</body>
</html>