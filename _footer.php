<?php
/**
 * _footer.php
 *
 * File ini berfungsi sebagai penutup layout utama aplikasi.
 * Tugasnya adalah menutup tag-tag HTML yang dibuka di _header.php
 * dan memuat file JavaScript yang dibutuhkan di seluruh halaman.
 */
?>
        </main> <!-- Penutup tag <main> dari _header.php -->
    </div> <!-- Penutup tag <div id="app-container"> dari _header.php -->

    <!-- Script untuk mengaktifkan ikon dari library Lucide Icons -->
    <script>
        lucide.createIcons();
    </script>
    
    <!-- Script untuk sistem notifikasi real-time -->
    <script src="assets/js/notifications.js"></script>

    <!-- Skrip untuk fungsionalitas UI seperti sidebar -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const sidebar = document.getElementById('sidebar');
            const toggleButton = document.getElementById('sidebar-toggle');
            const mainContent = document.getElementById('main-content');
            const toggleIcon = toggleButton.querySelector('i');
            const sidebarNav = document.getElementById('sidebar-nav');

            const collapseSidebar = (collapse) => {
                if (collapse) {
                    sidebar.classList.add('w-20', 'collapsed');
                    sidebar.classList.remove('w-64');
                    mainContent.classList.add('ml-20');
                    mainContent.classList.remove('ml-64');
                    toggleIcon.style.transform = 'rotate(180deg)';
                    localStorage.setItem('sidebarCollapsed', 'true');
                } else {
                    sidebar.classList.remove('w-20', 'collapsed');
                    sidebar.classList.add('w-64');
                    mainContent.classList.remove('ml-20');
                    mainContent.classList.add('ml-64');
                    toggleIcon.style.transform = 'rotate(0deg)';
                    localStorage.setItem('sidebarCollapsed', 'false');
                }
                 lucide.createIcons();
            };

            // Event listener untuk tombol toggle
            toggleButton.addEventListener('click', () => {
                const isCollapsed = sidebar.classList.contains('collapsed');
                collapseSidebar(!isCollapsed);
            });

            // Cek status dari local storage saat halaman dimuat
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                collapseSidebar(true);
            }

            // Simpan posisi scroll sidebar
            sidebarNav.addEventListener('scroll', () => {
                localStorage.setItem('sidebarScrollPos', sidebarNav.scrollTop);
            });

            // Kembalikan posisi scroll sidebar
            const scrollPos = localStorage.getItem('sidebarScrollPos');
            if (scrollPos) {
                sidebarNav.scrollTop = parseInt(scrollPos, 10);
            }
        });
    </script>
    
    <!-- SCRIPT BARU: Untuk transisi halaman yang halus -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const overlay = document.getElementById('page-transition-overlay');
            const transitionDuration = 250;
            
            // 1. Fade out overlay saat halaman selesai dimuat
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(() => {
                    overlay.style.pointerEvents = 'none';
                }, transitionDuration); 
            }

            // 2. Fade in overlay saat link navigasi diklik
            const navLinks = document.querySelectorAll('aside a'); // Hanya target link di sidebar
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const href = this.getAttribute('href');
                    // Hanya jalankan jika link valid dan bukan link logout
                    if (href && href !== '#' && !href.toLowerCase().includes('logout.php')) {
                        e.preventDefault(); // Mencegah navigasi standar
                        
                        if (overlay) {
                            overlay.style.pointerEvents = 'auto';
                            overlay.style.opacity = '1';
                        }
                        
                        // Tunggu animasi fade-in selesai, baru pindah halaman
                        setTimeout(() => {
                            window.location.href = href;
                        }, transitionDuration);
                    }
                });
            });
        });
    </script>

</body> <!-- Penutup tag <body> dari _header.php -->
</html> <!-- Penutup tag <html> dari _header.php -->

