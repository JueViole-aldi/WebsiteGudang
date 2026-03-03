<?php
// ajax_handler.php
define('APP_LOADED', true);

// Pastikan hanya request AJAX yang bisa mengakses file ini
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    die('Akses langsung tidak diizinkan.');
}

require_once 'db_connect.php';
require_once 'helpers.php';

// Pastikan user sudah login
if (!is_logged_in()) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Akses ditolak']);
    exit;
}

header('Content-Type: application/json');
$user_id = $_SESSION['id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    // Kasus untuk mengambil notifikasi
    case 'get_notifications':
        $sql = "SELECT id, pesan, tautan, sudah_dibaca, tanggal_dibuat 
                FROM notifikasi 
                WHERE id_user = ? 
                ORDER BY tanggal_dibuat DESC 
                LIMIT 10";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }

        // Hitung juga notifikasi yang belum dibaca
        $unread_count_sql = "SELECT COUNT(*) as total FROM notifikasi WHERE id_user = ? AND sudah_dibaca = 0";
        $unread_stmt = $mysqli->prepare($unread_count_sql);
        $unread_stmt->bind_param("i", $user_id);
        $unread_stmt->execute();
        $unread_count = $unread_stmt->get_result()->fetch_assoc()['total'];

        echo json_encode(['notifications' => $notifications, 'unread_count' => $unread_count]);
        break;

    // Kasus untuk menandai semua notifikasi sebagai sudah dibaca
    case 'mark_all_read':
        $sql = "UPDATE notifikasi SET sudah_dibaca = 1 WHERE id_user = ? AND sudah_dibaca = 0";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Gagal memperbarui status.']);
        }
        break;
        
    default:
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Aksi tidak valid.']);
        break;
}

$mysqli->close();
?>

