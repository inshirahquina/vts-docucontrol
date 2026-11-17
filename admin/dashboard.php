<?php

session_start();

require_once '../config/db.php';

require_once '../config/functions.php';



// --- LOGIC TO HANDLE ROLE SWITCHING ---

if (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'operations') {

    header("Location: ../operations/dashboard.php");

    exit();

}



require_once '../includes/header.php'; 



// 1. TOTAL FILES

 $total_files = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();



// 2. OVERDUE FILES

 $overdue_files = $pdo->query("SELECT COUNT(*) FROM requests WHERE current_status = 'Released' AND due_date < CURDATE()")->fetchColumn();



// 3. PENDING ADMIN APPROVALS 

 $pending_approvals = $pdo->query("SELECT COUNT(*) FROM requests WHERE current_status IN ('Requested', 'Return Requested')")->fetchColumn();



// 4. PENDING RETURN 

 $pending_return = $pdo->query("SELECT COUNT(*) FROM requests WHERE current_status = 'Released'")->fetchColumn();



// 5. RECENT REQUESTS 

 $recent_req = $pdo->query("SELECT r.id, r.current_status, r.borrow_date, f.file_name, u.full_name 

                           FROM requests r 

                           JOIN files f ON r.file_id = f.id 

                           JOIN users u ON r.user_id = u.id 

                           WHERE r.current_status != 'Completed' AND r.current_status != 'Cancelled'

                           ORDER BY r.borrow_date DESC LIMIT 5");



$selected_month = $_GET['month'] ?? date('m');

$selected_year  = $_GET['year'] ?? date('Y');

$summary_view = $_GET['summary_view'] ?? 'requests';

if (!in_array($summary_view, ['requests', 'availability'], true)) {

    $summary_view = 'requests';

}



$dept_summary = $pdo->prepare("
    SELECT
        d.department,
        COALESCE(r.total_requests, 0) AS total_requests,
        COALESCE(f.total_files, 0) AS total_files
    FROM (
        SELECT department
        FROM users
        WHERE department IS NOT NULL AND TRIM(department) != ''

        UNION

        SELECT department
        FROM files
        WHERE department IS NOT NULL AND TRIM(department) != ''
    ) AS d
    LEFT JOIN (
        SELECT
            u.department,
            COUNT(r.id) AS total_requests
        FROM requests r
        JOIN users u ON r.user_id = u.id
        WHERE MONTH(r.borrow_date) = ?
          AND YEAR(r.borrow_date) = ?
        GROUP BY u.department
    ) AS r ON r.department = d.department
    LEFT JOIN (
        SELECT
            department,
            COUNT(*) AS total_files
        FROM files
        WHERE file_category = 'Departmental Files'
          AND department IS NOT NULL
          AND TRIM(department) != ''
        GROUP BY department
    ) AS f ON f.department = d.department
    WHERE COALESCE(r.total_requests, 0) > 0
    ORDER BY total_requests DESC, d.department ASC
");
$dept_summary->execute([$selected_month, $selected_year]);
$dept_summary_rows = $dept_summary->fetchAll(PDO::FETCH_ASSOC);

$total_request = $pdo->prepare("

    SELECT COUNT(*) 

    FROM requests

    WHERE MONTH(borrow_date) = ?

    AND YEAR(borrow_date) = ?

");

$total_request->execute([$selected_month, $selected_year]);

$total_requests = $total_request->fetchColumn();



// FILE AVAILABILITY — category → department → files

$availability_per_page = 20;

$availability_page = isset($_GET['availability_page'])

    ? max(1, (int) $_GET['availability_page'])

    : 1;

$availability_category = trim((string) ($_GET['availability_category'] ?? ''));

$availability_department = trim((string) ($_GET['availability_department'] ?? ''));

$availability_level = 'category';

$availability_categories = [];

$availability_by_dept = [];

$availability_files = [];

$total_available_departments = 0;

$total_availability_pages = 1;

$availability_offset = 0;



$buildAvailabilityQuery = static function (array $overrides = []): string {

    $params = $_GET;

    $params['summary_view'] = 'availability';

    foreach ($overrides as $key => $value) {

        if ($value === null || $value === '') {

            unset($params[$key]);

        } else {

            $params[$key] = $value;

        }

    }

    return '?' . http_build_query($params);

};



if ($summary_view === 'availability') {

    $availability_categories = $pdo->query("

        SELECT

            file_category,

            COUNT(*) AS total_files,

            SUM(CASE WHEN status = 'borrowed' THEN 1 ELSE 0 END) AS borrowed_files,

            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_files

        FROM files

        WHERE file_category IS NOT NULL

          AND file_category != ''

        GROUP BY file_category

        ORDER BY

            CASE file_category

                WHEN 'Purchaser File' THEN 1

                WHEN 'Departmental Files' THEN 2

                ELSE 3

            END,

            file_category ASC

    ")->fetchAll(PDO::FETCH_ASSOC);



    $valid_categories = array_column($availability_categories, 'file_category');

    if ($availability_category === '' || !in_array($availability_category, $valid_categories, true)) {

        $availability_category = '';

        $availability_department = '';

        $availability_level = 'category';

    } else {

        $deptExists = false;

        if ($availability_department !== '') {

            $deptCheck = $pdo->prepare("

                SELECT COUNT(*)

                FROM files

                WHERE file_category = ?

                  AND department = ?

            ");

            $deptCheck->execute([$availability_category, $availability_department]);

            $deptExists = ((int) $deptCheck->fetchColumn()) > 0;

        }



        if ($deptExists) {

            $availability_level = 'files';

            $filesStmt = $pdo->prepare("

                SELECT

                    id,

                    file_name,

                    department,

                    allocation,

                    box_no,

                    month_year,

                    status

                FROM files

                WHERE file_category = ?

                  AND department = ?

                ORDER BY file_name ASC

            ");

            $filesStmt->execute([$availability_category, $availability_department]);

            $availability_files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

        } else {

            $availability_department = '';

            $availability_level = 'department';



            $countStmt = $pdo->prepare("

                SELECT COUNT(DISTINCT department)

                FROM files

                WHERE file_category = ?

                  AND department IS NOT NULL

                  AND department != ''

            ");

            $countStmt->execute([$availability_category]);

            $total_available_departments = (int) $countStmt->fetchColumn();



            $total_availability_pages = max(1, (int) ceil($total_available_departments / $availability_per_page));

            if ($availability_page > $total_availability_pages) {

                $availability_page = $total_availability_pages;

            }

            $availability_offset = ($availability_page - 1) * $availability_per_page;



            $deptStmt = $pdo->prepare("

                SELECT

                    department,

                    COUNT(*) AS total_files,

                    SUM(CASE WHEN status = 'borrowed' THEN 1 ELSE 0 END) AS borrowed_files,

                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_files

                FROM files

                WHERE file_category = :category

                  AND department IS NOT NULL

                  AND department != ''

                GROUP BY department

                ORDER BY department ASC

                LIMIT :limit OFFSET :offset

            ");

            $deptStmt->bindValue(':category', $availability_category, PDO::PARAM_STR);

            $deptStmt->bindValue(':limit', $availability_per_page, PDO::PARAM_INT);

            $deptStmt->bindValue(':offset', $availability_offset, PDO::PARAM_INT);

            $deptStmt->execute();

            $availability_by_dept = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

        }

    }

}

?>



<div class="layout-wrapper">

    <?php require_once '../includes/sidebar.php'; ?>



    <div class="main-content">

        <div class="content-area">



            <!-- Header -->

            <div style="margin-bottom: 25px;">

                <h2 style="margin:0;">Admin Dashboard</h2>

                <p style="color:var(--text-light); margin:5px 0 0 0;">Overview of repository status and activities.</p>

            </div>



            <!-- Stats Grid -->

            <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px;">



                <!-- Total Archive -->

                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid var(--accent);">

                    <div style="color: var(--text-light); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Total Archive</div>

                    <div class="value" style="font-size: 2rem; font-weight: bold; color: var(--text-dark); margin: 5px 0;"><?= number_format($total_files) ?></div>

                    <div style="font-size: 0.8rem; color: #888;">Documents in system</div>

                </div>



                <!-- Overdue Files -->

                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #dc2626;">

                    <div style="color: var(--text-light); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Overdue Files</div>

                    <div class="value" style="font-size: 2rem; font-weight: bold; color: var(--text-dark); margin: 5px 0;"><?= number_format($overdue_files) ?></div>

                    <div style="font-size: 0.8rem; color: #888;">Past due date</div>

                </div>



                <!-- Pending Approval -->

                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #ef4444;">

                    <div style="color: var(--text-light); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Needs Action</div>

                    <div class="value" style="font-size: 2rem; font-weight: bold; color: var(--text-dark); margin: 5px 0;"><?= number_format($pending_approvals) ?></div>

                    <div style="font-size: 0.8rem; color: #888;">Awaiting assignment</div>

                </div>



                <!-- Pending Return -->

                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #8b5cf6;">

                    <div style="color: var(--text-light); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Pending Return</div>

                    <div class="value" style="font-size: 2rem; font-weight: bold; color: var(--text-dark); margin: 5px 0;"><?= number_format($pending_return) ?></div>

                    <div style="font-size: 0.8rem; color: #888;">Files with requestors</div>

                </div>

            </div>

            <div class="card dept-summary-card" style="margin-top:20px; background:white; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.05); padding:20px;">



                <div class="dept-summary-toolbar">

                    <h3 class="dept-summary-title" id="deptSummaryTitle">

                        <?= $summary_view === 'availability' ? 'File Availability' : 'Department Request Summary' ?>

                    </h3>



                    <div class="dept-summary-controls">

                        <div class="dept-summary-segment" role="tablist" aria-label="Summary view">

                            <a href="?<?= http_build_query(array_merge($_GET, ['summary_view' => 'requests'])) ?>"

                               class="dept-summary-segment-btn <?= $summary_view === 'requests' ? 'is-active' : '' ?>"

                               role="tab"

                               aria-selected="<?= $summary_view === 'requests' ? 'true' : 'false' ?>">

                                Departmental File Requests

                            </a>

                            <a href="<?= $buildAvailabilityQuery(['availability_category' => '', 'availability_department' => '', 'availability_page' => '']) ?>"

                               class="dept-summary-segment-btn <?= $summary_view === 'availability' ? 'is-active' : '' ?>"

                               role="tab"

                               aria-selected="<?= $summary_view === 'availability' ? 'true' : 'false' ?>">

                                File Availability

                            </a>

                        </div>



                        <?php if ($summary_view === 'requests'): ?>

                        <form method="GET" class="dept-summary-filters">

                            <input type="hidden" name="summary_view" value="requests">

                            <select name="month" onchange="this.form.submit()" class="dept-summary-select">

                            <?php

                            for($m=1;$m<=12;$m++){

                                $val = str_pad($m,2,"0",STR_PAD_LEFT);

                                $selected = ($selected_month==$val) ? "selected" : "";

                                echo "<option value='$val' $selected>".date('F', mktime(0,0,0,$m,1))."</option>";

                            }

                            ?>

                            </select>

                            <select name="year" onchange="this.form.submit()" class="dept-summary-select dept-summary-select-year">

                            <?php

                            for($y=date('Y'); $y>=2024; $y--){

                                $selected = ($selected_year==$y) ? "selected" : "";

                                echo "<option value='$y' $selected>$y</option>";

                            }

                            ?>

                            </select>

                        </form>

                        <?php endif; ?>

                    </div>

                </div>



                <?php if ($summary_view === 'requests'): ?>

                <div class="dept-summary-panel" id="panelRequests">

                <table class="dept-summary-table dept-summary-table-requests">

                <colgroup>

                    <col style="width:50%;">

                    <col style="width:25%;">

                    <col style="width:25%;">

                </colgroup>



                <thead>

                    <tr>

                        <th>Department</th>

                        <th class="text-center">Total Requests</th>

                        <th class="text-center">Total Files</th>

                    </tr>

                </thead>



                    <tbody>
                    <?php if (!empty($dept_summary_rows)): ?>
                        <?php foreach ($dept_summary_rows as $row): ?>
                        <tr>
                            <td><?= sanitize($row['department']) ?></td>
                            <td class="text-center">
                                <span class="dept-summary-badge">
                                    <?= number_format((int) $row['total_requests']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="dept-summary-badge">
                                    <?= number_format((int) $row['total_files']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="dept-summary-empty">
                                No departments found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="dept-summary-foot">
                            <td>Total</td>
                            <td class="text-center">
                                <span class="dept-summary-badge dept-summary-badge-strong">
                                    <?= number_format((int) $total_requests) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="dept-summary-badge dept-summary-badge-strong">
                                    <?= number_format((int) $total_files) ?>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                </div>

                <?php else: ?>

                <div class="dept-summary-panel" id="panelAvailability">



                    <nav class="availability-breadcrumb" aria-label="File availability navigation">

                        <?php if ($availability_level === 'category'): ?>

                            <span class="availability-crumb is-current">File Availability</span>

                        <?php else: ?>

                            <a class="availability-crumb" href="<?= $buildAvailabilityQuery(['availability_category' => '', 'availability_department' => '', 'availability_page' => '']) ?>">File Availability</a>

                            <span class="availability-crumb-sep">›</span>

                            <?php if ($availability_level === 'department'): ?>

                                <span class="availability-crumb is-current"><?= sanitize($availability_category) ?></span>

                            <?php else: ?>

                                <a class="availability-crumb" href="<?= $buildAvailabilityQuery(['availability_category' => $availability_category, 'availability_department' => '']) ?>"><?= sanitize($availability_category) ?></a>

                                <span class="availability-crumb-sep">›</span>

                                <span class="availability-crumb is-current"><?= sanitize($availability_department) ?></span>

                            <?php endif; ?>

                        <?php endif; ?>

                    </nav>



                    <?php if ($availability_level === 'category'): ?>

                        <?php if (!empty($availability_categories)): ?>

                            <div class="availability-category-grid">

                                <?php foreach ($availability_categories as $cat_row):

                                    $cat_name = $cat_row['file_category'];

                                    $cat_href = $buildAvailabilityQuery([

                                        'availability_category' => $cat_name,

                                        'availability_department' => '',

                                        'availability_page' => 1,

                                    ]);

                                ?>

                                <a class="availability-category-card" href="<?= $cat_href ?>">

                                    <div class="availability-category-main">

                                        <div class="availability-category-icon">📁</div>

                                        <div>

                                            <div class="availability-category-name"><?= sanitize($cat_name) ?></div>

                                            <div class="availability-category-count"><?= number_format((int) $cat_row['total_files']) ?> files</div>

                                        </div>

                                    </div>

                                    <div class="availability-category-meta">

                                        <span class="dept-summary-badge dept-summary-badge-success"><?= number_format((int) $cat_row['available_files']) ?> available</span>

                                        <span class="dept-summary-badge dept-summary-badge-borrowed"><?= number_format((int) $cat_row['borrowed_files']) ?> borrowed</span>

                                        <span class="availability-category-arrow">→</span>

                                    </div>

                                </a>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <div class="dept-summary-empty">No file categories found.</div>

                        <?php endif; ?>



                    <?php elseif ($availability_level === 'department'): ?>

                        <table class="dept-summary-table dept-summary-table-availability">

                            <thead>

                                <tr>

                                    <th>Department</th>

                                    <th class="text-center">Total Files</th>

                                    <th class="text-center">Borrowed</th>

                                    <th class="text-center">Available</th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php if (!empty($availability_by_dept)): ?>

                                <?php foreach ($availability_by_dept as $dept_row):

                                    $dept_href = $buildAvailabilityQuery([

                                        'availability_category' => $availability_category,

                                        'availability_department' => $dept_row['department'],

                                    ]);

                                ?>

                                <tr class="availability-dept-row" tabindex="0" data-href="<?= htmlspecialchars($dept_href, ENT_QUOTES, 'UTF-8') ?>">

                                    <td>

                                        <a class="availability-dept-link" href="<?= $dept_href ?>">

                                            <?= sanitize($dept_row['department']) ?>

                                        </a>

                                    </td>

                                    <td class="text-center">

                                        <span class="dept-summary-badge"><?= (int) $dept_row['total_files'] ?></span>

                                    </td>

                                    <td class="text-center">

                                        <span class="dept-summary-badge dept-summary-badge-borrowed"><?= (int) $dept_row['borrowed_files'] ?></span>

                                    </td>

                                    <td class="text-center">

                                        <span class="dept-summary-badge dept-summary-badge-success"><?= (int) $dept_row['available_files'] ?></span>

                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="4" class="dept-summary-empty">No departments found in this category.</td>

                                </tr>

                            <?php endif; ?>

                            </tbody>

                        </table>



                        <?php if ($total_availability_pages > 1): ?>

                        <div class="availability-pagination">

                            <?php if ($availability_page > 1): ?>

                                <a class="availability-page-btn" href="<?= $buildAvailabilityQuery(['availability_page' => $availability_page - 1, 'availability_department' => '']) ?>">← Previous</a>

                            <?php else: ?>

                                <span class="availability-page-btn is-disabled">← Previous</span>

                            <?php endif; ?>



                            <div class="availability-page-info">

                                Page <strong><?= $availability_page ?></strong> of <strong><?= $total_availability_pages ?></strong>

                            </div>



                            <?php if ($availability_page < $total_availability_pages): ?>

                                <a class="availability-page-btn" href="<?= $buildAvailabilityQuery(['availability_page' => $availability_page + 1, 'availability_department' => '']) ?>">Next →</a>

                            <?php else: ?>

                                <span class="availability-page-btn is-disabled">Next →</span>

                            <?php endif; ?>

                        </div>

                        <?php endif; ?>



                    <?php else: ?>

                        <div class="availability-files-toolbar">

                            <a class="availability-back-link" href="<?= $buildAvailabilityQuery(['availability_department' => '']) ?>">← Back to Departments</a>

                        </div>

                        <div class="availability-detail-scroll">

                            <table class="availability-files-table">

                                <thead>

                                    <tr>

                                        <th>File Name</th>

                                        <th>Allocation</th>

                                        <th>Box No.</th>

                                        <th>Month / Year</th>

                                        <th>Status</th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php if (!empty($availability_files)): ?>

                                    <?php foreach ($availability_files as $file):

                                        $statusKey = strtolower(trim((string) ($file['status'] ?? '')));

                                        $statusClass = 'is-other';

                                        if ($statusKey === 'available') $statusClass = 'is-available';

                                        elseif ($statusKey === 'borrowed') $statusClass = 'is-borrowed';

                                        $statusLabel = $file['status'] !== '' ? ucfirst($file['status']) : 'Unknown';

                                    ?>

                                    <tr>

                                        <td class="availability-file-name" title="<?= sanitize($file['file_name']) ?>">

                                            <?= sanitize($file['file_name']) ?>

                                        </td>

                                        <td><?= sanitize($file['allocation'] ?? '—') ?></td>

                                        <td><?= sanitize($file['box_no'] ?? '—') ?></td>

                                        <td><?= sanitize($file['month_year'] ?? '—') ?></td>

                                        <td>

                                            <span class="availability-status <?= $statusClass ?>"><?= sanitize($statusLabel) ?></span>

                                        </td>

                                    </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <tr>

                                        <td colspan="5" class="dept-summary-empty">No files found for this department.</td>

                                    </tr>

                                <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

                <?php endif; ?>

            </div>



            <style>

            .dept-summary-toolbar {

                display: flex;

                justify-content: space-between;

                align-items: flex-start;

                gap: 16px;

                margin-bottom: 20px;

                flex-wrap: wrap;

            }

            .dept-summary-title {

                margin: 0;

                font-size: 1.1rem;

                color: var(--text-dark, #1e293b);

            }

            .dept-summary-controls {

                display: flex;

                align-items: center;

                gap: 12px;

                flex-wrap: wrap;

                justify-content: flex-end;

            }

            .dept-summary-segment {

                display: inline-flex;

                padding: 3px;

                background: #f1f5f9;

                border: 1px solid #e2e8f0;

                border-radius: 8px;

                gap: 2px;

            }

            .dept-summary-segment-btn {

                display: inline-block;

                padding: 7px 12px;

                border-radius: 6px;

                font-size: 0.8125rem;

                font-weight: 500;

                color: #64748b;

                text-decoration: none;

                white-space: nowrap;

                transition: background 0.15s, color 0.15s, box-shadow 0.15s;

            }

            .dept-summary-segment-btn:hover {

                color: #334155;

                background: rgba(255,255,255,0.6);

            }

            .dept-summary-segment-btn.is-active {

                background: #fff;

                color: #2563eb;

                box-shadow: 0 1px 2px rgba(15,23,42,0.08);

                font-weight: 600;

            }

            .dept-summary-filters {

                display: flex;

                align-items: center;

                gap: 10px;

            }

            .dept-summary-badge-borrowed {

                background: #fff7ed;

                color: #ea580c;

            }

            .dept-summary-select {

                padding: 8px 14px;

                border: 1px solid #d1d5db;

                border-radius: 8px;

                background: #fff;

                color: #374151;

                font-size: 14px;

                font-weight: 500;

                cursor: pointer;

                outline: none;

                min-width: 140px;

                box-shadow: 0 1px 2px rgba(0,0,0,0.05);

            }

            .dept-summary-select-year { min-width: 90px; }

            .dept-summary-table {

                width: 100%;

                border-collapse: collapse;

                table-layout: fixed;

            }

            .dept-summary-table th {

                padding: 10px;

                text-align: left;

                border-bottom: 2px solid #eee;

                font-size: 0.85rem;

                color: #64748b;

            }

            .dept-summary-table td {

                padding: 12px 10px;

                border-bottom: 1px solid #f5f5f5;

                vertical-align: middle;

            }

            .dept-summary-table .text-center { text-align: center; }

            .dept-summary-badge {

                display: inline-block;

                min-width: 32px;

                background: #eef4ff;

                color: #2563eb;

                padding: 5px 12px;

                border-radius: 999px;

                font-weight: 600;

            }

            .dept-summary-badge-success {

                background: #ecfdf5;

                color: #059669;

            }

            .dept-summary-badge-strong { font-weight: 800; }

            .dept-summary-foot td {

                border-top: 2px solid #ddd;

                background: #f9fafb;

                padding: 14px 10px;

                font-weight: 700;

            }

            .availability-breadcrumb {

                display: flex;

                align-items: center;

                flex-wrap: wrap;

                gap: 6px;

                margin-bottom: 16px;

                font-size: 0.8125rem;

                color: #64748b;

            }

            .availability-crumb {

                color: #2563eb;

                text-decoration: none;

                font-weight: 500;

            }

            .availability-crumb:hover { text-decoration: underline; }

            .availability-crumb.is-current {

                color: #334155;

                font-weight: 600;

                pointer-events: none;

            }

            .availability-crumb-sep { color: #cbd5e1; }

            .availability-category-grid {

                display: grid;

                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));

                gap: 12px;

            }

            .availability-category-card {

                display: flex;

                align-items: center;

                justify-content: space-between;

                gap: 12px;

                padding: 14px 16px;

                border: 1px solid #e2e8f0;

                border-radius: 8px;

                background: #fff;

                text-decoration: none;

                color: inherit;

                box-shadow: 0 1px 2px rgba(15,23,42,0.04);

                transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;

            }

            .availability-category-card:hover {

                border-color: #bfdbfe;

                background: #f8fafc;

                box-shadow: 0 2px 8px rgba(37,99,235,0.08);

            }

            .availability-category-main {

                display: flex;

                align-items: center;

                gap: 12px;

                min-width: 0;

            }

            .availability-category-icon { font-size: 1.25rem; line-height: 1; }

            .availability-category-name {

                font-weight: 600;

                color: #1e293b;

                font-size: 0.95rem;

            }

            .availability-category-count {

                margin-top: 2px;

                font-size: 0.8rem;

                color: #64748b;

            }

            .availability-category-meta {

                display: flex;

                align-items: center;

                gap: 6px;

                flex-wrap: wrap;

                justify-content: flex-end;

            }

            .availability-category-arrow {

                color: #94a3b8;

                font-weight: 600;

                margin-left: 4px;

            }

            .availability-dept-row { cursor: pointer; transition: background 0.15s; }

            .availability-dept-row:hover { background: #f8fafc; }

            .availability-dept-link {

                color: inherit;

                text-decoration: none;

                font-weight: 500;

            }

            .availability-dept-link:hover { color: #2563eb; }

            .availability-files-toolbar { margin-bottom: 12px; }

            .availability-back-link {

                display: inline-block;

                font-size: 0.8125rem;

                font-weight: 600;

                color: #2563eb;

                text-decoration: none;

            }

            .availability-back-link:hover { text-decoration: underline; }

            .availability-detail-scroll { overflow-x: auto; }

            .availability-status {

                display: inline-block;

                padding: 3px 8px;

                border-radius: 999px;

                font-size: 0.75rem;

                font-weight: 600;

            }

            .availability-status.is-available { background: #ecfdf5; color: #059669; }

            .availability-status.is-borrowed { background: #fff7ed; color: #ea580c; }

            .availability-status.is-other { background: #f1f5f9; color: #64748b; }

            .availability-pagination {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    margin-top: 18px;

}



.availability-page-btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-width: 100px;

    padding: 8px 14px;

    border: 1px solid #e2e8f0;

    border-radius: 7px;

    background: #fff;

    color: #475569;

    text-decoration: none;

    font-size: 0.85rem;

    font-weight: 500;

    transition: all 0.15s ease;

}



.availability-page-btn:hover {

    background: #f8fafc;

    border-color: #bfdbfe;

    color: #2563eb;

}



.availability-page-btn.is-disabled {

    color: #cbd5e1;

    background: #f8fafc;

    cursor: not-allowed;

}



.availability-page-info {

    font-size: 0.85rem;

    color: #64748b;

}



.availability-page-info strong {

    color: #334155;

}



@media (max-width: 500px) {

    .availability-pagination {

        gap: 8px;

    }



    .availability-page-btn {

        min-width: auto;

        padding: 8px 10px;

    }

}



            .availability-files-table {

                width: 100%;

                border-collapse: collapse;

                min-width: 520px;

                background: #fff;

                border: 1px solid #e2e8f0;

                border-radius: 8px;

                overflow: hidden;

            }

            .availability-files-table th,

            .availability-files-table td {

                padding: 10px 12px;

                text-align: left;

                font-size: 0.8125rem;

                border-bottom: 1px solid #f1f5f9;

            }

            .availability-files-table th {

                background: #f8fafc;

                color: #64748b;

                font-weight: 600;

            }

            .availability-file-name {

                max-width: 220px;

                overflow: hidden;

                text-overflow: ellipsis;

                white-space: nowrap;

            }

            @media (max-width: 768px) {

                .dept-summary-controls {

                    width: 100%;

                    flex-direction: column;

                    align-items: stretch;

                }

                .dept-summary-segment {

                    width: 100%;

                    flex-direction: column;

                }

                .dept-summary-segment-btn {

                    text-align: center;

                    white-space: normal;

                }

                .dept-summary-filters {

                    width: 100%;

                }

                .dept-summary-select {

                    flex: 1;

                    min-width: 0;

                }

            }

            </style>



            <script>

            document.addEventListener('DOMContentLoaded', function () {

                document.querySelectorAll('.availability-dept-row[data-href]').forEach(function (row) {

                    row.addEventListener('click', function (e) {

                        if (e.target.closest('a')) return;

                        var href = row.getAttribute('data-href');

                        if (href) window.location.href = href;

                    });

                    row.addEventListener('keydown', function (e) {

                        if (e.key === 'Enter' || e.key === ' ') {

                            e.preventDefault();

                            var href = row.getAttribute('data-href');

                            if (href) window.location.href = href;

                        }

                    });

                });

            });

            </script>



            <!-- Two Column Layout -->

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">



                <!-- Recent Requests (Left, Larger) -->

                <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 20px;">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">

                        <h3 style="margin:0; font-size: 1.1rem;">Recent Requests</h3>

                        <a href="requests.php" class="btn" style="padding: 6px 12px; font-size: 0.85rem;">View All &rarr;</a>

                    </div>



                    <table style="width: 100%; border-collapse: collapse;">

                        <thead>

                            <tr style="text-align: left; color: #888; font-size: 0.85rem; border-bottom: 2px solid #f3f4f6;">

                                <th style="padding: 10px;">File Name</th>

                                <th style="padding: 10px;">Requester</th>

                                <th style="padding: 10px;">Status</th>

                                <th style="padding: 10px;">Date</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if($recent_req->rowCount() > 0): 

                                while($row = $recent_req->fetch()): 

                                    // Simple Status Color Logic

                                    $st = $row['current_status'];

                                    $color = '#6b7280'; // Gray default

                                    if($st == 'Released') $color = '#4f46e5'; // Indigo

                                    elseif($st == 'Requested') $color = '#ea580c'; // Orange

                                    elseif($st == 'Completed') $color = '#16a34a'; // Green

                            ?>

                            <tr style="border-bottom: 1px solid #f9fafb;">

                                <td style="padding: 12px 10px; font-weight: 500;"><?= sanitize($row['file_name']) ?></td>

                                <td style="padding: 12px 10px; color: #555;"><?= sanitize($row['full_name']) ?></td>

                                <td style="padding: 12px 10px;">

                                    <span style="background: <?= $color ?>15; color: <?= $color ?>; padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">

                                        <?= $st ?>

                                    </span>

                                </td>

                                <td style="padding: 12px 10px; color: #888; font-size: 0.9rem;"><?= date('M j', strtotime($row['borrow_date'])) ?></td>

                            </tr>

                            <?php endwhile; 

                            else: ?>

                            <tr><td colspan="4" style="text-align:center; padding: 30px; color:#999;">No active requests found.</td></tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>



                <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 20px;">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">

                        <h3 style="margin:0; font-size: 1.1rem;">Audit Trail</h3>

                        <a href="reports.php" style="font-size: 0.85rem; color: var(--accent); text-decoration: none;">View Log</a>

                    </div>



                    <?php 



                    $logs = $pdo->query("

                        SELECT fh.*, u.full_name, f.file_name, f.department

                        FROM file_history fh

                        LEFT JOIN users u ON fh.performed_by = u.id

                        LEFT JOIN files f ON fh.file_id = f.id

                        ORDER BY fh.created_at DESC

                        LIMIT 5

                    ");

                    ?>



                    <div style="display: flex; flex-direction: column; gap: 15px;">

                        <?php if($logs->rowCount() > 0): 

                            while($row = $logs->fetch()): 

                                // Optional: display user with role

                                $userWithRole = $row['full_name'] . " (" . $row['performed_role'] . ")";

                        ?>

                        <div style="display: flex; gap: 10px; align-items: flex-start;">

                            <div style="background: #eff6ff; color: var(--accent); width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0;">

                                <?= strtoupper(substr($row['full_name'], 0, 1)) ?>

                            </div>

                            <div>

                                <div style="font-size: 0.9rem; font-weight: 600;"><?= sanitize($row['action']) ?></div>

                                <div style="font-size: 0.8rem; color: #666; margin-top: 2px;">

                                    by <?= sanitize($userWithRole) ?>

                                </div>

                                <div style="font-size: 0.75rem; color: #999; margin-top: 4px;">

                                    <?= date('M j, H:i', strtotime($row['created_at'])) ?>

                                </div>

                            </div>

                        </div>

                        <?php endwhile; 

                        else: ?>

                            <div style="text-align:center; padding: 20px; color:#999; font-size: 0.9rem;">No recent logs.</div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>



<?php require_once '../includes/footer.php'; ?>