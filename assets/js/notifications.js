document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('notification-container');
    if (!container) return;

    const bell = document.getElementById('notification-bell');
    const countBadge = document.getElementById('notification-count');
    const dropdown = document.getElementById('notification-dropdown');
    const list = document.getElementById('notification-list');

    let isDropdownOpen = false;

    // Fungsi untuk mengambil notifikasi
    const fetchNotifications = async () => {
        try {
            const response = await fetch('dashboard.php?page=ajax_handler&action=get_unread');
            if (!response.ok) return;
            
            const notifications = await response.json();
            updateDropdown(notifications);
        } catch (error) {
            console.error("Gagal mengambil notifikasi:", error);
        }
    };

    // Fungsi untuk memperbarui tampilan dropdown
    const updateDropdown = (notifications) => {
        // Perbarui jumlah notifikasi
        if (notifications.length > 0) {
            countBadge.textContent = notifications.length;
            countBadge.classList.remove('hidden');
        } else {
            countBadge.classList.add('hidden');
        }

        // Isi daftar notifikasi
        list.innerHTML = '';
        if (notifications.length === 0) {
            const noNotifItem = document.createElement('div');
            noNotifItem.className = 'p-4 text-center text-sm text-gray-500';
            noNotifItem.textContent = 'Tidak ada notifikasi baru.';
            list.appendChild(noNotifItem);
        } else {
            notifications.forEach(notif => {
                const link = document.createElement('a');
                link.href = notif.tautan;
                link.className = 'block p-3 hover:bg-gray-100 border-b last:border-b-0';
                
                const message = document.createElement('p');
                message.className = 'text-sm text-gray-700';
                message.textContent = notif.pesan;

                const time = document.createElement('p');
                time.className = 'text-xs text-gray-400 mt-1';
                time.textContent = new Date(notif.timestamp).toLocaleString('id-ID');

                link.appendChild(message);
                link.appendChild(time);
                list.appendChild(link);
            });
        }
    };
    
    // Fungsi untuk menandai semua notifikasi telah dibaca
    const markAllAsRead = async () => {
        try {
            await fetch('dashboard.php?page=ajax_handler&action=mark_all_read', {
                method: 'POST'
            });
            countBadge.classList.add('hidden');
        } catch (error) {
            console.error("Gagal menandai notifikasi:", error);
        }
    };

    // Event listener untuk klik ikon lonceng
    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        isDropdownOpen = !isDropdownOpen;
        if (isDropdownOpen) {
            dropdown.classList.remove('hidden');
            if (parseInt(countBadge.textContent) > 0) {
                markAllAsRead();
            }
        } else {
            dropdown.classList.add('hidden');
        }
    });

    // Menutup dropdown jika klik di luar area
    document.addEventListener('click', () => {
        if (isDropdownOpen) {
            isDropdownOpen = false;
            dropdown.classList.add('hidden');
        }
    });

    // Panggil fungsi pertama kali
    fetchNotifications();
    // Set interval untuk memeriksa notifikasi baru setiap 30 detik
    setInterval(fetchNotifications, 30000);
});
