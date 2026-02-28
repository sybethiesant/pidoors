            </main>

    <footer class="footer mt-auto py-3 bg-light">
        <div class="container text-center">
            <span class="text-muted">PiDoors Access Control System</span>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="<?php echo htmlspecialchars($config['url']); ?>/js/jquery-3.5.1.js"></script>
    <script src="<?php echo htmlspecialchars($config['url']); ?>/js/popper.min.js"></script>
    <script src="<?php echo htmlspecialchars($config['url']); ?>/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo htmlspecialchars($config['url']); ?>/js/jquery.dataTables.min.js"></script>
    <script src="<?php echo htmlspecialchars($config['url']); ?>/js/dataTables.bootstrap5.min.js"></script>
    <script src="<?php echo htmlspecialchars($config['url']); ?>/js/Chart.min.js"></script>

    <script>
        // Initialize all DataTables
        $(document).ready(function() {
            if ($.fn.DataTable) {
                $('.datatable').DataTable({
                    responsive: true,
                    pageLength: 25,
                    order: [[0, 'asc']],
                    language: {
                        search: "Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    }
                });
            }

            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                $('.alert-dismissible').fadeOut('slow');
            }, 5000);
        });

        // Confirm delete actions
        function confirmDelete(message) {
            return confirm(message || 'Are you sure you want to delete this item?');
        }

        // Format datetime for display
        function formatDateTime(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleString();
        }
    </script>
</body>
</html>
