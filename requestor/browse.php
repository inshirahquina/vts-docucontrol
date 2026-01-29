<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php'; // Uses Dynamic Header

// --- SECURITY CHECK ---
if (!isLoggedIn()) {
    redirect('../index.php');
}

// Only allow Requestors
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'requestor') {
    redirect('../index.php');
}

// Include Requestor Sidebar
 $current_page = basename($_SERVER['PHP_SELF']);

// --- DYNAMIC FILTER DATA FETCHING ---

// 1. Fetch Unique Departments
 $deptQuery = "SELECT DISTINCT department FROM files ORDER BY department ASC";
 $deptStmt = $pdo->query($deptQuery);
 $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

// 2. Fetch Unique Statuses
 $statusQuery = "SELECT DISTINCT status FROM files ORDER BY status ASC";
 $statusStmt = $pdo->query($statusQuery);
 $statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);


// --- PAGINATION SETUP ---
 $limit = 10; // Show 10 rows per page
 $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
 $offset = ($page - 1) * $limit;

// --- FILTERS SETUP ---
 $where = [];
 $params = []; // This will now store key-value pairs like ['search' => '%term%']

// Default to available files ONLY if no availability filter is set
if (!isset($_GET['status']) || empty($_GET['status'])) {
    $where[] = "f.status = 'available'";
}

// Search Filter (Changed to named parameter :search_term)
if (!empty($_GET['search'])) {
    $searchTerm = "%" . $_GET['search'] . "%";
    $where[] = "(f.file_name LIKE :search_term OR f.barcode LIKE :search_term OR f.department LIKE :search_term)";
    $params[':search_term'] = $searchTerm;
}

// Department Filter (Changed to named parameter :dept_filter)
if (!empty($_GET['department']) && $_GET['department'] !== 'all') {
    $where[] = "f.department = :dept_filter";
    $params[':dept_filter'] = $_GET['department'];
}

// Availability/Status Filter (Changed to named parameter :status_filter)
if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
    $where[] = "f.status = :status_filter";
    $params[':status_filter'] = $_GET['status'];
}

// --- BUILD QUERIES ---

// 1. Count Total Rows for Pagination
 $sqlCount = "SELECT COUNT(*) FROM files f ";
if (!empty($where)) {
    $sqlCount .= "WHERE " . implode(" AND ", $where);
}

 $stmtCount = $pdo->prepare($sqlCount);
// Bind parameters for count query
foreach ($params as $key => $value) {
    $stmtCount->bindValue($key, $value);
}
 $stmtCount->execute();
 $total_rows = $stmtCount->fetchColumn();
 $total_pages = ceil($total_rows / $limit);

// 2. Fetch Data for Current Page
 $sql = "SELECT f.*, f.location_text, l.room, l.rack, l.box 
        FROM files f 
        LEFT JOIN locations l ON f.location_id = l.id ";

if (!empty($where)) {
    $sql .= "WHERE " . implode(" AND ", $where) . " ";
}

 $sql .= "ORDER BY f.id DESC LIMIT :limit OFFSET :offset";

 $stmt = $pdo->prepare($sql);

// --- BINDING PARAMETERS (100% Named) ---

// 1. Bind Filter/Search parameters (e.g., :search_term)
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

// 2. Bind Pagination parameters
 $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
 $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

 $stmt->execute();
 $files = $stmt;

?>

<div class="layout-wrapper">
    <!-- Use Requestor Sidebar -->
    <div class="sidebar">
        <a href="../index.php" class="sidebar-brand">VTS Requestor</a>
        <div class="sidebar-menu">
            <a href="dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            <a href="browse.php" class="<?= ($current_page == 'browse.php') ? 'active' : ''; ?>">Browse Files</a>
            <a href="my_files.php" class="<?= ($current_page == 'my_files.php') ? 'active' : ''; ?>">My History</a>
        </div>
    </div>

    <div class="main-content">
        <div class="content-area">
            
            <!-- FILTER BAR -->
            <div class="card" style="padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <form method="GET" action="browse.php">
                    <div style="display:flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <!-- Search -->
                        <div style="flex: 2; min-width: 250px; position:relative;">
                            <span style="position:absolute; left:12px; top:10px; color:#999;">&#128269;</span>
                            <input type="text" name="search" placeholder="Search Name, Barcode, or Dept..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>" 
                                   style="width: 100%; padding: 10px 10px 10px 35px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        
                        <!-- Department Filter (Dynamic) -->
                        <div style="flex: 1; min-width: 150px;">
                            <select name="department" style="width: 100%; padding: 10px; border:1px solid #ddd; border-radius:6px; background:white;">
                                <option value="all" <?= (isset($_GET['department']) && $_GET['department'] == 'all') ? 'selected' : '' ?>>All Departments</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept) ?>" 
                                        <?= (isset($_GET['department']) && $_GET['department'] == $dept) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Availability Filter (Dynamic) -->
                        <div style="flex: 1; min-width: 150px;">
                            <select name="status" style="width: 100%; padding: 10px; border:1px solid #ddd; border-radius:6px; background:white;">
                                <option value="all" <?= (isset($_GET['status']) && $_GET['status'] == 'all') ? 'selected' : '' ?>>All Status</option>
                                <?php foreach($statuses as $stat): ?>
                                    <option value="<?= htmlspecialchars($stat) ?>" 
                                        <?= (isset($_GET['status']) && $_GET['status'] == $stat) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucfirst($stat)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn">Filter</button>
                        <a href="browse.php" class="btn btn-secondary" style="text-decoration:none;">Reset</a>
                    </div>
                </form>
            </div>

            <!-- FILE LIST -->
            <div class="card" style="padding: 0; overflow:hidden;">
                <table style="width:100%; border-collapse: collapse; min-width: 900px;">
                    <thead>
                        <tr style="background: #f8f9fa; text-align: left; border-bottom: 2px solid #eee;">
                            <th style="padding: 15px 20px; font-weight:600; color: #555; width: 30%;">File Name</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555; width: 15%;">Department</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555; width: 25%;">Location</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555; width: 15%;">Status</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555; width: 15%; text-align:right;"></th> 
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($files->rowCount() > 0): ?>
                            <?php while($row = $files->fetch()): ?>
                            <tr style="border-bottom:1px solid #eee; transition: background 0.1s;">
                                <!-- File Name Column -->
                                <td style="padding: 15px 20px; vertical-align: top;">
                                    <div style="font-weight:700; font-size:1rem; color:var(--text-dark); margin-bottom:4px;">
                                        <?= htmlspecialchars($row['file_name']) ?>
                                    </div>
                                    <!-- <div style="font-family:monospace; font-size:0.85rem; color:var(--text-light); background:#f4f4f4; display:inline-block; padding:2px 6px; border-radius:4px;">
                                        <?= htmlspecialchars($row['barcode']) ?>
                                    </div> -->
                                    <div style="font-size:0.8rem; color:#888; margin-top:4px;">
                                        <?= htmlspecialchars($row['category']) ?>
                                    </div>
                                </td>
                                
                                <!-- Department Column -->
                                <td style="padding: 15px 20px; vertical-align: top;">
                                    <span style="font-weight: 600; color: #333;">
                                        <?= htmlspecialchars($row['department']) ?>
                                    </span>
                                </td>
                                
                                <!-- Location Column -->
                                <td style="padding: 15px 20px; vertical-align: top;">
                                    <?php 
                                    $hasStructuredLocation = (!empty($row['room']) || !empty($row['rack']) || !empty($row['box']));
                                    $hasTextLocation = !empty($row['location_text']);
                                    
                                    if ($hasStructuredLocation): ?>
                                        <div style="font-size:0.9rem; color:var(--text-dark); font-weight:500;">
                                            <span style="color:#888;">Room:</span> <?= htmlspecialchars($row['room']) ?> <br>
                                            <span style="color:#888;">Rack:</span> <?= htmlspecialchars($row['rack']) ?> / <span style="color:#888;">Box:</span> <?= htmlspecialchars($row['box']) ?>
                                        </div>
                                    <?php elseif ($hasTextLocation): ?>
                                        <div style="font-size:0.9rem; color:var(--text-dark); font-weight:500;">
                                            <?= htmlspecialchars($row['location_text']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#d9534f; font-weight:bold; font-size:0.85rem;">Location Data Missing</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Status Column -->
                                <td style="padding: 15px 20px; vertical-align: top;">
                                    <?php 
                                    $status = $row['status'];
                                    $badgeStyle = "";
                                    switch($status) {
                                        case 'available':
                                            $badgeStyle = "background: #e8f5e9; color: #2e7d32;";
                                            break;
                                        case 'borrowed':
                                            $badgeStyle = "background: #fff3e0; color: #ef6c00;";
                                            break;
                                        case 'archived':
                                            $badgeStyle = "background: #eceff1; color: #546e7a;";
                                            break;
                                        case 'lost':
                                            $badgeStyle = "background: #ffebee; color: #c62828;";
                                            break;
                                        default:
                                            $badgeStyle = "background: #eee; color: #333;";
                                    }
                                    ?>
                                    <span class="badge" style="font-size:0.75rem; font-weight:700; padding:4px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.5px; <?= $badgeStyle ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                                
                                <!-- Action Column -->
                                <td style="padding: 15px 20px; vertical-align: top; text-align:right;">
                                    <?php if($row['status'] == 'available'): ?>
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="create_request">
                                        <input type="hidden" name="file_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:var(--primary); color:white; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-size:0.85rem;">
                                            Request File
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <span style="color:#aaa; font-size:0.85rem; font-style:italic;">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="5" style="padding:40px; text-align:center; color:#888;">
                                No files found matching your criteria.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- PAGINATION CONTROLS -->
                <?php if($total_pages > 1): ?>
                <div style="padding: 15px 20px; border-top: 1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#666; font-size:0.9rem;">
                        Showing <?= ($offset + 1) ?> - <?= min($offset + $limit, $total_rows) ?> of <?= $total_rows ?> files
                    </span>
                    <div style="display:flex; gap: 5px;">
                        <?php 
                            $query_params = $_GET;
                            unset($query_params['page']);
                            $base_url = "?" . http_build_query($query_params);
                        ?>

                        <?php if($page > 1): ?>
                            <a href="<?= $base_url ?>&page=1" class="btn btn-secondary" style="padding: 5px 10px; font-size:0.85rem;">First</a>
                            <a href="<?= $base_url ?>&page=<?= $page - 1 ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size:0.85rem;">Prev</a>
                        <?php endif; ?>

                        <span style="padding: 5px 10px; font-weight:bold; color:var(--primary);">
                            <?= $page ?> / <?= $total_pages ?>
                        </span>

                        <?php if($page < $total_pages): ?>
                            <a href="<?= $base_url ?>&page=<?= $page + 1 ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size:0.85rem;">Next</a>
                            <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size:0.85rem;">Last</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>