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
?>

<div class="layout-wrapper">
    <!-- Use Requestor Sidebar -->
    <div class="sidebar">
        <a href="../index.php" class="sidebar-brand">VTS Requestor</a>
        <div class="sidebar-menu">
            <a href="dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            <a href="browse.php" class="<?= ($current_page == 'browse.php') ? 'active' : ''; ?>">Browse Files</a>
            <a href="my_files.php" class="<?= ($current_page == 'my_files.php') ? 'active' : ''; ?>">My Files</a>
        </div>
    </div>

    <div class="main-content">
        <div class="content-area">
            
            <!-- SEARCH BAR -->
            <div class="card" style="padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <form method="GET" action="browse.php">
                    <div style="display:flex; gap: 15px; align-items: center;">
                        <div style="flex: 2; min-width: 250px; position:relative;">
                            <span style="position:absolute; left:12px; top:10px; color:#999;">&#128269;</span>
                            <input type="text" name="search" placeholder="Search File Name, Barcode, or Department..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>" 
                                   style="width: 100%; padding: 10px 10px 10px 35px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div style="flex: 1; min-width: 150px;">
                            <select name="filter" style="width: 100%; padding: 10px; border:1px solid #ddd; border-radius:6px; background:white;">
                                <option value="all" <?= (isset($_GET['filter']) && $_GET['filter'] == 'all') ? 'selected' : '' ?>>All Departments</option>
                                <option value="HR" <?= (isset($_GET['filter']) && $_GET['filter'] == 'HR') ? 'selected' : '' ?>>HR Department</option>
                                <option value="Finance" <?= (isset($_GET['filter']) && $_GET['filter'] == 'Finance') ? 'selected' : '' ?>>Finance Department</option>
                                <option value="IT" <?= (isset($_GET['filter']) && $_GET['filter'] == 'IT') ? 'selected' : '' ?>>IT Department</option>
                                <option value="Operations" <?= (isset($_GET['filter']) && $_GET['filter'] == 'Operations') ? 'selected' : '' ?>>Operations Department</option>
                            </select>
                        </div>
                        <button type="submit" class="btn">Filter</button>
                        <a href="browse.php" class="btn btn-secondary" style="text-decoration:none;">Reset</a>
                    </div>
                </form>
            </div>

            <!-- FILE LIST -->
            <div class="card" style="padding: 0; overflow:hidden;">
                <table style="width:100%; border-collapse: collapse; min-width: 800px;">
                    <thead>
                        <tr style="background: #f8f9fa; text-align: left; border-bottom: 2px solid #eee;">
                            <th style="padding: 15px 20px; font-weight:600; color: #555;">File Details</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555;">Location</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555;">Status</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555; text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Build Query
                        $where = [];
                        $params = [];

                        // Only show AVAILABLE files
                        $where[] = "f.status = 'available'";
                        
                        if (!empty($_GET['search'])) {
                            $searchTerm = "%" . $_GET['search'] . "%";
                            $where[] = "(f.file_name LIKE ? OR f.barcode LIKE ? OR f.department LIKE ?)";
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                        }

                        if (!empty($_GET['filter']) && $_GET['filter'] !== 'all') {
                            $where[] = "f.department = ?";
                            $params[] = $_GET['filter'];
                        }

                        // UPDATED SQL: Added 'f.location_text'
                        $sql = "SELECT f.*, f.location_text, l.room, l.rack, l.box 
                                FROM files f 
                                LEFT JOIN locations l ON f.location_id = l.id ";

                        if (!empty($where)) {
                            $sql .= "WHERE " . implode(" AND ", $where) . " ";
                        }

                        $sql .= "ORDER BY f.id DESC";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $files = $stmt;
                        
                        if($files->rowCount() > 0):
                            while($row = $files->fetch()):
                        ?>
                        <tr style="border-bottom:1px solid #eee; transition: background 0.1s;">
                            <td style="padding: 15px 20px; vertical-align: top;">
                                <div style="font-weight:700; font-size:1rem; color:var(--text-dark); margin-bottom:4px;">
                                    <?= htmlspecialchars($row['file_name']) ?>
                                </div>
                                <div style="font-family:monospace; font-size:0.85rem; color:var(--text-light); background:#f4f4f4; display:inline-block; padding:2px 6px; border-radius:4px;">
                                    <?= htmlspecialchars($row['barcode']) ?>
                                </div>
                                <div style="font-size:0.8rem; color:#888; margin-top:4px;">
                                    <?= htmlspecialchars($row['department']) ?> &bull; <?= htmlspecialchars($row['category']) ?>
                                </div>
                            </td>
                            <td style="padding: 15px 20px; vertical-align: top;">
                                <?php 
                                // DETERMINE LOCATION PRIORITY:
                                // 1. Try to use the structured Location Table (Room/Rack/Box)
                                // 2. If that fails (NULL), use the backup 'location_text' column
                                // 3. If both fail, show Missing
                                
                                $hasStructuredLocation = (!empty($row['room']) || !empty($row['rack']) || !empty($row['box']));
                                $hasTextLocation = !empty($row['location_text']);
                                
                                if ($hasStructuredLocation): 
                                ?>
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
                            <td style="padding: 15px 20px; vertical-align: top;">
                                <span class="badge" style="font-size:0.75rem; font-weight:700; padding:4px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.5px;
                                    background: #e8f5e9; color: #2e7d32;">
                                    Available
                                </span>
                            </td>
                            <td style="padding: 15px 20px; vertical-align: top; text-align:right;">
                                <form method="POST" action="../actions/request_actions.php">
                                    <input type="hidden" name="action" value="create_request">
                                    <input type="hidden" name="file_id" value="<?= $row['id'] ?>">
                                    <button type="submit" class="btn" style="background:var(--primary); color:white; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-size:0.85rem;">
                                        Request File
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr>
                            <td colspan="4" style="padding:40px; text-align:center; color:#888;">
                                No files found matching your criteria.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>