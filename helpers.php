<?php
// helpers.php

// Cek status login
function is_logged_in() {
    return isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true;
}

// Cek hak akses
function has_access($allowed_roles) {
    if (!is_logged_in()) return false;
    
    $user_role = $_SESSION['role'] ?? '';
    return in_array($user_role, $allowed_roles);
}

// Fungsi untuk mencatat aktivitas
function log_activity($mysqli, $user_id, $aktivitas) {
    $sql = "INSERT INTO log_aktivitas (id_user, aktivitas) VALUES (?, ?)";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("is", $user_id, $aktivitas);
        $stmt->execute();
        $stmt->close();
    }
}

// --- FUNGSI BARU UNTUK NOTIFIKASI ---

/**
 * Membuat notifikasi baru untuk satu atau banyak pengguna.
 *
 * @param mysqli $mysqli Koneksi database.
 * @param int|array $id_user ID pengguna tunggal atau array ID pengguna.
 * @param string $pesan Isi pesan notifikasi.
 * @param string|null $tautan URL tujuan saat notifikasi diklik.
 */
function create_notification($mysqli, $id_user, $pesan, $tautan = null) {
    $sql = "INSERT INTO notifikasi (id_user, pesan, tautan) VALUES (?, ?, ?)";
    if ($stmt = $mysqli->prepare($sql)) {
        if (is_array($id_user)) {
            // Jika penerima adalah array (banyak user)
            foreach ($id_user as $penerima_id) {
                $stmt->bind_param("iss", $penerima_id, $pesan, $tautan);
                $stmt->execute();
            }
        } else {
            // Jika penerima hanya satu user
            $stmt->bind_param("iss", $id_user, $pesan, $tautan);
            $stmt->execute();
        }
        $stmt->close();
    }
}

/**
 * Mengambil semua ID user berdasarkan peran (role).
 *
 * @param mysqli $mysqli Koneksi database.
 * @param string $role Peran user yang dicari (misal: 'admin', 'staff').
 * @return array Array berisi ID para pengguna.
 */
function get_user_ids_by_role($mysqli, $role) {
    $ids = [];
    $sql = "SELECT id FROM users WHERE role = ?";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = $row['id'];
        }
        $stmt->close();
    }
    return $ids;
}

/**
 * Menghapus notifikasi berdasarkan tautannya untuk pengguna yang sedang login.
 *
 * @param mysqli $mysqli Koneksi database.
 * @param int $user_id ID pengguna yang sedang login.
 * @param string $tautan Link notifikasi yang ingin dihapus.
 */
function delete_notification_by_link_and_user($mysqli, $user_id, $tautan) {
    $sql = "DELETE FROM notifikasi WHERE id_user = ? AND tautan = ?";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("is", $user_id, $tautan);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Menghapus notifikasi berdasarkan tautannya (versi lama, kurang spesifik).
 * Berguna untuk membersihkan notifikasi setelah tugasnya selesai.
 *
 * @param mysqli $mysqli Koneksi database.
 * @param string $tautan Link notifikasi yang ingin dihapus.
 */
function delete_notification_by_link($mysqli, $tautan) {
    $sql = "DELETE FROM notifikasi WHERE tautan = ?";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("s", $tautan);
        $stmt->execute();
        $stmt->close();
    }
}

