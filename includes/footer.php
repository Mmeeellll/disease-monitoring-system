<?php
/**
 * FILE: includes/footer.php
 * PURPOSE: Persistent layout footer and global client-side JavaScript loader.
 * SYSTEM ROLE: Closes the main HTML structure opened in header.php and loads
 *              Bootstrap JS (modals/alerts), Chart.js, and assets/app.js.
 */
?>
</section>
<footer class="footer">
  <span>© <?= date('Y') ?> Disease Monitoring · Health Unit Management System</span>
</footer>
</main>
</div>

<!-- Bootstrap 5 Bundle (Modals, Dismissible Alerts, Dropdowns) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js Library for Dashboard & Analytics Graphs -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<!-- Custom Chart Initialization Script -->
<script src="assets/app.js"></script>
</body>
</html>