<?php
// /var/www/sync/ui/footer.php
// Common footer - closes the page structure
?>
    </div><!-- container-xl -->
</div><!-- page-body -->

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/js/tabler.min.js"></script>
<?php if (isset($isAuthenticated) && $isAuthenticated): ?>
<script type="module" src="./js/global.js"></script>
<?php endif; ?>
</body>
</html>
