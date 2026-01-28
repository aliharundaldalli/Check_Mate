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

        // Confirmation dialogs
        function confirmDelete(message = 'Bu işlemi gerçekleştirmek istediğinizden emin misiniz?') {
            return confirm(message);
        }

        // Form validation helpers
        function validateForm(formId) {
            const form = document.getElementById(formId);
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            return isValid;
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

        // Data table search functionality
        function searchTable(inputId, tableId) {
            const input = document.getElementById(inputId);
            const table = document.getElementById(tableId);
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
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

        // Tooltip initialization
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>
