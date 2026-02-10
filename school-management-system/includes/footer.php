<?php
/**
 * Shared footer and JavaScript includes.
 */

declare(strict_types=1);
?>
    </div>
</div>

<script>
    window.APP_BASE_URL = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(app_url('assets/js/api.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/main.js')) ?>"></script>
<?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
        <script src="<?= e(app_url($script)) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
