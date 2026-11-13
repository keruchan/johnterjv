<?php
/** Shared, read-only registry date and summary filters. Authorization stays with each caller. */
require_once __DIR__ . '/view.php';

function registry_range(?array $input = null): array
{
    $input ??= $_GET;
    $range = ['from' => '', 'to' => '', 'error' => ''];
    foreach (['from', 'to'] as $key) {
        $value = $input['date_' . $key] ?? '';
        if (!is_string($value)) { $range['error'] = 'Enter valid From and To dates.'; continue; }
        $value = trim($value);
        $range[$key] = $value;
        if ($value === '') { continue; }
        $date = preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) { $range['error'] = 'Enter valid From and To dates.'; }
    }
    if ($range['error'] === '' && $range['from'] !== '' && $range['to'] !== '' && $range['from'] > $range['to']) {
        $range['error'] = 'From date must be on or before To date.';
    }
    return $range;
}

function registry_date_rows(array $rows, string|callable $field, ?array $range = null): array
{
    $range ??= registry_range();
    if ($range['error'] !== '') { return []; }
    if ($range['from'] === '' && $range['to'] === '') { return $rows; }
    return array_values(array_filter($rows, static function (array $row) use ($field, $range): bool {
        $date = substr((string) (is_callable($field) ? $field($row) : ($row[$field] ?? '')), 0, 10);
        return $date !== '' && ($range['from'] === '' || $date >= $range['from'])
            && ($range['to'] === '' || $date <= $range['to']);
    }));
}

function registry_selected(): string
{
    return is_string($_GET['summary'] ?? '') ? ($_GET['summary'] ?? 'all') : 'all';
}

/** SQL bounds are applied before pagination; callers supply a fixed column name. */
function registry_sql_dates(array &$where, array &$params, string $column, ?array $input = null): void
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/D', $column)) { throw new InvalidArgumentException('Invalid date column.'); }
    $range = registry_range($input);
    if ($range['error'] !== '') { $where[] = '1 = 0'; return; }
    if ($range['from'] !== '') { $where[] = $column . ' >= :registry_from'; $params[':registry_from'] = $range['from'] . ' 00:00:00'; }
    if ($range['to'] !== '') { $where[] = $column . ' <= :registry_to'; $params[':registry_to'] = $range['to'] . ' 23:59:59'; }
}

function registry_category_rows(array $rows, array $predicates): array
{
    $selected = registry_selected();
    return isset($predicates[$selected]) ? array_values(array_filter($rows, $predicates[$selected])) : $rows;
}

function registry_counts(array $rows, array $predicates): array
{
    $counts = ['all' => count($rows)];
    foreach ($predicates as $key => $predicate) { $counts[$key] = count(array_filter($rows, $predicate)); }
    return $counts;
}

function registry_url(array $overrides = [], string $target = ''): string
{
    $query = $target === '' ? array_filter($_GET, 'is_scalar') : array_intersect_key($_GET, array_flip(['date_from', 'date_to']));
    unset($query['page'], $query['before'], $query['view'], $query['edit'], $query['export']);
    if (array_key_exists('date_from', $overrides) || array_key_exists('date_to', $overrides)) {
        unset($query['audit_date_from'], $query['audit_date_to'], $query['login_date_from'], $query['login_date_to'], $query['audit_page'], $query['login_page']);
    }
    $query = array_merge($query, $overrides);
    $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
    return $target . ($query ? '?' . http_build_query($query) : ($target === '' ? '?' : ''));
}

function registry_card_attributes(string $key, array $overrides = [], string $target = ''): string
{
    $query = array_merge(['summary' => $key, 'status' => null, 'classification' => null, 'donation_status' => null, 'movement_type' => null], $overrides);
    $selected = $target === '' && registry_selected() === $key;
    return 'href="' . e(registry_url($query, $target)) . '"' . ($selected ? ' aria-current="page"' : '');
}

function registry_hidden_filters(array $exclude = []): void
{
    foreach (['date_from', 'date_to', 'summary', 'record_view'] as $key) {
        if (in_array($key, $exclude, true) || !isset($_GET[$key]) || !is_string($_GET[$key])) { continue; }
        echo '<input type="hidden" name="' . e($key) . '" value="' . e($_GET[$key]) . '">';
    }
}

function render_registry_date_filter(string $label, string $note = '', string $target = ''): void
{
    $range = registry_range();
    $today = new DateTimeImmutable('today');
    $end = $today->format('Y-m-d');
    $presets = [
        'This week' => [$today->modify('monday this week')->format('Y-m-d'), $end],
        'This month' => [$today->format('Y-m-01'), $end],
        'This year' => [$today->format('Y-01-01'), $end],
        'Last 12 months' => [$today->modify('first day of this month')->modify('-11 months')->format('Y-m-d'), $end],
        'All dates' => ['', ''],
    ];
    ?>
    <section class="docket-panel mb-3 registry-date-filter" aria-label="<?php echo e($label); ?>">
        <form method="get"<?php echo $target !== '' ? ' action="' . e($target) . '"' : ''; ?> class="row g-3 align-items-end">
            <?php foreach ($_GET as $key => $value): ?>
                <?php if (!is_scalar($value) || in_array($key, ['date_from', 'date_to', 'page', 'before', 'view', 'edit', 'export'], true)) { continue; } ?>
                <input type="hidden" name="<?php echo e((string) $key); ?>" value="<?php echo e((string) $value); ?>">
            <?php endforeach; ?>
            <div class="col-md-5"><label class="form-label" for="registryFrom">From</label><input class="form-control" type="date" id="registryFrom" name="date_from" value="<?php echo e($range['from']); ?>"></div>
            <div class="col-md-5"><label class="form-label" for="registryTo">To</label><input class="form-control" type="date" id="registryTo" name="date_to" value="<?php echo e($range['to']); ?>"></div>
            <div class="col-md-2"><button class="btn btn-certreefy w-100" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button></div>
        </form>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <?php foreach ($presets as $name => [$from, $to]): ?>
                <?php $active = $range['error'] === '' && $range['from'] === $from && $range['to'] === $to; ?>
                <a class="btn btn-sm <?php echo $active ? 'btn-certreefy' : 'btn-outline-secondary'; ?>" href="<?php echo e($target . registry_url(['date_from' => $from, 'date_to' => $to])); ?>"<?php echo $active ? ' aria-current="true"' : ''; ?>><?php echo e($name); ?></a>
            <?php endforeach; ?>
            <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?php echo e($target . registry_url(['date_from' => null, 'date_to' => null, 'summary' => null, 'status' => null, 'classification' => null, 'movement_type' => null])); ?>">Reset</a>
        </div>
        <?php if ($range['error'] !== ''): ?>
            <div class="alert alert-danger mt-3 mb-0" role="alert"><?php echo e($range['error']); ?></div>
        <?php else: ?>
            <p class="small text-secondary mt-3 mb-0"><?php echo e($label); ?>: <?php echo e($range['from'] === '' && $range['to'] === '' ? 'All dates' : ($range['from'] ?: 'Beginning') . ' – ' . ($range['to'] ?: 'Present')); ?><?php echo $note !== '' ? ' · ' . e($note) : ''; ?></p>
        <?php endif; ?>
    </section>
    <?php
}

function render_registry_cards(array $counts, array $labels, array $overrides = []): void
{
    echo '<section class="row g-3 mb-4" aria-label="Registry summary">';
    foreach ($labels as $key => $label) {
        echo '<div class="col-sm-6 col-xl-3"><a class="ledger-card summary-card" ' . registry_card_attributes($key, $overrides) . '>'
            . '<div class="ledger-value tabular">' . (int) ($counts[$key] ?? 0) . '</div>'
            . '<div class="ledger-caption">' . e($label) . '</div></a></div>';
    }
    echo '</section>';
}

function registry_request_predicates(): array
{
    return [
        'pending' => static fn ($r) => in_array($r['current_status'], ['submitted', 'under_review', 'approved'], true),
        'active' => static fn ($r) => in_array($r['current_status'], ['submitted', 'under_review', 'approved', 'ready_for_pickup'], true),
        'ready' => static fn ($r) => $r['current_status'] === 'ready_for_pickup',
        'claimed' => static fn ($r) => $r['current_status'] === 'claimed',
    ];
}

function registry_report_predicates(): array
{
    return [
        'active' => static fn ($r) => $r['current_status'] !== 'resolved',
        'review' => static fn ($r) => in_array($r['current_status'], ['submitted', 'under_review'], true),
        'field' => static fn ($r) => $r['current_status'] === 'field_verification',
        'resolved' => static fn ($r) => $r['current_status'] === 'resolved',
    ];
}
