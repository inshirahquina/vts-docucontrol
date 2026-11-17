
<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if (!isLoggedIn() || ($_SESSION['active_role'] ?? '') !== 'hod') {
    redirect(BASE_URL . 'index.php');
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| 1. Get HOD department
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT department
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$department = $stmt->fetchColumn();

if (!$department) {
    $department = '';
}

/*
|--------------------------------------------------------------------------
| 2. Department file and request statistics
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM files
    WHERE department = ?
");
$stmt->execute([$department]);
$total_files = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_requests,
        COALESCE(SUM(
            CASE WHEN r.current_status = 'Pending HOD Approval'
            THEN 1 ELSE 0 END
        ), 0) AS pending_count,
        COALESCE(SUM(
            CASE WHEN r.current_status = 'Approved by HOD'
            THEN 1 ELSE 0 END
        ), 0) AS approved_count
    FROM requests r
    JOIN users u ON r.user_id = u.id
    WHERE u.hod_id = ?
      AND u.department = ?
");
$stmt->execute([$current_user_id, $department]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$total_requests = (int) ($stats['total_requests'] ?? 0);
$pending_count  = (int) ($stats['pending_count'] ?? 0);
$approved_count = (int) ($stats['approved_count'] ?? 0);

/*
|--------------------------------------------------------------------------
| 3. File status chart
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        COALESCE(status, 'unknown') AS status,
        COUNT(*) AS total
    FROM files
    WHERE department = ?
    GROUP BY status
    ORDER BY status
");
$stmt->execute([$department]);
$file_status_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$file_status_labels = [];
$file_status_values = [];
$status_totals = [];

foreach ($file_status_rows as $row) {
    $label = ucfirst((string) $row['status']);
    $count = (int) $row['total'];

    $file_status_labels[] = $label;
    $file_status_values[] = $count;
    $status_totals[strtolower($label)] = $count;
}

/*
|--------------------------------------------------------------------------
| 4. Search, status filter and pagination
|--------------------------------------------------------------------------
*/
$allowed_page_sizes = [10, 20, 50];

$page_size = (int) ($_GET['page_size'] ?? 10);

if (!in_array($page_size, $allowed_page_sizes, true)) {
    $page_size = 10;
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100);

$selected_status = strtolower(
    trim((string) ($_GET['file_status'] ?? ''))
);

if (
    $selected_status !== '' &&
    !array_key_exists($selected_status, $status_totals)
) {
    $selected_status = '';
}

/*
|--------------------------------------------------------------------------
| 5. Build filtered query
|--------------------------------------------------------------------------
*/
$where = ["department = ?"];
$params = [$department];

if ($selected_status !== '') {
    $where[] = "LOWER(COALESCE(status, 'unknown')) = ?";
    $params[] = $selected_status;
}

if ($search !== '') {
    $where[] = "(
        file_name LIKE ?
        OR allocation LIKE ?
        OR box_no LIKE ?
        OR month_year LIKE ?
        OR retention_period LIKE ?
        OR status LIKE ?
    )";

    $search_term = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_term;
    }
}

$where_sql = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| 6. Count matching files
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM files
    WHERE $where_sql
");
$stmt->execute($params);

$filtered_total = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($filtered_total / $page_size));
$page = min($page, $total_pages);
$offset = ($page - 1) * $page_size;

/*
|--------------------------------------------------------------------------
| 7. Fetch current page only
|--------------------------------------------------------------------------
*/
$file_sql = "
    SELECT
        id,
        file_name,
        allocation,
        box_no,
        month_year,
        retention_period,
        status
    FROM files
    WHERE $where_sql
    ORDER BY file_name ASC
    LIMIT ? OFFSET ?
";

$file_params = $params;
$file_params[] = $page_size;
$file_params[] = $offset;

$stmt = $pdo->prepare($file_sql);
$stmt->execute($file_params);
$department_files = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 8. Recent approval tasks
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.current_status,
        r.created_at,
        u.full_name,
        f.file_name
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN files f ON r.file_id = f.id
    WHERE r.current_status = 'Pending HOD Approval'
      AND u.hod_id = ?
      AND u.department = ?
    ORDER BY r.id DESC
    LIMIT 5
");
$stmt->execute([$current_user_id, $department]);
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 9. URL helper — preserves search, status and page size
|--------------------------------------------------------------------------
*/
$build_url = function (array $overrides = []) use (
    $page,
    $page_size,
    $search,
    $selected_status
) {
    $query = [
        'page' => $page,
        'page_size' => $page_size
    ];

    if ($search !== '') {
        $query['q'] = $search;
    }

    if ($selected_status !== '') {
        $query['file_status'] = $selected_status;
    }

    $query = array_merge($query, $overrides);

    return '?' . http_build_query($query);
};

require_once '../includes/header.php';
?>

<style>
.hod-dashboard {
    --hd-text: #1f2937;
    --hd-muted: #6b7280;
    --hd-border: #e5e7eb;
    --hd-soft: #f8fafc;
    color: var(--hd-text);
}

.hd-heading {
    margin-bottom: 22px;
}

.hd-heading h2 {
    margin: 0 0 6px;
}

.hd-heading p {
    color: var(--hd-muted);
    margin: 0;
}

.hd-card {
    background: #fff;
    border: 1px solid var(--hd-border);
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
}

.hd-title {
    font-size: 18px;
    margin: 0 0 6px;
}

.hd-subtitle {
    color: var(--hd-muted);
    font-size: 13px;
    line-height: 1.6;
    margin: 0;
}

/* Main chart and summary */
.hd-overview {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(290px, .85fr);
    gap: 28px;
    align-items: center;
    margin-top: 24px;
}

.hd-chart {
    width: 100%;
    max-width: 440px;
    margin: auto;
}

.hd-chart canvas {
    cursor: pointer;
}

.hd-summary {
    min-width: 0;
}

.hd-summary-heading {
    display: flex;
    align-items: center;
    gap: 9px;
    font-weight: 650;
    font-size: 16px;
    margin-bottom: 5px;
}

.hd-dot {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background: #64748b;
    flex-shrink: 0;
}

.hd-summary-description {
    font-size: 13px;
    line-height: 1.6;
    color: var(--hd-muted);
    margin: 0 0 18px;
}

.hd-summary-number {
    font-size: 34px;
    font-weight: 750;
    line-height: 1.2;
    margin: 16px 0 6px;
}

.hd-progress {
    height: 7px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 18px;
}

.hd-progress-bar {
    height: 100%;
    width: 0;
    background: #64748b;
    transition: width .25s ease;
}

.hd-percentage {
    color: var(--hd-muted);
    font-size: 12px;
    margin: 8px 0 18px;
}

/* Four compact metrics on the right */
.hd-mini-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 22px;
}

.hd-mini-stat {
    border: 1px solid var(--hd-border);
    border-radius: 9px;
    padding: 13px;
    min-width: 0;
    background: #fff;
}

.hd-mini-label {
    font-size: 12px;
    color: var(--hd-muted);
    line-height: 1.5;
}

.hd-mini-value {
    font-size: 23px;
    font-weight: 700;
    margin-top: 6px;
}

.hd-mini-stat.blue { border-left: 3px solid #3978d4; }
.hd-mini-stat.purple { border-left: 3px solid #8b6bd6; }
.hd-mini-stat.orange { border-left: 3px solid #e9a23b; }
.hd-mini-stat.green { border-left: 3px solid #22a06b; }

.hd-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 9px 13px;
    border-radius: 7px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    text-decoration: none;
    font-size: 13px;
    cursor: pointer;
}

.hd-btn:hover {
    background: #f3f4f6;
}

.hd-btn-primary {
    background: #1f2937;
    color: #fff;
    border-color: #1f2937;
}

.hd-btn-primary:hover {
    background: #374151;
    color: #fff;
}

.hd-chart-hint {
    color: var(--hd-muted);
    font-size: 12px;
    margin: 18px 0 0;
}

/* Search and table toolbar */
.hd-toolbar {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    margin: 22px 0 16px;
}

.hd-search-form {
    display: flex;
    flex: 1;
    gap: 8px;
    min-width: 250px;
}

.hd-search-input {
    flex: 1;
    min-width: 0;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font: inherit;
    font-size: 13px;
}

.hd-search-input:focus,
.hd-select:focus {
    outline: 2px solid #bfdbfe;
    border-color: #3978d4;
}

.hd-page-size {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--hd-muted);
}

.hd-select {
    padding: 9px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    background: #fff;
}

.hd-results-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin: 15px 0;
    font-size: 12px;
    color: var(--hd-muted);
}

.hd-table-wrap {
    overflow-x: auto;
}

.hd-table {
    width: 100%;
    border-collapse: collapse;
}

.hd-table th,
.hd-table td {
    padding: 12px;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid var(--hd-border);
    font-size: 13px;
    overflow-wrap: anywhere;
}

.hd-table th {
    background: #f9fafb;
    color: #4b5563;
    font-weight: 600;
    white-space: nowrap;
}

.hd-table tbody tr:hover {
    background: #fafafa;
}

.hd-badge {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 20px;
    background: #f3f4f6;
    color: #374151;
    font-size: 12px;
    white-space: nowrap;
}

.hd-badge.available { background: #dcfce7; color: #166534; }
.hd-badge.borrowed { background: #fef3c7; color: #92400e; }
.hd-badge.requested { background: #dbeafe; color: #1d4ed8; }
.hd-badge.archived { background: #e5e7eb; color: #374151; }
.hd-badge.lost { background: #fee2e2; color: #991b1b; }

.hd-empty {
    padding: 30px 12px;
    text-align: center;
    color: var(--hd-muted);
}

.hd-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding-top: 18px;
}

.hd-pagination-links {
    display: flex;
    gap: 5px;
    align-items: center;
    flex-wrap: wrap;
}

.hd-page-link {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-width: 35px;
    height: 35px;
    padding: 0 10px;
    border: 1px solid var(--hd-border);
    border-radius: 7px;
    background: #fff;
    color: #374151;
    text-decoration: none;
    font-size: 12px;
}

.hd-page-link.active {
    background: #1f2937;
    color: #fff;
    border-color: #1f2937;
}

.hd-page-link.disabled {
    opacity: .4;
    pointer-events: none;
}

.hd-small-muted {
    font-size: 12px;
    color: var(--hd-muted);
}

@media (max-width: 900px) {
    .hd-overview {
        grid-template-columns: 1fr;
    }

    .hd-chart {
        max-width: 360px;
    }
}

@media (max-width: 520px) {
    .hd-card {
        padding: 15px;
    }

    .hd-search-form {
        min-width: 100%;
    }

    .hd-mini-value {
        font-size: 20px;
    }
}
</style>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="content-area hod-dashboard">

            <div class="hd-heading">
                <h2>Department Dashboard</h2>
                <p>
                    File inventory and approval overview for
                    <strong><?= sanitize($department ?: 'Your Department') ?></strong>
                </p>
            </div>

            <!-- Department overview -->
            <div class="hd-card">
                <h3 class="hd-title">Department Overview</h3>
                <p class="hd-subtitle">
                    Explore file status and monitor your department's requests.
                </p>

                <div class="hd-overview">

                    <!-- Left: interactive pie chart -->
                    <div class="hd-chart">
                        <?php if ($total_files > 0): ?>
                            <canvas id="fileStatusChart"></canvas>
                        <?php else: ?>
                            <div class="hd-empty">
                                No files registered in this department.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: dynamic summary -->
                    <div class="hd-summary">

                        <div class="hd-summary-heading">
                            <span class="hd-dot" id="hdStatusDot"></span>
                            <span id="hdStatusLabel">Department Summary</span>
                        </div>

                        <div class="hd-summary-number" id="hdStatusCount">
                            <?= number_format($total_files) ?>
                        </div>

                        <p class="hd-summary-description" id="hdStatusDescription">
                            Total files registered in your department.
                            Select a chart segment to explore a specific status.
                        </p>

                        <div id="hdStatusProgressSection" hidden>
                            <div class="hd-progress">
                                <div class="hd-progress-bar" id="hdProgressBar"></div>
                            </div>
                            <p class="hd-percentage" id="hdStatusPercentage"></p>
                        </div>

                        <button
                            type="button"
                            class="hd-btn"
                            id="hdViewAllBtn"
                            style="display:none">
                            View All Files
                        </button>

                        <div class="hd-mini-stats" id="hdMiniStats">

                            <div class="hd-mini-stat blue">
                                <div class="hd-mini-label">Total Files</div>
                                <div class="hd-mini-value">
                                    <?= number_format($total_files) ?>
                                </div>
                            </div>

                            <div class="hd-mini-stat purple">
                                <div class="hd-mini-label">Total Requests</div>
                                <div class="hd-mini-value">
                                    <?= number_format($total_requests) ?>
                                </div>
                            </div>

                            <div class="hd-mini-stat orange">
                                <div class="hd-mini-label">Pending Approval</div>
                                <div class="hd-mini-value">
                                    <?= number_format($pending_count) ?>
                                </div>
                            </div>

                            <div class="hd-mini-stat green">
                                <div class="hd-mini-label">Approved Requests</div>
                                <div class="hd-mini-value">
                                    <?= number_format($approved_count) ?>
                                </div>
                            </div>

                        </div>

                    </div>
                </div>

                <p class="hd-chart-hint">
                    Click a slice or legend item to filter the file list by status.
                </p>
            </div>

            <!-- File inventory and search -->
            <div class="hd-card">

                <h3 class="hd-title">Department File Inventory</h3>
                <p class="hd-subtitle">
                    Search and browse files belonging to your department.
                </p>

                <form method="get" action="" class="hd-toolbar">

                    <div class="hd-search-form">
                        <input
                            type="search"
                            class="hd-search-input"
                            name="q"
                            value="<?= htmlspecialchars(
                                $search,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            placeholder="Search file name, allocation, box no..."
                            aria-label="Search department files">

                        <?php if ($selected_status !== ''): ?>
                            <input
                                type="hidden"
                                name="file_status"
                                value="<?= htmlspecialchars(
                                    $selected_status,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>">
                        <?php endif; ?>

                        <input type="hidden" name="page" value="1">
                        <input
                            type="hidden"
                            name="page_size"
                            value="<?= $page_size ?>">

                        <button type="submit" class="hd-btn hd-btn-primary">
                            Search
                        </button>

                        <?php if ($search !== ''): ?>
                            <a
                                class="hd-btn"
                                href="<?= htmlspecialchars(
                                    $build_url(['q' => '', 'page' => 1]),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>">
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="hd-page-size">
                        <label for="hdPageSize">Rows per page</label>

                        <select
                            id="hdPageSize"
                            class="hd-select"
                            name="page_size"
                            onchange="this.form.submit()">

                            <?php foreach ([10, 20, 50] as $size): ?>
                                <option
                                    value="<?= $size ?>"
                                    <?= $page_size === $size ? 'selected' : '' ?>>
                                    <?= $size ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </form>

                <div class="hd-results-info">
                    <span>
                        <?php if ($selected_status !== ''): ?>
                            Status: <strong><?= sanitize(ucfirst($selected_status)) ?></strong>
                        <?php else: ?>
                            All file statuses
                        <?php endif; ?>

                        <?php if ($search !== ''): ?>
                            &middot; Search:
                            <strong><?= sanitize($search) ?></strong>
                        <?php endif; ?>
                    </span>

                    <span>
                        <?= number_format($filtered_total) ?>
                        matching file<?= $filtered_total === 1 ? '' : 's' ?>
                    </span>
                </div>

                <div class="hd-table-wrap">
                    <table class="hd-table">
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Allocation</th>
                                <th>Box No.</th>
                                <th>Month / Year</th>
                                <th>Retention Period</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($department_files)): ?>

                                <tr>
                                    <td colspan="6" class="hd-empty">
                                        No matching files found.
                                        Try a different search term or clear the filters.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($department_files as $file): ?>
                                    <?php
                                    $file_status = strtolower(
                                        (string) ($file['status'] ?? 'unknown')
                                    );

                                    $badge_class = in_array(
                                        $file_status,
                                        ['available', 'borrowed', 'requested', 'archived', 'lost'],
                                        true
                                    ) ? $file_status : '';
                                    ?>

                                    <tr>
                                        <td>
                                            <?= sanitize($file['file_name'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <?= sanitize($file['allocation'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <?= sanitize($file['box_no'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <?= sanitize($file['month_year'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <?= sanitize($file['retention_period'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <span class="hd-badge <?= $badge_class ?>">
                                                <?= sanitize(ucfirst($file_status)) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php
                $start_record = $filtered_total > 0 ? $offset + 1 : 0;
                $end_record = min($offset + $page_size, $filtered_total);
                ?>

                <div class="hd-pagination">

                    <div class="hd-small-muted">
                        Showing <?= number_format($start_record) ?>–<?= number_format($end_record) ?>
                        of <?= number_format($filtered_total) ?> files
                    </div>

                    <div class="hd-pagination-links">

                        <a
                            class="hd-page-link <?= $page <= 1 ? 'disabled' : '' ?>"
                            href="<?= htmlspecialchars(
                                $build_url(['page' => max(1, $page - 1)]),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>">
                            Previous
                        </a>

                        <?php
                        $first_page = max(1, $page - 2);
                        $last_page = min($total_pages, $page + 2);
                        ?>

                        <?php if ($first_page > 1): ?>
                            <a
                                class="hd-page-link"
                                href="<?= htmlspecialchars(
                                    $build_url(['page' => 1]),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>">1</a>

                            <?php if ($first_page > 2): ?>
                                <span class="hd-small-muted">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $first_page; $p <= $last_page; $p++): ?>
                            <a
                                class="hd-page-link <?= $p === $page ? 'active' : '' ?>"
                                <?= $p === $page ? 'aria-current="page"' : '' ?>
                                href="<?= htmlspecialchars(
                                    $build_url(['page' => $p]),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($last_page < $total_pages): ?>
                            <?php if ($last_page < $total_pages - 1): ?>
                                <span class="hd-small-muted">...</span>
                            <?php endif; ?>

                            <a
                                class="hd-page-link"
                                href="<?= htmlspecialchars(
                                    $build_url(['page' => $total_pages]),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>">
                                <?= $total_pages ?>
                            </a>
                        <?php endif; ?>

                        <a
                            class="hd-page-link <?= $page >= $total_pages ? 'disabled' : '' ?>"
                            href="<?= htmlspecialchars(
                                $build_url(['page' => min($total_pages, $page + 1)]),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>">
                            Next
                        </a>

                    </div>
                </div>

            </div>

            <!-- Recent approval tasks -->
            <div class="hd-card">

                <h3 class="hd-title">Recent Approval Tasks</h3>
                <p class="hd-subtitle">
                    The latest requests waiting for your review.
                </p>

                <?php if (empty($recent)): ?>

                    <div class="hd-empty">No pending approvals.</div>

                <?php else: ?>

                    <div class="hd-table-wrap">
                        <table class="hd-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Staff</th>
                                    <th>File</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($recent as $r): ?>
                                    <tr>
                                        <td>#<?= (int) $r['id'] ?></td>
                                        <td><?= sanitize($r['full_name']) ?></td>
                                        <td><?= sanitize($r['file_name']) ?></td>
                                        <td>
                                            <span class="hd-badge">
                                                <?= sanitize($r['current_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="approvals.php" class="hd-btn">
                                                Review
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

            </div>

        </div>
    </main>
</div>

<?php if ($total_files > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const statusLabels = <?= json_encode(
    $file_status_labels,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const statusValues = <?= json_encode($file_status_values) ?>;
const currentStatus = <?= json_encode(
    $selected_status,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const chartColors = [
    '#22a06b',
    '#e9a23b',
    '#3978d4',
    '#7c8494',
    '#dc5151',
    '#8b6bd6'
];

const descriptions = {
    available: 'Files currently available for staff to request or use.',
    borrowed: 'Files currently recorded as borrowed.',
    requested: 'Files currently marked as requested in the inventory.',
    archived: 'Files archived and retained for record-keeping.',
    lost: 'Files marked as lost in the inventory.',
    unknown: 'Files without a recorded status.'
};

const total = statusValues.reduce((sum, value) => sum + value, 0);

const dot = document.getElementById('hdStatusDot');
const labelElement = document.getElementById('hdStatusLabel');
const countElement = document.getElementById('hdStatusCount');
const descriptionElement = document.getElementById('hdStatusDescription');
const progressSection = document.getElementById('hdStatusProgressSection');
const progressBar = document.getElementById('hdProgressBar');
const percentageElement = document.getElementById('hdStatusPercentage');
const miniStats = document.getElementById('hdMiniStats');
const viewAllButton = document.getElementById('hdViewAllBtn');

function getStatusColor(index) {
    return chartColors[index % chartColors.length];
}

function updateSummary(index) {
    if (index === null) {
        labelElement.textContent = 'Department Summary';
        countElement.textContent = total.toLocaleString();

        descriptionElement.textContent =
            'Total files registered in your department. Select a chart segment to explore a specific status.';

        dot.style.background = '#64748b';
        progressSection.hidden = true;
        miniStats.style.display = 'grid';
        viewAllButton.style.display = 'none';
        return;
    }

    const label = statusLabels[index];
    const count = statusValues[index];
    const key = label.toLowerCase();
    const percentage = total > 0 ? count / total * 100 : 0;
    const color = getStatusColor(index);

    labelElement.textContent = label + ' Files';
    countElement.textContent = count.toLocaleString();

    descriptionElement.textContent =
        descriptions[key] ||
        'Files currently recorded under this status.';

    dot.style.background = color;
    progressBar.style.background = color;
    progressBar.style.width = percentage + '%';

    percentageElement.textContent =
        percentage.toFixed(1) + '% of department files';

    progressSection.hidden = false;
    miniStats.style.display = 'none';
    viewAllButton.style.display = 'inline-flex';
}

function navigateToStatus(index) {
    const params = new URLSearchParams(window.location.search);

    params.set('page', '1');

    if (index === null) {
        params.delete('file_status');
    } else {
        params.set('file_status', statusLabels[index].toLowerCase());
    }

    window.location.search = params.toString();
}

const chart = new Chart(document.getElementById('fileStatusChart'), {
    type: 'pie',

    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues,
            backgroundColor: statusLabels.map((_, index) =>
                getStatusColor(index)
            ),
            borderColor: '#ffffff',
            borderWidth: 2,
            hoverOffset: 8
        }]
    },

    options: {
        responsive: true,

        onClick: function(event, elements) {
            if (elements.length) {
                navigateToStatus(elements[0].index);
            }
        },

        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    padding: 18
                },
                onClick: function(event, legendItem) {
                    if (typeof legendItem.index === 'number') {
                        navigateToStatus(legendItem.index);
                    }
                }
            },

            tooltip: {
                callbacks: {
                    label: function(context) {
                        const count = context.raw;
                        const percentage = total > 0
                            ? (count / total * 100).toFixed(1)
                            : 0;

                        return context.label + ': ' +
                            count.toLocaleString() +
                            ' files (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

const selectedIndex = statusLabels.findIndex(
    label => label.toLowerCase() === currentStatus
);

updateSummary(selectedIndex >= 0 ? selectedIndex : null);

viewAllButton.addEventListener('click', function() {
    navigateToStatus(null);
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
