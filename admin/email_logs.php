
<?php
// =====================================================
// 1. INITIALIZATION
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once '../config/functions.php';

// Redirect Operations users
if (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'operations') {
    header("Location: ../operations/dashboard.php");
    exit();
}

// Admin access protection
if (!isLoggedIn() || !isAdmin()) {
    header("Location: ../index.php");
    exit();
}


// =====================================================
// 2. HELPER
// =====================================================

function emailLogEscape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}


// =====================================================
// 3. FILTERS
// =====================================================

$search = trim($_GET['search'] ?? '');
$status = strtoupper(trim($_GET['status'] ?? ''));
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$allowed_statuses = ['SUCCESS', 'FAILED'];

if (!in_array($status, $allowed_statuses, true)) {
    $status = '';
}

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ||
    !strtotime($date_from)) {
    $date_from = '';
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) ||
    !strtotime($date_to)) {
    $date_to = '';
}

if ($date_from && $date_to && $date_from > $date_to) {
    $date_to = '';
}


// =====================================================
// 4. SUMMARY STATISTICS
// =====================================================

$total_emails = (int)$pdo->query("
    SELECT COUNT(*) FROM email_logs
")->fetchColumn();

$total_success = (int)$pdo->query("
    SELECT COUNT(*)
    FROM email_logs
    WHERE status = 'SUCCESS'
")->fetchColumn();

$total_failed = (int)$pdo->query("
    SELECT COUNT(*)
    FROM email_logs
    WHERE status = 'FAILED'
")->fetchColumn();


// =====================================================
// 5. BUILD FILTER QUERY
// =====================================================

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(recipient LIKE :search_recipient OR subject LIKE :search_subject)";

    $params[':search_recipient'] = '%' . $search . '%';
    $params[':search_subject'] = '%' . $search . '%';
}

if ($status !== '') {
    $where[] = "status = :status";
    $params[':status'] = $status;
}

if ($date_from !== '') {
    $where[] = "sent_at >= :date_from";
    $params[':date_from'] = $date_from . ' 00:00:00';
}

if ($date_to !== '') {
    $where[] = "sent_at <= :date_to";
    $params[':date_to'] = $date_to . ' 23:59:59';
}

$where_sql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';


// =====================================================
// 6. PAGINATION
// =====================================================

$per_page = 25;

$page = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM email_logs
    $where_sql
");

$countStmt->execute($params);

$filtered_total = (int)$countStmt->fetchColumn();

$total_pages = max(1, (int)ceil($filtered_total / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;


// =====================================================
// 7. FETCH EMAIL LOGS
// =====================================================

$sql = "
    SELECT
        id,
        recipient,
        subject,
        email_type,
        status,
        error_message,
        sent_at
    FROM email_logs
    $where_sql
    ORDER BY sent_at DESC, id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();

$email_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);


// =====================================================
// 8. PAGINATION URL
// =====================================================

function emailLogsPageUrl($page) {

    $params = $_GET;
    $params['page'] = $page;

    return '?' . http_build_query($params);
}

require_once '../includes/header.php';

?>

<div class="layout-wrapper">

    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">

            <!-- PAGE HEADER -->
            <div class="email-page-header">
                <div>
                    <h2>Email Activity Logs</h2>
                    <p>Monitor outgoing email notifications and delivery attempts.</p>
                </div>

                <div class="email-header-icon">
                    <i class="fas fa-envelope"></i>
                </div>
            </div>


            <!-- SUMMARY CARDS -->
            <div class="email-stats-grid">

                <div class="email-stat-card">
                    <div class="email-stat-icon total-icon">
                        <i class="fas fa-envelope"></i>
                    </div>

                    <div>
                        <div class="email-stat-label">Total Emails</div>
                        <div class="email-stat-value">
                            <?= number_format($total_emails) ?>
                        </div>
                        <div class="email-stat-description">All recorded email attempts</div>
                    </div>
                </div>


                <div class="email-stat-card">
                    <div class="email-stat-icon success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>

                    <div>
                        <div class="email-stat-label">Successful</div>
                        <div class="email-stat-value">
                            <?= number_format($total_success) ?>
                        </div>
                        <div class="email-stat-description">Accepted by SMTP server</div>
                    </div>
                </div>


                <div class="email-stat-card">
                    <div class="email-stat-icon failed-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>

                    <div>
                        <div class="email-stat-label">Failed</div>
                        <div class="email-stat-value">
                            <?= number_format($total_failed) ?>
                        </div>
                        <div class="email-stat-description">Failed email attempts</div>
                    </div>
                </div>

            </div>


            <!-- EMAIL LOGS CARD -->
            <div class="email-logs-card">

                <div class="email-logs-heading">
                    <div>
                        <h3>Email History</h3>
                        <p>
                            <?= number_format($filtered_total) ?>
                            record(s) found
                        </p>
                    </div>

                    <div class="email-refresh">
                        <a href="email_logs.php" title="Reset filters">
                            <i class="fas fa-sync-alt"></i> Reset
                        </a>
                    </div>
                </div>


                <!-- FILTER FORM -->
                <form method="GET" class="email-filter-form">

                    <div class="email-filter-search">
                        <label>Search</label>

                        <div class="email-search-box">
                            <i class="fas fa-search"></i>

                            <input
                                type="text"
                                name="search"
                                placeholder="Recipient or subject..."
                                value="<?= emailLogEscape($search) ?>"
                            >
                        </div>
                    </div>


                    <div class="email-filter-field">
                        <label>Status</label>

                        <select name="status">
                            <option value="">All Status</option>
                            <option value="SUCCESS" <?= $status === 'SUCCESS' ? 'selected' : '' ?>>
                                Success
                            </option>
                            <option value="FAILED" <?= $status === 'FAILED' ? 'selected' : '' ?>>
                                Failed
                            </option>
                        </select>
                    </div>


                    <div class="email-filter-field">
                        <label>From Date</label>

                        <input
                            type="date"
                            name="date_from"
                            value="<?= emailLogEscape($date_from) ?>"
                        >
                    </div>


                    <div class="email-filter-field">
                        <label>To Date</label>

                        <input
                            type="date"
                            name="date_to"
                            value="<?= emailLogEscape($date_to) ?>"
                        >
                    </div>


                    <div class="email-filter-button">
                        <button type="submit">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>

                </form>


                <!-- TABLE -->
                <div class="email-table-wrapper">

                    <table class="email-logs-table">

                        <thead>
                            <tr>
                                <th width="60">ID</th>
                                <th>Recipient</th>
                                <th>Subject</th>
                                <th>Email Type</th>
                                <th>Status</th>
                                <th>Date & Time</th>
                                <th>Details</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (!empty($email_logs)): ?>

                                <?php foreach ($email_logs as $log): ?>

                                    <tr>

                                        <td class="email-id">
                                            #<?= (int)$log['id'] ?>
                                        </td>


                                        <td class="email-recipient">
                                            <?= emailLogEscape($log['recipient']) ?>
                                        </td>


                                        <td class="email-subject">
                                            <?= emailLogEscape($log['subject']) ?>
                                        </td>


                                        <td>
                                            <span class="email-type">
                                                <?= emailLogEscape($log['email_type'] ?: 'General') ?>
                                            </span>
                                        </td>


                                        <td>

                                            <?php if ($log['status'] === 'SUCCESS'): ?>

                                                <span class="email-status success">
                                                    <i class="fas fa-check-circle"></i>
                                                    SUCCESS
                                                </span>

                                            <?php else: ?>

                                                <span class="email-status failed">
                                                    <i class="fas fa-times-circle"></i>
                                                    FAILED
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <td class="email-date">
                                            <?= date('d M Y', strtotime($log['sent_at'])) ?>
                                            <small>
                                                <?= date('h:i:s A', strtotime($log['sent_at'])) ?>
                                            </small>
                                        </td>


                                        <td>

                                            <?php if ($log['status'] === 'FAILED' && !empty($log['error_message'])): ?>

                                                <details class="email-error-details">

                                                    <summary>
                                                        View Error
                                                    </summary>

                                                    <div class="email-error-message">
                                                        <?= emailLogEscape($log['error_message']) ?>
                                                    </div>

                                                </details>

                                            <?php else: ?>

                                                <span class="email-no-error">—</span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="7" class="email-empty">

                                        <i class="fas fa-inbox"></i>

                                        <strong>No email records found</strong>

                                        <span>
                                            Try changing your search or filter criteria.
                                        </span>

                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>


                <!-- PAGINATION -->
                <?php if ($total_pages > 1): ?>

                    <div class="email-pagination">

                        <div class="email-pagination-info">
                            Showing
                            <?= number_format($offset + 1) ?> -
                            <?= number_format(min($offset + $per_page, $filtered_total)) ?>
                            of <?= number_format($filtered_total) ?> records
                        </div>


                        <div class="email-pagination-buttons">

                            <?php if ($page > 1): ?>

                                <a href="<?= emailLogsPageUrl($page - 1) ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>

                            <?php else: ?>

                                <span class="disabled">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </span>

                            <?php endif; ?>


                            <span class="email-page-number">
                                Page <?= $page ?> of <?= $total_pages ?>
                            </span>


                            <?php if ($page < $total_pages): ?>

                                <a href="<?= emailLogsPageUrl($page + 1) ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>

                            <?php else: ?>

                                <span class="disabled">
                                    Next <i class="fas fa-chevron-right"></i>
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endif; ?>


            </div>

        </div>
    </div>

</div>


<style>

/* =====================================================
   EMAIL ACTIVITY LOGS
===================================================== */

.email-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.email-page-header h2 {
    margin: 0;
    color: var(--text-dark, #1e293b);
    font-size: 1.5rem;
}

.email-page-header p {
    color: var(--text-light, #64748b);
    margin: 6px 0 0;
    font-size: 0.9rem;
}

.email-header-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}


/* SUMMARY */

.email-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
    margin-bottom: 25px;
}

.email-stat-card {
    background: #fff;
    padding: 20px;
    border-radius: 9px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 15px;
    border: 1px solid #f1f5f9;
}

.email-stat-icon {
    width: 46px;
    height: 46px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}

.total-icon {
    background: #eff6ff;
    color: #2563eb;
}

.success-icon {
    background: #ecfdf5;
    color: #059669;
}

.failed-icon {
    background: #fef2f2;
    color: #dc2626;
}

.email-stat-label {
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}

.email-stat-value {
    color: #1e293b;
    font-size: 1.8rem;
    font-weight: 700;
    margin: 3px 0;
}

.email-stat-description {
    font-size: 0.75rem;
    color: #94a3b8;
}


/* LOGS CARD */

.email-logs-card {
    background: #fff;
    border-radius: 9px;
    padding: 22px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    border: 1px solid #f1f5f9;
}

.email-logs-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.email-logs-heading h3 {
    margin: 0;
    color: #1e293b;
    font-size: 1.05rem;
}

.email-logs-heading p {
    color: #94a3b8;
    font-size: 0.8rem;
    margin: 5px 0 0;
}

.email-refresh a {
    color: #2563eb;
    font-size: 0.85rem;
    text-decoration: none;
    font-weight: 500;
}


/* FILTERS */

.email-filter-form {
    display: grid;
    grid-template-columns: minmax(200px, 1.7fr) repeat(3, minmax(125px, 1fr)) auto;
    gap: 12px;
    align-items: end;
    padding: 16px;
    margin-bottom: 20px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}

.email-filter-form label {
    display: block;
    font-size: 0.78rem;
    color: #64748b;
    font-weight: 600;
    margin-bottom: 7px;
}

.email-filter-form input,
.email-filter-form select {
    width: 100%;
    height: 39px;
    padding: 8px 10px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    background: #fff;
    color: #334155;
    font-size: 0.82rem;
    outline: none;
    box-sizing: border-box;
}

.email-filter-form input:focus,
.email-filter-form select:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.email-search-box {
    position: relative;
}

.email-search-box i {
    position: absolute;
    left: 11px;
    top: 12px;
    color: #94a3b8;
    font-size: 0.85rem;
}

.email-search-box input {
    padding-left: 32px;
}

.email-filter-button button {
    height: 39px;
    padding: 0 16px;
    border: none;
    border-radius: 7px;
    background: #2563eb;
    color: #fff;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}

.email-filter-button button:hover {
    background: #1d4ed8;
}


/* TABLE */

.email-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.email-logs-table {
    width: 100%;
    min-width: 950px;
    border-collapse: collapse;
}

.email-logs-table th {
    text-align: left;
    padding: 13px 12px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 600;
    white-space: nowrap;
}

.email-logs-table td {
    padding: 14px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #475569;
    font-size: 0.82rem;
    vertical-align: middle;
}

.email-logs-table tbody tr:hover {
    background: #fafcff;
}

.email-id {
    color: #94a3b8 !important;
    font-weight: 600;
}

.email-recipient {
    color: #334155 !important;
    font-weight: 500;
}

.email-subject {
    max-width: 220px;
    overflow-wrap: anywhere;
}

.email-type {
    display: inline-block;
    padding: 4px 8px;
    background: #f1f5f9;
    color: #64748b;
    border-radius: 5px;
    font-size: 0.72rem;
    white-space: nowrap;
}

.email-date {
    white-space: nowrap;
}

.email-date small {
    display: block;
    margin-top: 4px;
    color: #94a3b8;
    font-size: 0.72rem;
}


/* STATUS BADGES */

.email-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    white-space: nowrap;
}

.email-status.success {
    color: #059669;
    background: #ecfdf5;
}

.email-status.failed {
    color: #dc2626;
    background: #fef2f2;
}


/* ERROR DETAILS */

.email-error-details summary {
    color: #dc2626;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}

.email-error-message {
    margin-top: 8px;
    padding: 10px;
    max-width: 300px;
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
    border-radius: 6px;
    font-size: 0.75rem;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.email-no-error {
    color: #cbd5e1;
}


/* EMPTY STATE */

.email-empty {
    text-align: center !important;
    padding: 45px 15px !important;
}

.email-empty i {
    display: block;
    font-size: 2rem;
    color: #cbd5e1;
    margin-bottom: 12px;
}

.email-empty strong {
    display: block;
    color: #475569;
    font-size: 0.9rem;
}

.email-empty span {
    display: block;
    color: #94a3b8;
    font-size: 0.8rem;
    margin-top: 5px;
}


/* PAGINATION */

.email-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.email-pagination-info {
    color: #94a3b8;
    font-size: 0.8rem;
}

.email-pagination-buttons {
    display: flex;
    align-items: center;
    gap: 10px;
}

.email-pagination-buttons a,
.email-pagination-buttons span.disabled {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    text-decoration: none;
    color: #475569;
    background: #fff;
    font-size: 0.8rem;
}

.email-pagination-buttons a:hover {
    border-color: #93c5fd;
    color: #2563eb;
}

.email-pagination-buttons span.disabled {
    color: #cbd5e1;
    cursor: not-allowed;
}

.email-page-number {
    border: none !important;
    font-weight: 600;
    color: #64748b !important;
    white-space: nowrap;
}


/* RESPONSIVE */

@media (max-width: 1100px) {
    .email-filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .email-filter-search {
        grid-column: span 2;
    }
}

@media (max-width: 600px) {
    .email-logs-card {
        padding: 14px;
    }

    .email-filter-form {
        grid-template-columns: 1fr;
    }

    .email-filter-search {
        grid-column: auto;
    }

    .email-filter-button button {
        width: 100%;
    }

    .email-pagination {
        align-items: flex-start;
        flex-direction: column;
    }

    .email-pagination-buttons {
        width: 100%;
        justify-content: space-between;
    }
}

</style>


<?php require_once '../includes/footer.php'; ?>
