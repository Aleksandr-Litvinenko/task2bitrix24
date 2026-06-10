<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

$period = $_GET['period'] ?? date('Y-m');
if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

$pageTitle = 'ХОЧУ ТАКЖЕ — taskCRM';
$navActive = 'wantsame';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel want-panel">
        <div class="panel-grid" aria-hidden="true"></div>
        <div class="want-inner">
            <p class="eyebrow">taskCRM / Bitrix24 / УНФ</p>
            <h1 class="want-title">Хочешь такую же систему себе?</h1>
            <p class="want-sub">Закрытые часы в Excel, доска задач по проектам, дашборд сотрудников и документы в УНФ — одной кнопкой.</p>
            <a class="want-cta" href="<?= h(TELEGRAM_CHANNEL_URL) ?>">
                <span>да, хочу также</span>
            </a>
        </div>
    </section>
<?php require __DIR__ . '/partials/foot.php'; ?>
