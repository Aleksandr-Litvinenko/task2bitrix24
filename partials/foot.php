<?php
declare(strict_types=1);

/**
 * Общий «подвал» страниц. Подключает фоновый курсор-трейл и закрывает разметку.
 * Любой постраничный <script> выводится страницей ДО подключения этого файла.
 */

$effectsVersion = is_file(__DIR__ . '/../effects.js') ? (string)filemtime(__DIR__ . '/../effects.js') : '1';
?>
    <p class="app-version">taskCRM <?= h(defined('APP_VERSION') ? APP_VERSION : '') ?></p>
</main>
<script src="effects.js?v=<?= h($effectsVersion) ?>"></script>
</body>
</html>
