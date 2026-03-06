<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');
if (!isset($_SESSION['active_role'])) $_SESSION['active_role'] = 'admin';

// --- FETCH DEPARTMENTS FOR DROPDOWNS ---
$deptStmt = $pdo->query("SELECT DISTINCT department FROM files WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$params = [];

if (!empty($_GET['status'])) {
    $where[] = "status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['search'])) {
    $searchTerm = "%" . $_GET['search'] . "%";
    $where[] = "(file_name LIKE ? OR allocation LIKE ? OR box_no LIKE ? OR department LIKE ?)";
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

$whereSQL = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

/* ---------------- PAGINATION ---------------- */
$perPage = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

$countSQL = "SELECT COUNT(*) FROM files $whereSQL";
$countStmt = $pdo->prepare($countSQL);
$countStmt->execute($params);
$totalRows  = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$sql = "SELECT * FROM files $whereSQL ORDER BY id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$files = $stmt;

if(isset($_POST['add_file'])) {
    $sql = "INSERT INTO files 
            (file_name, allocation, box_no, department, month_year, retention_period, status) 
            VALUES (?,?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            $_POST['file_name'],
            $_POST['allocation'],
            $_POST['box_no'],
            $_POST['department'],
            $_POST['month_year'],
            $_POST['retention'],
            'available' 
        ]);
        header("Location: files.php?msg=added");
        exit;
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
    }
}


if(isset($_POST['edit_file'])) {
    $sql = "UPDATE files SET 
            file_name = ?, allocation = ?, box_no = ?, 
            department = ?, month_year = ?, retention_period = ?, status = ?
            WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            $_POST['file_name'],
            $_POST['allocation'],
            $_POST['box_no'],
            $_POST['department'],
            $_POST['month_year'],
            $_POST['retention'],
            $_POST['status'], 
            $_POST['file_id']
        ]);
        header("Location: files.php?msg=updated");
        exit;
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
    }
}
?>
<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>File Inventory</h1>
                    <p>Track and manage physical documents across departments.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <span>+</span> Add New File
                    </button>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if(isset($_GET['msg'])): ?>
                <div class="alert-card success">
                    <div class="alert-icon">✅</div>
                    <div class="alert-content">
                        <?php 
                            if($_GET['msg'] == 'added') echo "File added successfully.";
                            if($_GET['msg'] == 'updated') echo "File updated successfully.";
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if(isset($errorMsg)): ?>
                <div class="alert-card danger">
                    <div class="alert-icon">⚠️</div>
                    <div class="alert-content">Error: <?= sanitize($errorMsg) ?></div>
                </div>
            <?php endif; ?>

            <!-- Filter Bar -->
            <div class="filter-bar-card">
                <form method="GET" action="files.php" class="filter-form-inline">
                    <div class="search-box-modern">
                        <span class="icon">🔍</span>
                        <input type="text" name="search" placeholder="Search Name, Allocation, Box..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                    </div>
                    
                    <div class="filter-item">
                        <select name="status" class="modern-select">
                            <option value="">All Status</option>

                            <option value="available" <?= (isset($_GET['status']) && $_GET['status'] == 'available') ? 'selected' : '' ?>>Available</option>
                            <option value="borrowed" <?= (isset($_GET['status']) && $_GET['status'] == 'borrowed') ? 'selected' : '' ?>>Borrowed</option>
                            <option value="requested" <?= (isset($_GET['status']) && $_GET['status'] == 'requested') ? 'selected' : '' ?>>Requested</option>
                            <option value="archived" <?= (isset($_GET['status']) && $_GET['status'] == 'archived') ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-dark">Filter</button>
                    <a href="files.php" class="btn btn-secondary">Reset</a>
                </form>
            </div>

            <!-- Data Table Card -->
            <div class="card table-card">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>File Details</th>
                                <th>Location Info</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($files->rowCount() > 0): ?>
                                <?php while($row = $files->fetch()): 
                                    // Comprehensive Status Config for Display
                                    $statusConfig = [
                                        'available' => ['class' => 'status-green', 'icon' => '✅'],
                                        'borrowed'  => ['class' => 'status-orange', 'icon' => '📤'],
                                        'requested' => ['class' => 'status-blue', 'icon' => '📝'],
                                        'archived'  => ['class' => 'status-gray', 'icon' => '🗄️']
                                    ];
                                    $stConf = $statusConfig[$row['status']] ?? ['class' => 'status-default', 'icon' => '📄'];
                                ?>
                                <tr>
                                    <!-- File Info -->
                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-title"><?= sanitize($row['file_name']) ?></span>
                                            <span class="cell-sub"><?= sanitize($row['department']) ?></span>
                                        </div>
                                    </td>

                                    <!-- Location Info -->
                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-text"><strong>Alloc:</strong> <?= sanitize($row['allocation']) ?></span>
                                            <span class="cell-sub"><strong>Box:</strong> <?= sanitize($row['box_no']) ?> • <?= sanitize($row['month_year']) ?></span>
                                        </div>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <div class="status-badge <?= $stConf['class'] ?>">
                                            <?= $stConf['icon'] ?> <?= ucfirst($row['status']) ?>
                                        </div>
                                        <div class="cell-sub" style="margin-top:4px;">
                                            Retention: <?= sanitize($row['retention_period']) ?>
                                        </div>
                                    </td>

                                    <!-- Actions -->
                                    <td class="action-cell">
                                        <div class="action-buttons">
                                            <a href="view_file.php?id=<?= $row['id'] ?>" class="action-btn view" title="View Details">
                                                👁️
                                            </a>

                                            <button onclick='openEditModal(<?= json_encode($row) ?>)' class="action-btn edit" title="Edit File">
                                                ✏️
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="empty-cell">
                                        <div class="empty-state">
                                            <h3>No Files Found</h3>
                                            <p>Try adjusting your filters or add a new file.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <?php
            $range = 10;
            $start = floor(($page - 1) / $range) * $range + 1;
            $end = min($start + $range - 1, $totalPages);
            ?>
            <div style="margin-top:20px; display:flex; justify-content:center; gap:6px; flex-wrap:wrap;">
                <?php if($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="btn btn-secondary">Prev</a>
                <?php endif; ?>

                <?php for($i=$start; $i<=$end; $i++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>" class="btn <?= $i==$page?'btn-primary':'btn-secondary' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="btn btn-secondary">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ADD FILE MODAL -->
<div id="addModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New File</h2>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="add_file" value="true">
            <div class="modal-body">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>File Name</label>
                        <input type="text" name="file_name" required>
                    </div>
                    <div class="form-group">
                        <label>Allocation (Shelf-Col-Tier)</label>
                        <input type="text" name="allocation" placeholder="e.g., S-C-T">
                    </div>
                    <div class="form-group">
                        <label>Box No.</label>
                        <input type="text" name="box_no">
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department" required>
                            <option value="" disabled selected>-- Select Department --</option>
                            <?php foreach($departments as $d): ?>
                                <option value="<?= sanitize($d) ?>"><?= sanitize($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Month/Year</label>
                        <input type="text" name="month_year" placeholder="YYYY-MM">
                    </div>
                    <div class="form-group">
                        <label>Retention Period</label>
                        <input type="text" name="retention" placeholder="e.g., 2 YEARS">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save File</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT FILE MODAL -->
<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit File</h2>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="edit_file" value="true">
            <input type="hidden" name="file_id" id="edit_id">
            <div class="modal-body">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>File Name</label>
                        <input type="text" name="file_name" id="edit_name" required>
                    </div>
                    <div class="form-group">
                        <label>Allocation</label>
                        <input type="text" name="allocation" id="edit_allocation">
                    </div>
                    <div class="form-group">
                        <label>Box No.</label>
                        <input type="text" name="box_no" id="edit_box">
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department" id="edit_dept" required>
                             <?php foreach($departments as $d): ?>
                                <option value="<?= sanitize($d) ?>"><?= sanitize($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Month/Year</label>
                        <input type="text" name="month_year" id="edit_my">
                    </div>
                    <div class="form-group">
                        <label>Retention Period</label>
                        <input type="text" name="retention" id="edit_retention">
                    </div>
                    
                    <!-- Status Field: Only Available & Borrowed -->
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status" required>
                            <option value="available">Available</option>
                            <option value="borrowed">Borrowed</option>
                            <option value="requested">Requested</option>
                            <option value="archived">Archived</option>
                            <option value="lost">Lost</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update File</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
}

function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_name').value = data.file_name || '';
    document.getElementById('edit_allocation').value = data.allocation || '';
    document.getElementById('edit_box').value = data.box_no || '';
    
    // Set dropdown value
    document.getElementById('edit_dept').value = data.department || '';
    
    document.getElementById('edit_my').value = data.month_year || '';
    document.getElementById('edit_retention').value = data.retention_period || '';
    
    // Set status dropdown
    var statusSelect = document.getElementById('edit_status');
        statusSelect.value = data.status || 'available';

            document.getElementById('editModal').style.display = 'flex';
        }

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>

<style>
/* Layout */
.content-area { padding: 24px; background: #f3f4f6; min-height: calc(100vh - 60px); }

/* Page Header */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.page-title h1 { font-size: 1.5rem; color: #111827; margin: 0 0 4px 0; font-weight: 700; }
.page-title p { color: #6b7280; margin: 0; font-size: 0.9rem; }

/* Cards */
.card { background: #fff; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.table-card { overflow: hidden; }

/* Filter Bar */
.filter-bar-card { padding: 16px 20px; margin-bottom: 16px; }
.filter-form-inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

.search-box-modern { position: relative; flex: 1; min-width: 250px; }
.search-box-modern .icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
.search-box-modern input { width: 100%; padding: 10px 15px 10px 38px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; background: #f9fafb; transition: all 0.2s; }
.search-box-modern input:focus { border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); outline: none; }

.modern-select { padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; font-size: 0.9rem; min-width: 150px; }

/* Alerts */
.alert-card { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; }
.alert-card.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-card.danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Table */
.table-responsive { overflow-x: auto; }
.modern-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.modern-table th { text-align: left; padding: 12px 20px; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; background: #f9fafb; border-bottom: 1px solid #e5e7eb; letter-spacing: 0.05em; }
.modern-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }

.cell-main { display: flex; flex-direction: column; gap: 2px; }
.cell-title { font-weight: 600; color: #111827; font-size: 0.95rem; }
.cell-text { font-weight: 500; color: #374151; font-size: 0.9rem; }
.cell-sub { font-size: 0.8rem; color: #9ca3af; }

.empty-cell { padding: 40px; text-align: center; }
.empty-state h3 { margin: 0 0 5px 0; color: #374151; }
.empty-state p { margin: 0; color: #6b7280; font-size: 0.9rem; }

/* Status Badges */
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.status-green { background: #dcfce7; color: #166534; }
.status-orange { background: #ffedd5; color: #c2410c; }
.status-blue { background: #dbeafe; color: #1d4ed8; } /* Added for Requested */
.status-gray { background: #f3f4f6; color: #4b5563; }

/* Action Buttons */
.action-cell { text-align: right; }
.action-buttons { display: flex; justify-content: flex-end; gap: 6px; }

.action-btn {
    width: 32px; height: 32px; border-radius: 6px; border: 1px solid transparent;
    display: flex; align-items: center; justify-content: center; cursor: pointer;
    font-size: 1rem; transition: all 0.2s; text-decoration: none;
}
.action-btn.view { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
.action-btn.view:hover { background: #dbeafe; }
.action-btn.edit { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
.action-btn.edit:hover { background: #ffedd5; }

/* Buttons */
.btn { padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-primary { background: #2563eb; color: white; }
.btn-primary:hover { background: #1d4ed8; }
.btn-secondary { background: #f3f4f6; color: #374151; }
.btn-secondary:hover { background: #e5e7eb; }
.btn-dark { background: #1f2937; color: white; }
.btn-dark:hover { background: #111827; }

/* Modals */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: white; border-radius: 12px; width: 100%; max-width: 550px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); overflow: hidden; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e5e7eb; }
.modal-header h2 { margin: 0; font-size: 1.1rem; color: #111827; }
.modal-close { background: none; border: none; font-size: 1.5rem; color: #9ca3af; cursor: pointer; line-height: 1; }
.modal-body { padding: 20px; }
.modal-footer { padding: 12px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; }

/* Forms */
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full-width { grid-column: span 2; }
.form-group label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.form-group input, .form-group select { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; }
.form-group input:focus, .form-group select:focus { border-color: #2563eb; outline: none; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
</style>

<?php require_once '../includes/footer.php'; ?>