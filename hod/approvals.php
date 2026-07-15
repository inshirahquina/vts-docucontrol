<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if (!isLoggedIn() || ($_SESSION['active_role'] ?? '') !== 'hod') {
    redirect(BASE_URL . 'index.php');
}

$current_user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT r.*, 
       f.file_name,
       f.department,
       f.allocation,
       u.full_name as requester_name 
    FROM requests r
    JOIN files f ON r.file_id = f.id
    JOIN users u ON r.user_id = u.id
    WHERE r.current_status = 'Pending HOD Approval'
    AND u.hod_id = ?
    ORDER BY r.borrow_date DESC
");

$stmt->execute([$current_user_id]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pendingCount = count($pending);

require_once '../includes/header.php';
?>

<div class="layout-wrapper" id="hodApprovalsRoot">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="content-area hod-approvals">

            <div class="page-header-row">
                <div class="page-title">
                    <h1>Pending Approvals</h1>
                    <p>Review file and extension requests from your team</p>
                </div>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="flash-banner success"><?= sanitize($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (empty($pending)): ?>
                <div class="card empty-approvals">
                    <div class="empty-icon">✓</div>
                    <h3>All caught up</h3>
                    <p>No pending approvals for your staff.</p>
                </div>
            <?php else: ?>

                <div class="list-controls-card">
                    <div class="bulk-list-toolbar" id="bulkListToolbar">
                        <label class="bulk-mode-control">
                            <input type="checkbox" id="selectAllBulk">
                            <span class="bulk-mode-label">Select Requests</span>
                        </label>
                        <span class="bulk-select-hint" id="bulkStripCount">None selected</span>
                        <button type="button" class="bulk-cancel-btn" id="bulkExitBtn" hidden>Cancel</button>
                    </div>
                </div>

                <div class="card approvals-card">
                    <div class="table-responsive">
                        <table class="modern-table approvals-table">
                            <thead>
                                <tr>
                                    <th class="col-check"></th>
                                    <th>Date</th>
                                    <th>File</th>
                                    <th>Department / Project</th>
                                    <th>Requester</th>
                                    <th>Type</th>
                                    <th class="col-actions">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending as $p):
                                    $isExtension = $p['extension_count'] > 0;
                                ?>
                                <tr class="bulk-item" data-selectable="1" data-request-id="<?= (int)$p['id'] ?>">
                                    <td class="col-check">
                                        <div class="bulk-checkbox-wrap">
                                            <input type="checkbox"
                                                   class="bulk-checkbox"
                                                   value="<?= (int)$p['id'] ?>"
                                                   aria-label="Select request <?= (int)$p['id'] ?>">
                                        </div>
                                    </td>
                                    <td><?= date('d M Y', strtotime($p['borrow_date'])) ?></td>
                                    <td>
                                        <strong class="file-name"><?= sanitize($p['file_name']) ?></strong>
                                    </td>
                                    <td>
                                        <?= sanitize($p['department']) ?><br>
                                        <small class="muted"><?= sanitize($p['allocation']) ?></small>
                                    </td>
                                    <td><?= sanitize($p['requester_name']) ?></td>
                                    <td>
                                        <?php if ($isExtension): ?>
                                            <span class="type-pill extension">Extension #<?= (int)$p['extension_count'] ?></span>
                                        <?php else: ?>
                                            <span class="type-pill new">New Request</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-actions">
                                        <div class="per-row-actions action-buttons">
                                            <form method="POST" action="../actions/request_actions.php" class="no-bulk-toggle">
                                                <input type="hidden" name="request_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="action" value="approve_hod">
                                                <button type="submit" class="btn-sm btn-approve">Approve</button>
                                            </form>
                                            <form method="POST" action="../actions/request_actions.php" class="no-bulk-toggle"
                                                  onsubmit="return confirm('Reject this request?');">
                                                <input type="hidden" name="request_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="action" value="reject_hod">
                                                <button type="submit" class="btn-sm btn-reject">Reject</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="bulk-toolbar" id="bulkToolbar" aria-hidden="true">
    <div class="bulk-toolbar-count" id="bulkSelectedCount">0 requests selected</div>
    <div class="bulk-toolbar-actions">
        <button type="button" class="btn-bulk btn-bulk-ghost" id="cancelSelectionBtn">Cancel Selection</button>
        <form id="bulkRejectForm" method="POST" action="../actions/request_actions.php" data-bulk-ids>
            <input type="hidden" name="action" value="bulk_reject_hod">
            <button type="submit" class="btn-bulk btn-bulk-danger">Reject Selected</button>
        </form>
        <form id="bulkApproveForm" method="POST" action="../actions/request_actions.php" data-bulk-ids>
            <input type="hidden" name="action" value="bulk_approve_hod">
            <button type="submit" class="btn-bulk btn-bulk-success">Approve Selected</button>
        </form>
    </div>
</div>

<style>
.hod-approvals {
    padding: 28px 32px 32px;
    background: #f8fafc;
    font-family: var(--bs-font, 'IBM Plex Sans', sans-serif);
}
.approvals-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e8eef5;
    box-shadow: var(--bs-shadow);
    overflow: hidden;
    padding: 0;
}
.approvals-table {
    width: 100%;
    border-collapse: collapse;
}
.approvals-table th {
    text-align: left;
    padding: 14px 16px;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
.approvals-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.9rem;
    color: #334155;
    vertical-align: middle;
}
.approvals-table tr.bulk-item:hover td {
    background: #fafbfc;
}
.approvals-table .file-name { color: #0f172a; font-weight: 600; }
.approvals-table .muted { color: #94a3b8; }
.col-check { width: 44px; }
.col-actions { min-width: 180px; }
.type-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
}
.type-pill.extension { background: #fff7ed; color: #c2410c; }
.type-pill.new { background: #f1f5f9; color: #475569; }
.per-row-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.btn-sm {
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: transform 0.15s ease, opacity 0.15s;
}
.btn-sm:hover { transform: translateY(-1px); }
.btn-approve { background: #16a34a; color: #fff; }
.btn-reject { background: #fff; color: #dc2626; border: 1px solid #fecaca; }
.empty-approvals {
    text-align: center;
    padding: 64px 24px;
    background: #fff;
    border-radius: 12px;
    border: 1px dashed #cbd5e1;
}
.empty-approvals .empty-icon {
    width: 48px; height: 48px; margin: 0 auto 12px;
    border-radius: 50%; background: #ecfdf5; color: #059669;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; font-weight: 700;
}
.empty-approvals h3 { margin: 0 0 6px; color: #0f172a; }
.empty-approvals p { margin: 0; color: #64748b; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof BulkSelect === 'undefined' || !document.getElementById('selectAllBulk')) return;

    var bulk = BulkSelect.init({
        root: document.getElementById('hodApprovalsRoot'),
        modeContainer: document.getElementById('hodApprovalsRoot'),
        noun: 'request'
    });

    function bindBulkForm(formId, confirmMsg) {
        var form = document.getElementById(formId);
        if (!form) return;
        form.addEventListener('submit', function (e) {
            if (!bulk.fillForm(form)) {
                e.preventDefault();
                return;
            }
            var n = bulk.getSelectedIds().length;
            if (!confirm(confirmMsg.replace('{n}', n))) {
                e.preventDefault();
            }
        });
    }

    bindBulkForm('bulkApproveForm', 'Approve {n} selected request(s)?');
    bindBulkForm('bulkRejectForm', 'Reject {n} selected request(s)? This cannot be undone for new requests.');
});
</script>

<?php require_once '../includes/footer.php'; ?>
