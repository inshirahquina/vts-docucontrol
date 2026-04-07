<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if (!isLoggedIn()) redirect('../index.php');

$base_role = $_SESSION['role'] ?? '';
if ($base_role !== 'requestor' && $base_role !== 'hod') {
    redirect('../index.php');
}

$current_page = basename($_SERVER['PHP_SELF']);

// --- DYNAMIC FILTER DATA ---
$deptStmt = $pdo->query("SELECT DISTINCT department FROM files ORDER BY department ASC");
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

// --- PAGINATION SETUP ---
$limit = 20; // Changed to 20 per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// --- FILTERS SETUP ---
$where = [];
$params = [];

$where[] = "f.status != 'archived'";

if (!isset($_GET['status']) || empty($_GET['status'])) {
    $where[] = "f.status = 'available'";
}

// 2. Search Filter (Removed file_number/barcode)
if (!empty($_GET['search'])) {

    $where[] = "
        (
            f.file_name LIKE :search_name
            OR f.department LIKE :search_dept
            OR f.allocation LIKE :search_alloc
        )
    ";

    $params[':search_name']  = "%" . $_GET['search'] . "%";
    $params[':search_dept']  = "%" . $_GET['search'] . "%";
    $params[':search_alloc'] = "%" . $_GET['search'] . "%";
}

// 3. Department Filter
if (!empty($_GET['department']) && $_GET['department'] !== 'all') {
    $where[] = "f.department = :dept";
    $params[':dept'] = $_GET['department'];
}

$whereSQL = "";
if (!empty($where)) {
    $whereSQL = "WHERE " . implode(" AND ", $where);
}

// 1. Count Query
$sqlCount = "SELECT COUNT(*) FROM files f $whereSQL";
$stmtCount = $pdo->prepare($sqlCount);

// Bind Count Params
foreach ($params as $key => $value) {
    $stmtCount->bindValue($key, $value);
}
$stmtCount->execute();
$total_rows = $stmtCount->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 2. Data Query (Removed location JOIN)
$sql = "SELECT f.* FROM files f 
        $whereSQL
        ORDER BY f.id DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);

// Bind Data Params (Filters)
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

// Bind Pagination Params (Integers)
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$files = $stmt;
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Browse Files</h1>
                    <p>Find and request files from the archive.</p>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar-card">
                <form method="GET" action="browse.php" class="filter-form-inline">
                    
                    <div class="search-box-modern">
                        <span class="icon">🔍</span>
                        <input type="text" name="search" placeholder="Search Name, Dept, Allocation..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                    </div>

                    <div class="filter-item">
                        <select name="department" class="modern-select">
                            <option value="all">All Departments</option>
                            <?php foreach($departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept) ?>" 
                                    <?= (isset($_GET['department']) && $_GET['department'] == $dept) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-dark">Filter</button>
                    <a href="browse.php" class="btn btn-secondary">Reset</a>
                </form>
            </div>

            <!-- Data Table Card -->
            <div class="card table-card">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>File Details</th>
                                <th>Allocation</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($files->rowCount() > 0): ?>
                                <?php while($row = $files->fetch()): ?>
                                <tr>
                                    <!-- File Details -->
                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-title"><?= sanitize($row['file_name']) ?></span>
                                            <span class="cell-sub">Retention: <?= sanitize($row['retention_period']) ?></span>
                                        </div>
                                    </td>

                                    <!-- Allocation -->
                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-text"><?= sanitize($row['allocation']) ?: '-' ?></span>
                                            <span class="cell-sub">Box: <?= sanitize($row['box_no']) ?> • <?= sanitize($row['month_year']) ?></span>
                                        </div>
                                    </td>

                                    <!-- Department -->
                                    <td>
                                        <span class="cell-text"><?= sanitize($row['department']) ?></span>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <?php 
                                        $statusConfig = [
                                            'available' => ['class' => 'status-green', 'icon' => '✅'],
                                            'borrowed'  => ['class' => 'status-orange', 'icon' => '📤'],
                                            'archived'  => ['class' => 'status-gray', 'icon' => '🗄️']
                                        ];
                                        $stConf = $statusConfig[$row['status']] ?? ['class' => 'status-default', 'icon' => '📄'];
                                        ?>
                                        <div class="status-badge <?= $stConf['class'] ?>">
                                             <?= ucfirst($row['status']) ?>
                                        </div>
                                    </td>

                                    <!-- Action -->
                                    <td class="action-cell">
                                        <?php if($row['status'] == 'available'): ?>
                                            <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                                <input type="hidden" name="action" value="create_request">
                                                <input type="hidden" name="file_id" value="<?= $row['id'] ?>">
                                                <button 
                                                    type="button" 
                                                    class="btn btn-sm primary openRequestModal"
                                                    data-file-id="<?= $row['id'] ?>"
                                                    data-file-name="<?= htmlspecialchars($row['file_name']) ?>"
                                                    data-allocation="<?= htmlspecialchars($row['allocation']) ?>"
                                                    data-box="<?= htmlspecialchars($row['box_no']) ?>"
                                                    data-dept="<?= htmlspecialchars($row['department']) ?>"
                                                    >
                                                    Request
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="disabled-text">Unavailable</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-cell">
                                        <div class="empty-state">
                                            <h3>No Files Found</h3>
                                            <p>No available files match your search criteria.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div id="requestModal" class="modal-overlay">
                        <div class="modal-box">
                            <h3>Request File</h3>

                            <div class="modal-details">
                                <p><strong>File:</strong> <span id="m_file_name"></span></p>
                                <p><strong>Allocation:</strong> <span id="m_allocation"></span></p>
                                <p><strong>Box:</strong> <span id="m_box"></span></p>
                                <p><strong>Department:</strong> <span id="m_dept"></span></p>
                            </div>

                            <br>

                            <form method="POST" action="../actions/request_actions.php">
                                <input type="hidden" name="action" value="create_request">
                                <input type="hidden" name="file_id" id="m_file_id">

                                <label>Remarks :</label>
                                <textarea name="remarks" class="modal-textarea" placeholder="Optional remarks..."></textarea>

                                <div class="modal-actions">
                                    <button type="button" class="btn btn-secondary closeModal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Submit Request</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
                    <div class="pagination-info">
                        Showing <?= min($total_rows, $offset + 1) ?> - <?= min($total_rows, $offset + $limit) ?> of <?= $total_rows ?> files
                    </div>

                    <div class="pagination-links">
                        <?php 
                        $query_params = $_GET;
                        unset($query_params['page']); 
                        $query_string = http_build_query($query_params);
                        if(!empty($query_string)) $query_string = '&'.$query_string;

                        $max_buttons = 10;
                        $start_page = floor(($page - 1) / $max_buttons) * $max_buttons + 1;
                        $end_page = min($start_page + $max_buttons - 1, $total_pages);
                        ?>

                        <?php if($page > 1): ?>
                            <a href="?page=1<?= $query_string ?>" class="page-btn">«</a>
                            <a href="?page=<?= $page-1 ?><?= $query_string ?>" class="page-btn">‹</a>
                        <?php endif; ?>

                        <?php for($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if($i == $page): ?>
                                <span class="page-btn active"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $i ?><?= $query_string ?>" class="page-btn"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if($page < $total_pages): ?>
                            <a href="?page=<?= $page+1 ?><?= $query_string ?>" class="page-btn">›</a>
                            <a href="?page=<?= $total_pages ?><?= $query_string ?>" class="page-btn">»</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
/* Layout */
.content-area { padding: 24px; background: #f3f4f6; min-height: calc(100vh - 60px); }

/* Page Header */
.page-header { margin-bottom: 24px; }
.page-title h1 { font-size: 1.5rem; color: #111827; margin: 0 0 4px 0; font-weight: 700; }
.page-title p { margin: 0; color: #6b7280; font-size: 0.9rem; }

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
.status-gray { background: #f3f4f6; color: #4b5563; }

/* Action Buttons */
.action-cell { text-align: right; }
.btn-sm { padding: 6px 14px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
.btn-sm.primary { background: #2563eb; color: white; }
.btn-sm.primary:hover { background: #1d4ed8; }
.disabled-text { color: #9ca3af; font-size: 0.85rem; font-style: italic; }

/* Buttons */
.btn { padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-primary { background: #2563eb; color: white; }
.btn-secondary { background: #f3f4f6; color: #374151; }
.btn-secondary:hover { background: #e5e7eb; }
.btn-dark { background: #1f2937; color: white; }
.btn-dark:hover { background: #111827; }

/* Pagination Styles */
.pagination-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
}
.pagination-info { font-size: 0.85rem; color: #6b7280; }
.pagination-links { display: flex; gap: 4px; }
.page-btn {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.page-btn:hover { background: #f3f4f6; border-color: #9ca3af; }
.page-btn.active { background: #2563eb; color: white; border-color: #2563eb; }
.modal-overlay{
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.4);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:999;
}

.modal-box{
    background:#fff;
    width:420px;
    padding:20px;
    border-radius:10px;
}

.modal-details p{
    margin:4px 0;
    font-size:0.9rem;
}

.modal-textarea{
    width:100%;
    padding:8px;
    margin-top:6px;
    border:1px solid #e5e7eb;
    border-radius:6px;
    min-height:70px;
}

.modal-actions{
    margin-top:15px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}
</style>

<script>

const modal = document.getElementById("requestModal");

document.querySelectorAll(".openRequestModal").forEach(btn => {

    btn.addEventListener("click", function(){

        document.getElementById("m_file_id").value = this.dataset.fileId;
        document.getElementById("m_file_name").textContent = this.dataset.fileName;
        document.getElementById("m_allocation").textContent = this.dataset.allocation;
        document.getElementById("m_box").textContent = this.dataset.box;
        document.getElementById("m_dept").textContent = this.dataset.dept;

        modal.style.display = "flex";

    });

});

document.querySelector(".closeModal").onclick = () => modal.style.display = "none";

window.onclick = function(e){
    if(e.target === modal){
        modal.style.display = "none";
    }
}

</script>

<?php require_once '../includes/footer.php'; ?>