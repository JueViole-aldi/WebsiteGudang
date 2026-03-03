<?php
// proses_pembayaran.php
session_start();
require_once 'db_connect_public.php';

// Validasi input: pastikan invoice_id ada dan merupakan angka
if (!isset($_GET['invoice_id']) || !filter_var($_GET['invoice_id'], FILTER_VALIDATE_INT)) {
    header("Location: donasi.php?error=invalid_id");
    exit;
}
$id_donasi = (int)$_GET['invoice_id'];

// Ambil detail donasi dari database (Ambil juga status 'PENDING' karena belum divalidasi admin)
$stmt = $mysqli->prepare("
    SELECT d.jumlah_donasi, d.metode_pembayaran, don.nama_donatur
    FROM donasi d
    JOIN donatur don ON d.id_donatur = don.id
    WHERE d.id = ? AND d.status_pembayaran = 'PENDING'
");
$stmt->bind_param("i", $id_donasi);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: donasi.php?error=not_found");
    exit;
}
$donasi = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mengunggah & Memproses...</title>
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
        .spinner {
            border: 4px solid rgba(13, 148, 136, 0.1);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border-left-color: #0d9488;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in-up { animation: fadeIn 0.6s ease-out forwards; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-8 md:p-10 text-center fade-in-up border border-gray-100 relative overflow-hidden">
        <!-- Efek loading bar di atas modal -->
        <div class="absolute top-0 left-0 h-1 bg-teal-500 animate-[loadingBar_3s_ease-in-out_forwards]" style="width: 0%"></div>
        
        <div class="spinner mx-auto mb-6"></div>
        <h1 class="text-2xl font-bold text-gray-800">Menyimpan Data...</h1>
        <p class="text-gray-500 mt-2 text-sm">Sedang mengunggah foto bukti transfer dan mencatat ke sistem.</p>
        
        <div class="mt-8 p-6 bg-gray-50 rounded-xl border border-gray-100 text-left space-y-3 shadow-inner">
            <div class="flex justify-between items-center border-b border-gray-200 pb-2">
                <span class="text-xs text-gray-500 uppercase tracking-wider font-bold">Donatur</span>
                <p class="font-bold text-gray-800"><?php echo htmlspecialchars($donasi['nama_donatur']); ?></p>
            </div>
             <div class="flex justify-between items-center border-b border-gray-200 pb-2">
                <span class="text-xs text-gray-500 uppercase tracking-wider font-bold">Jumlah</span>
                <p class="font-bold text-lg text-teal-600">Rp <?php echo number_format($donasi['jumlah_donasi'], 0, ',', '.'); ?></p>
            </div>
            <div class="flex justify-between items-center border-b border-gray-200 pb-2">
                <span class="text-xs text-gray-500 uppercase tracking-wider font-bold">Transfer Via</span>
                <p class="font-semibold text-gray-700 text-sm">QRIS / Langsung</p>
            </div>
             <div class="flex justify-between items-center pt-1">
                <span class="text-xs text-gray-500 uppercase tracking-wider font-bold">ID Tiket</span>
                <p class="font-mono text-gray-800 font-bold bg-gray-200 px-2 py-0.5 rounded text-sm">#<?php echo str_pad($id_donasi, 4, '0', STR_PAD_LEFT); ?></p>
            </div>
        </div>

        <div class="mt-6">
            <p class="text-xs font-semibold text-teal-600 flex items-center justify-center">
                <i data-lucide="shield-check" class="w-4 h-4 mr-1"></i> Transaksi Anda Dijamin Aman
            </p>
        </div>
    </div>

    <style>
        @keyframes loadingBar { 0% { width: 0%; } 100% { width: 100%; } }
    </style>

    <script>
        // Simulasi Loading Tepat 3 Detik (Sesuai Permintaan)
        setTimeout(function() {
            // Karena ini manual QRIS, status tidak langsung PAID, melainkan menunggu Admin.
            // Arahkan kembali ke donasi.php dengan pesan sukses dan e-kwitansi "Menunggu Validasi"
            window.location.href = 'donasi.php?sukses=1&id=<?php echo $id_donasi; ?>';
        }, 3000); 
        
        lucide.createIcons();
    </script>
</body>
</html>