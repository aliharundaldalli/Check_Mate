        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const overlay = document.getElementById('mobileOverlay');
            
            if (window.innerWidth <= 768) {
                // Mobile mode
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            } else {
                // Desktop mode
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            }
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.getElementById('mobileToggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !mobileToggle.contains(event.target) &&
                sidebar.classList.contains('show')) {
                toggleSidebar();
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const overlay = document.getElementById('mobileOverlay');
            
            if (window.innerWidth > 768) {
                // Desktop mode - reset mobile classes
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            } else {
                // Mobile mode - reset desktop classes
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide sidebar on mobile
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.remove('show');
            }
            
            // Display any flash messages
            <?php if (isset($_SESSION['flash_message'])): ?>
                const alertType = '<?php echo $_SESSION['flash_type'] ?? 'info'; ?>';
                const alertMessage = '<?php echo addslashes($_SESSION['flash_message']); ?>';
                showAlert(alertMessage, alertType);
                <?php 
                unset($_SESSION['flash_message']);
                unset($_SESSION['flash_type']);
                ?>
            <?php endif; ?>
        });

        // Alert system
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.style.position = 'fixed';
            alertDiv.style.top = '20px';
            alertDiv.style.right = '20px';
            alertDiv.style.zIndex = '9999';
            alertDiv.style.minWidth = '300px';
            
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(alertDiv);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Loading state management
        function showLoading(element) {
            const originalText = element.innerHTML;
            element.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Yükleniyor...';
            element.disabled = true;
            element.dataset.originalText = originalText;
        }

        function hideLoading(element) {
            if (element.dataset.originalText) {
                element.innerHTML = element.dataset.originalText;
                element.disabled = false;
                delete element.dataset.originalText;
            }
        }

        // Auto-refresh active sessions (for student dashboard)
        if (typeof refreshActiveContent !== 'undefined' && refreshActiveContent) {
            setInterval(function() {
                location.reload();
            }, 30000); // 30 seconds
        }

        // Tooltip initialization
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });

        // Confirmation dialogs
        function confirmAction(message = 'Bu işlemi gerçekleştirmek istediğinizden emin misiniz?') {
            return confirm(message);
        }

        // Data table search functionality
        function searchTable(inputId, tableId) {
            const input = document.getElementById(inputId);
            const table = document.getElementById(tableId);
            
            if (!input || !table) return;
            
            const rows = table.getElementsByTagName('tbody')[0]?.getElementsByTagName('tr') || [];
            
            input.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i];
                    const cells = row.getElementsByTagName('td');
                    let found = false;
                    
                    for (let j = 0; j < cells.length; j++) {
                        const cell = cells[j];
                        if (cell.textContent.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                    
                    row.style.display = found ? '' : 'none';
                }
            });
        }

        // Print functionality
        function printDiv(divId) {
            const printContent = document.getElementById(divId);
            const windowPrint = window.open('', '', 'width=800,height=600');
            
            windowPrint.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Yazdır</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        @media print {
                            .no-print { display: none; }
                            body { font-size: 12px; }
                        }
                    </style>
                </head>
                <body>
                    ${printContent.innerHTML}
                </body>
                </html>
            `);
            
            windowPrint.document.close();
            windowPrint.focus();
            windowPrint.print();
            windowPrint.close();
        }

        // Export to CSV
        function exportToCSV(tableId, filename = 'data.csv') {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const rows = Array.from(table.querySelectorAll('tr'));
            const csvContent = rows.map(row => {
                const cells = Array.from(row.querySelectorAll('td, th'));
                return cells.map(cell => {
                    const text = cell.textContent.trim();
                    return text.includes(',') ? `"${text}"` : text;
                }).join(',');
            }).join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Form validation
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        function validatePhoneNumber(phone) {
            const re = /^[\+]?[0-9\s\-\(\)]{10,}$/;
            return re.test(phone);
        }

        // Date formatting
        function formatDate(dateString, format = 'dd.mm.yyyy') {
            const date = new Date(dateString);
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            
            switch (format) {
                case 'dd.mm.yyyy':
                    return `${day}.${month}.${year}`;
                case 'mm/dd/yyyy':
                    return `${month}/${day}/${year}`;
                case 'yyyy-mm-dd':
                    return `${year}-${month}-${day}`;
                default:
                    return `${day}.${month}.${year}`;
            }
        }

        // Time formatting
        function formatTime(timeString) {
            const time = new Date(`2000-01-01 ${timeString}`);
            return time.toLocaleTimeString('tr-TR', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: false 
            });
        }

        // Number formatting
        function formatNumber(number, decimals = 0) {
            return Number(number).toLocaleString('tr-TR', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        }

        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showAlert('Panoya kopyalandı!', 'success');
            }).catch(() => {
                showAlert('Kopyalama başarısız!', 'error');
            });
        }
    </script>
</body>
</html>
