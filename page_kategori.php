<?php
// page_kategori.php

// Pastikan hanya admin yang bisa mengakses halaman ini
if (!has_access(['admin'])) {
    echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md' role='alert'><p>Anda tidak memiliki hak akses untuk mengakses halaman ini.</p></div>";
    return;
}

$error_message = '';
$success_message = '';

// Handle Tambah Kategori
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_kategori'])) {
    $nama_kategori = trim($_POST['nama_kategori']);
    if (!empty($nama_kategori)) {
        $sql = "INSERT INTO kategori (nama_kategori) VALUES (?)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("s", $nama_kategori);
            if ($stmt->execute()) {
                log_activity($mysqli, $_SESSION['id'], "Menambah kategori baru: {$nama_kategori}");
                $success_message = "Kategori '{$nama_kategori}' berhasil ditambahkan.";
            } else {
                $error_message = "Gagal menambahkan kategori. Mungkin nama sudah ada.";
            }
            $stmt->close();
        }
    } else {
        $error_message = "Nama kategori tidak boleh kosong.";
    }
}

// Handle Hapus Kategori
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_kategori'])) {
    $id_kategori = $_POST['id_kategori'];
    // Ambil nama kategori untuk log sebelum dihapus
    $kategori_query = $mysqli->query("SELECT nama_kategori FROM kategori WHERE id = " . intval($id_kategori));
    $nama_kategori = $kategori_query->fetch_assoc()['nama_kategori'] ?? 'N/A';

    $sql = "DELETE FROM kategori WHERE id = ?";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $id_kategori);
        if ($stmt->execute()) {
            log_activity($mysqli, $_SESSION['id'], "Menghapus kategori: {$nama_kategori}");
            $success_message = "Kategori '{$nama_kategori}' berhasil dihapus.";
        } else {
            $error_message = "Gagal menghapus kategori. Pastikan tidak ada hadiah yang menggunakan kategori ini.";
        }
        $stmt->close();
    }
}

// Handle Edit Kategori
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_kategori'])) {
    $id_kategori = $_POST['id_kategori_edit'];
    $nama_kategori = trim($_POST['nama_kategori_edit']);
    if (!empty($nama_kategori) && !empty($id_kategori)) {
        $sql = "UPDATE kategori SET nama_kategori = ? WHERE id = ?";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("si", $nama_kategori, $id_kategori);
            if ($stmt->execute()) {
                log_activity($mysqli, $_SESSION['id'], "Mengubah kategori menjadi: {$nama_kategori}");
                $success_message = "Kategori berhasil diperbarui.";
            } else {
                $error_message = "Gagal memperbarui kategori.";
            }
            $stmt->close();
        }
    } else {
        $error_message = "Nama kategori tidak boleh kosong.";
    }
}


// Ambil semua data kategori
$result = $mysqli->query("SELECT * FROM kategori ORDER BY nama_kategori ASC");

?>

<div id="kategori-hadiah">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Kategori Hadiah</h1>

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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Form Tambah Kategori -->
        <div class="lg:col-span-1">
            <div class="bg-white p-6 rounded-xl shadow-md">
                <h2 class="text-xl font-semibold mb-4 text-gray-800">Tambah Kategori Baru</h2>
                <form method="POST" action="dashboard.php?page=kategori">
                    <div>
                        <label for="nama_kategori" class="block text-sm font-medium text-gray-700">Nama Kategori</label>
                        <input type="text" name="nama_kategori" id="nama_kategori" required class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-md">
                    </div>
                    <button type="submit" name="add_kategori" class="mt-4 btn w-full bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700">
                        Tambah Kategori
                    </button>
                </form>
            </div>
        </div>

        <!-- Daftar Kategori -->
        <div class="lg:col-span-2">
            <div class="bg-white p-6 rounded-xl shadow-md">
                <h2 class="text-xl font-semibold mb-4 text-gray-800">Daftar Kategori</h2>
                 <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b-2">
                                <th class="py-3 px-2">Nama Kategori</th>
                                <th class="py-3 px-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                             <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="py-3 px-2 font-medium"><?php echo htmlspecialchars($row['nama_kategori']); ?></td>
                                    <td class="py-3 px-2 text-right">
                                        <button onclick="openEditModalKategori(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_kategori'], ENT_QUOTES); ?>')" class="text-blue-500 hover:text-blue-700 mr-2"><i data-lucide="edit" class="w-4 h-4"></i></button>
                                        <form method="POST" action="dashboard.php?page=kategori" onsubmit="return confirm('Apakah Anda yakin ingin menghapus kategori ini?');" class="inline-block">
                                            <input type="hidden" name="id_kategori" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_kategori" class="text-red-500 hover:text-red-700"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="2" class="py-4 text-center">Belum ada data kategori.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit Kategori -->
<div id="edit-kategori-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <h3 class="text-xl font-semibold mb-4">Edit Kategori</h3>
            <form method="POST" action="dashboard.php?page=kategori" class="space-y-4">
                <input type="hidden" name="id_kategori_edit" id="id_kategori_edit">
                <div>
                    <label for="nama_kategori_edit" class="block text-sm font-medium">Nama Kategori</label>
                    <input type="text" name="nama_kategori_edit" id="nama_kategori_edit" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="closeEditModalKategori()" class="px-4 py-2 bg-gray-200 rounded-md">Batal</button>
                    <button type="submit" name="edit_kategori" class="px-4 py-2 bg-teal-600 text-white rounded-md">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const editKategoriModal = document.getElementById('edit-kategori-modal');
    
    function openEditModalKategori(id, nama) {
        document.getElementById('id_kategori_edit').value = id;
        document.getElementById('nama_kategori_edit').value = nama;
        editKategoriModal.classList.remove('hidden');
        editKategoriModal.classList.add('flex');
    }

    function closeEditModalKategori() {
        editKategoriModal.classList.add('hidden');
        editKategoriModal.classList.remove('flex');
    }
</script>

