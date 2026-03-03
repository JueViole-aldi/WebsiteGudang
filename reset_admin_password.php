<?php
// reset_admin_password.php
// File ini adalah alat bantu sekali pakai untuk memperbaiki password admin.

// 1. Sertakan koneksi database
require_once 'db_connect.php';

echo "<!DOCTYPE html><html lang='id'><head><title>Reset Password</title>";
echo "<style>body { font-family: sans-serif; padding: 20px; line-height: 1.6; } .success { color: green; font-weight: bold; } .error { color: red; font-weight: bold; }</style>";
echo "</head><body>";

echo "<h1>Proses Reset Password Admin</h1>";

// 2. Siapkan password baru dan hash-nya
$plain_password = 'admin';
$hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

echo "<p>Password baru (plain text): <strong>" . $plain_password . "</strong></p>";
echo "<p>Password baru (hashed): " . $hashed_password . "</p><hr>";

// 3. Siapkan query untuk update
$sql = "UPDATE users SET password = ? WHERE username = 'admin'";

if ($stmt = $mysqli->prepare($sql)) {
    // 4. Bind hash baru ke query
    $stmt->bind_param("s", $hashed_password);

    // 5. Eksekusi query
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "<p class='success'>BERHASIL: Password untuk username 'admin' telah direset.</p>";
            echo "<p>Silakan coba login kembali melalui halaman utama.</p>";
        } else {
            echo "<p class='error'>GAGAL: Tidak ada user dengan username 'admin' yang ditemukan untuk diupdate.</p>";
            echo "<p>Pastikan user 'admin' ada di dalam tabel 'users' Anda.</p>";
        }
    } else {
        echo "<p class='error'>ERROR: Gagal mengeksekusi perintah update. Pesan: " . $stmt->error . "</p>";
    }

    $stmt->close();
} else {
    echo "<p class='error'>ERROR: Gagal mempersiapkan perintah SQL. Pesan: " . $mysqli->error . "</p>";
}

$mysqli->close();
echo "</body></html>";
?>
    