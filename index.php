<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';

requireAuth();

$period = $_GET['period'] ?? date('Y-m');
if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

[$monthStart, $periodStart, $periodEnd] = monthPeriod($period);
$selectedYear = (int)$monthStart->format('Y');
$selectedMonth = (int)$monthStart->format('n');
$monthSelectNames = [
    1 => 'Январь',
    2 => 'Февраль',
    3 => 'Март',
    4 => 'Апрель',
    5 => 'Май',
    6 => 'Июнь',
    7 => 'Июль',
    8 => 'Август',
    9 => 'Сентябрь',
    10 => 'Октябрь',
    11 => 'Ноябрь',
    12 => 'Декабрь',
];
$firstYear = min((int)date('Y') - 3, $selectedYear);
$lastYear = max((int)date('Y') + 1, $selectedYear);

// Справочник компаний для фильтра отчёта (из суточного кеша).
// Целевых клиентов поднимаем наверх — иначе они теряются среди сотен строк.
$companies = userCan('excel') ? fetchCrmCompanies() : [];
$defaultCompanyIds = array_map('intval', REPORT_DEFAULT_COMPANY_IDS);
$targetCompanies = [];
$otherCompanies = [];
foreach ($companies as $company) {
    if (in_array((int)$company['id'], $defaultCompanyIds, true)) {
        $targetCompanies[] = $company;
    } else {
        $otherCompanies[] = $company;
    }
}

$pageTitle = 'Закрытые часы';
$navActive = 'main';
require __DIR__ . '/partials/head.php';
?>
    <section class="panel page-panel page-panel--with-extra">
        <div class="heading">
            <div>
                <p class="eyebrow">Bitrix24 / XLSX</p>
                <h1>Закрытые часы</h1>
            </div>
            <span id="monthTitle" class="month-chip"><?= h(russianMonthTitle($monthStart)) ?></span>
        </div>

        <form class="report-form" action="report.php" method="get">
            <label for="periodMonth">Месяц отчёта</label>
            <div class="controls">
                <input id="period" name="period" type="hidden" value="<?= h($period) ?>">
                <div class="period-picker">
                    <select id="periodMonth" aria-label="Месяц отчёта">
                        <?php foreach ($monthSelectNames as $monthNumber => $monthName): ?>
                            <option value="<?= h((string)$monthNumber) ?>"<?= $monthNumber === $selectedMonth ? ' selected' : '' ?>>
                                <?= h($monthName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="periodYear" aria-label="Год отчёта">
                        <?php for ($year = $firstYear; $year <= $lastYear; $year++): ?>
                            <option value="<?= h((string)$year) ?>"<?= $year === $selectedYear ? ' selected' : '' ?>>
                                <?= h((string)$year) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php if (userCan('excel')): ?>
                    <button type="submit">
                        <span>Скачать Excel</span>
                    </button>
                <?php else: ?>
                    <button type="button" disabled title="<?= h(isGuest() ? 'Войдите, чтобы скачать отчёт' : 'Недоступно для вашей роли') ?>">
                        <span>Скачать Excel</span>
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <?php if (userCan('excel')): ?>
            <form class="company-filter" action="report.php" method="get">
                <input type="hidden" name="period" id="companyPeriod" value="<?= h($period) ?>">
                <label for="companySelect">Отчёт по выбранным компаниям</label>
                <p class="action-hint company-hint">
                    Тот же период, но только задачи выбранных клиентов. В файле будет один лист «Все задачи».
                </p>
                <div id="companyChips" class="company-chips" role="list" aria-label="Выбранные компании"></div>
                <div id="companyInputs" hidden></div>

                <div class="company-controls">
                    <select id="companyPicker" aria-label="Добавить компанию в отчёт">
                        <option value="">Добавить компанию…</option>
                        <?php if (!empty($targetCompanies)): ?>
                            <optgroup label="Целевые клиенты" data-group="target">
                                <?php foreach ($targetCompanies as $company): ?>
                                    <option value="<?= (int)$company['id'] ?>"><?= h($company['title']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <optgroup label="Остальные компании" data-group="other">
                            <?php foreach ($otherCompanies as $company): ?>
                                <option value="<?= (int)$company['id'] ?>"><?= h($company['title']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    <button type="submit" class="secondary-action">
                        <span>Скачать отчёт по выбранным компаниям</span>
                    </button>
                </div>

                <script id="companyPreset" type="application/json"><?= json_encode(array_map(static function (array $company): array {
                    return ['id' => (int)$company['id'], 'title' => (string)$company['title']];
                }, $targetCompanies), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
                <?php if (empty($companies)): ?>
                    <p class="action-hint">Справочник компаний пока не загружен — откройте страницу ещё раз или проверьте связь с Битриксом.</p>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <dl class="meta">
            <div>
                <dt>Месяц результата</dt>
                <dd id="resultMonthValue"><?= h($monthStart->format('m.Y')) ?></dd>
            </div>
            <div>
                <dt>Закрыты с</dt>
                <dd id="closedFromValue"><?= h($periodStart->format('d.m.Y')) ?></dd>
            </div>
            <div>
                <dt>Закрыты по</dt>
                <dd id="closedToValue"><?= h($periodEnd->format('d.m.Y')) ?></dd>
            </div>
        </dl>

        <?php if (userCan('unf')): ?>
            <div class="unf-action">
                <button id="createUnfTimeButton" class="secondary-action" type="button">
                    <span>создать "Учеты времени" в БОЕВОЙ УНФ</span>
                </button>
                <div id="unfActionStatus" class="action-log" role="log" aria-live="polite" aria-label="Журнал создания учётов времени"></div>
            </div>

            <div class="unf-action">
                <button id="createUnfWorkOrderButton" class="secondary-action" type="button">
                    <span>создать "Задания на работу" в БОЕВОЙ УНФ</span>
                </button>
                <div id="workOrderActionStatus" class="action-log" role="log" aria-live="polite" aria-label="Журнал создания заданий на работу"></div>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel quick-panel" aria-labelledby="quickLinksTitle">
        <div class="quick-links">
            <h2 id="quickLinksTitle">Полезные кнопки</h2>
            <?php
            // Внешним пользователям рабочие сервисы не показываем — все кнопки ведут в телеграм-канал.
            $quickLinks = [
                'Наш таск' => 'https://task.kodar-msk.ru/',
                'Наш битрикс' => 'https://nalog1c.bitrix24.ru/',
                'Наша УНФ' => 'https://1cfresh.com/a/sbm/767684',
            ];
            ?>
            <div class="quick-link-grid">
                <?php foreach ($quickLinks as $quickLabel => $quickUrl): ?>
                    <a href="<?= h(isMaskedView() ? TELEGRAM_CHANNEL_URL : $quickUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($quickLabel) ?></a>
                <?php endforeach; ?>
                <?php if (userCan('admin')): ?>
                    <a href="admin.php" class="quick-link-admin">Администрирование</a>
                <?php elseif (isGuest()): ?>
                    <?php // У гостя на месте админки — приглашение к автору. ?>
                    <a href="<?= h(GUEST_TELEGRAM_URL) ?>" class="quick-link-want" target="_blank" rel="noopener noreferrer">ХОЧУ ТАКЖЕ</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<script>
    (function () {
        var periodInput = document.getElementById('period');
        var periodMonth = document.getElementById('periodMonth');
        var periodYear = document.getElementById('periodYear');
        var monthTitle = document.getElementById('monthTitle');
        var resultMonthValue = document.getElementById('resultMonthValue');
        var closedFromValue = document.getElementById('closedFromValue');
        var closedToValue = document.getElementById('closedToValue');
        var createUnfTimeButton = document.getElementById('createUnfTimeButton');
        var unfActionStatus = document.getElementById('unfActionStatus');
        var createUnfWorkOrderButton = document.getElementById('createUnfWorkOrderButton');
        var workOrderActionStatus = document.getElementById('workOrderActionStatus');
        var monthNames = [
            'Январь',
            'Февраль',
            'Март',
            'Апрель',
            'Май',
            'Июнь',
            'Июль',
            'Август',
            'Сентябрь',
            'Октябрь',
            'Ноябрь',
            'Декабрь'
        ];

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function formatDate(date) {
            return pad(date.getUTCDate()) + '.' + pad(date.getUTCMonth() + 1) + '.' + date.getUTCFullYear();
        }

        var companyPeriod = document.getElementById('companyPeriod');
        var closedWindowMonths = <?= (int)REPORT_CLOSED_WINDOW_MONTHS ?>;

        /* Компании-фильтры: выбранные висят чипами сверху и пропадают
           из выпадающего списка; крестик возвращает компанию обратно. */
        (function () {
            var picker = document.getElementById('companyPicker');
            var chips = document.getElementById('companyChips');
            var inputs = document.getElementById('companyInputs');
            var preset = document.getElementById('companyPreset');
            if (!picker || !chips || !inputs) {
                return;
            }

            function optionGroupFor(id) {
                // Целевые клиенты возвращаются в свою группу, остальные — в свою.
                var groups = picker.querySelectorAll('optgroup');
                for (var i = 0; i < groups.length; i++) {
                    if (groups[i].dataset.group === 'target' && presetIds.indexOf(id) !== -1) {
                        return groups[i];
                    }
                    if (groups[i].dataset.group === 'other' && presetIds.indexOf(id) === -1) {
                        return groups[i];
                    }
                }
                return picker;
            }

            function restoreOption(id, title) {
                var group = optionGroupFor(id);
                var option = document.createElement('option');
                option.value = String(id);
                option.textContent = title;

                // Возвращаем на место по алфавиту, а не в конец списка
                var siblings = group.querySelectorAll('option');
                for (var i = 0; i < siblings.length; i++) {
                    if (siblings[i].textContent.localeCompare(title, 'ru') > 0) {
                        group.insertBefore(option, siblings[i]);
                        return;
                    }
                }
                group.appendChild(option);
            }

            function addCompany(id, title) {
                id = String(id);

                var chip = document.createElement('span');
                chip.className = 'company-chip';
                chip.setAttribute('role', 'listitem');

                var label = document.createElement('span');
                label.textContent = title;
                chip.appendChild(label);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'company-chip-remove';
                remove.setAttribute('aria-label', 'Убрать ' + title);
                remove.textContent = '×';
                chip.appendChild(remove);

                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'companies[]';
                input.value = id;
                inputs.appendChild(input);

                remove.addEventListener('click', function () {
                    chip.remove();
                    input.remove();
                    restoreOption(id, title);
                    updateEmptyState();
                });

                chips.appendChild(chip);

                var option = picker.querySelector('option[value="' + id + '"]');
                if (option) {
                    option.remove();
                }

                updateEmptyState();
            }

            function updateEmptyState() {
                var isEmpty = chips.querySelectorAll('.company-chip').length === 0;
                chips.dataset.empty = isEmpty ? 'true' : 'false';
            }

            var presetIds = [];
            var presetList = [];
            try {
                presetList = JSON.parse(preset ? preset.textContent : '[]') || [];
            } catch (error) {
                presetList = [];
            }
            presetList.forEach(function (company) {
                presetIds.push(String(company.id));
            });
            presetList.forEach(function (company) {
                addCompany(company.id, company.title);
            });

            picker.addEventListener('change', function () {
                if (!picker.value) {
                    return;
                }
                var selected = picker.options[picker.selectedIndex];
                addCompany(picker.value, selected.textContent.trim());
                picker.value = '';
            });

            updateEmptyState();
        }());

        function syncPeriodFromSelects() {
            periodInput.value = periodYear.value + '-' + pad(periodMonth.value);
            // Форма отчёта по компаниям использует тот же выбранный месяц
            if (companyPeriod) {
                companyPeriod.value = periodInput.value;
            }
            updateMeta();
        }

        function updateMeta() {
            var value = periodInput.value;
            if (!/^\d{4}-\d{2}$/.test(value)) {
                return;
            }

            var parts = value.split('-');
            var year = Number(parts[0]);
            var monthIndex = Number(parts[1]) - 1;
            // Окно поиска: closedWindowMonths месяцев до начала месяца и после его конца
            var closedFrom = new Date(Date.UTC(year, monthIndex - closedWindowMonths, 1));
            var closedTo = new Date(Date.UTC(year, monthIndex + closedWindowMonths + 1, 0));

            monthTitle.textContent = monthNames[monthIndex] + ' ' + year;
            resultMonthValue.textContent = pad(monthIndex + 1) + '.' + year;
            closedFromValue.textContent = formatDate(closedFrom);
            closedToValue.textContent = formatDate(closedTo);

            if (periodMonth.value !== String(monthIndex + 1)) {
                periodMonth.value = String(monthIndex + 1);
            }

            if (periodYear.value !== String(year)) {
                periodYear.value = String(year);
            }
        }

        function appendLog(logElement, message, state) {
            if (!logElement) {
                return;
            }

            var now = new Date();
            var pad2 = function (value) {
                return String(value).padStart(2, '0');
            };
            var time = pad2(now.getHours()) + ':' + pad2(now.getMinutes()) + ':' + pad2(now.getSeconds());

            String(message).split('\n').forEach(function (lineText, index) {
                if (lineText === '') {
                    return;
                }

                var line = document.createElement('div');
                line.className = 'action-log-line';
                line.dataset.state = state || '';
                line.textContent = (index === 0 ? time + '  ' : '        ') + lineText;
                logElement.appendChild(line);
            });

            // Свежие записи внизу; вверх можно листать.
            logElement.scrollTop = logElement.scrollHeight;
        }

        function summarizeUnfResult(result) {
            var created = Array.isArray(result.created) ? result.created : [];
            var skipped = Array.isArray(result.skipped) ? result.skipped : [];
            var errors = Array.isArray(result.errors) ? result.errors : [];
            var lines = [
                'Создано: ' + created.length + '. Пропущено: ' + skipped.length + '. Ошибок: ' + errors.length + '.'
            ];

            created.forEach(function (item) {
                lines.push('+ ' + item.name + (item.number ? ' — ' + item.number : '') + (item.hours ? ' (' + item.hours + ' ч)' : ''));
            });

            skipped.forEach(function (item) {
                lines.push('~ ' + item.name + ': ' + item.reason + (item.existing_number ? ' (' + item.existing_number + ')' : ''));
            });

            errors.forEach(function (item) {
                lines.push('! ' + item.name + ': ' + item.error);
            });

            return lines.join('\n');
        }

        function bindUnfAction(button, statusElement, endpoint, pendingMessage) {
            if (!button) {
                return;
            }

            button.addEventListener('click', function () {
                button.disabled = true;
                appendLog(statusElement, pendingMessage, 'pending');

                fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        period: periodInput.value
                    })
                }).then(function (response) {
                    return response.text().then(function (text) {
                        var data = {};
                        try {
                            data = text ? JSON.parse(text) : {};
                        } catch (error) {
                            throw new Error('УНФ вернула некорректный ответ.');
                        }

                        if (!response.ok && data.error) {
                            throw new Error(data.error);
                        }

                        if (!response.ok) {
                            throw new Error('Запрос завершился с HTTP ' + response.status + '.');
                        }

                        return data;
                    });
                }).then(function (data) {
                    var errors = Array.isArray(data.errors) ? data.errors : [];
                    appendLog(statusElement, summarizeUnfResult(data), errors.length > 0 ? 'error' : 'success');
                }).catch(function (error) {
                    appendLog(statusElement, error.message, 'error');
                }).finally(function () {
                    button.disabled = false;
                });
            });
        }

        periodMonth.addEventListener('change', syncPeriodFromSelects);
        periodYear.addEventListener('change', syncPeriodFromSelects);
        bindUnfAction(createUnfTimeButton, unfActionStatus, 'unf_time.php', 'Создаю учёты времени в УНФ...');
        bindUnfAction(createUnfWorkOrderButton, workOrderActionStatus, 'unf_workorder.php', 'Создаю задания на работу в УНФ...');
    }());
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
