<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');
if (!isset($_SESSION['active_role'])) $_SESSION['active_role'] = 'admin';

 $activeRole = $_SESSION['active_role'];
 $uid = $_SESSION['user_id'];

// 1. Fetch locations for the dropdown (Optional, if you still use it)
 $locations = $pdo->query("SELECT * FROM locations ORDER BY room, rack, box")->fetchAll();

// 2. Handle Search/Filter Logic
 $where = [];
 $params = [];

if (!empty($_GET['status'])) {
    $where[] = "f.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['search'])) {
    $searchTerm = "%" . $_GET['search'] . "%";
    $where[] = "(f.file_name LIKE ? OR f.barcode LIKE ? OR f.department LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

 $sql = "SELECT f.* 
        FROM files f ";

if (!empty($where)) {
    $sql .= "WHERE " . implode(" AND ", $where) . " ";
}

 $sql .= "ORDER BY f.id DESC";

 $stmt = $pdo->prepare($sql);
 $stmt->execute($params);
 $files = $stmt;

// Handle Add File (Quick Add)
if(isset($_POST['add_file'])) {
    // DEBUG: Print the raw POST data so we can see exactly what is being sent
    echo "<div style='background:#fff3cd; padding:10px; border:1px solid #ffeeba; margin-bottom:20px;'>";
    echo "<strong>DEBUG: Received Data:</strong><br>";
    echo "<pre>" . print_r($_POST, true) . "</pre>";
    echo "</div>";

    $sql = "INSERT INTO files (file_name, file_number, barcode, department, category, month_year, location_id, retention_period, status) VALUES (?,?,?,?,?,?,?,?,?)";
    
    $stmt = $pdo->prepare($sql);
    
    try {
        $stmt->execute([
            $_POST['file_name'], 
            $_POST['file_number'], 
            $_POST['barcode'], 
            $_POST['department'], 
            $_POST['category'], 
            $_POST['month_year'], 
            $_POST['location_id'], 
            $_POST['retention'],
            'available'
        ]);
        
        // Only redirect if successful
        echo "<script>window.location.href='files.php?msg=success';</script>";
        
    } catch (PDOException $e) {
        // STOP! Do not redirect. Show the red error box.
        echo "<div style='background:#ffebee; color:#c62828; padding:20px; border:1px solid red; margin-top:20px;'>";
        echo "<h3>Database Error:</h3>";
        echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>";
        echo "<p><strong>Code:</strong> " . $e->getCode() . "</p>";
        echo "</div>";
    }
}
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Header -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                <div>
                    <h1 style="margin:0; font-size:1.8rem;">File Inventory</h1>
                    <p style="margin:5px 0 0 0; color: var(--text-light);">Track and manage your physical documents.</p>
                </div>
                <div style="display:flex; gap:10px;">
                    <!-- <a href="manage_locations.php" class="btn btn-secondary">Manage Locations</a> -->
                    <!-- <button class="btn" onclick="document.getElementById('addModal').style.display='flex'">+ Add New File</button> -->
                </div>
            </div>

            <!-- SEARCH & FILTER BAR -->
            <div class="card" style="padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <form method="GET" action="files.php" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 2; min-width: 250px; position:relative;">
                        <span style="position:absolute; left:12px; top:10px; color:#999;">&#128269;</span>
                        <input type="text" name="search" placeholder="Search Name, Barcode, or Location..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>" 
                               style="width: 100%; padding: 10px 10px 10px 35px; border:1px solid #ddd; border-radius:6px;">
                    </div>
                    <div style="flex: 1; min-width: 150px;">
                        <select name="status" style="width: 100%; padding: 10px; border:1px solid #ddd; border-radius:6px; background:white;">
                            <option value="">All Status</option>
                            <option value="available" <?= (isset($_GET['status']) && $_GET['status'] == 'available') ? 'selected' : '' ?>>Available</option>
                            <option value="borrowed" <?= (isset($_GET['status']) && $_GET['status'] == 'borrowed') ? 'selected' : '' ?>>Borrowed/Assigned</option>
                            <option value="archived" <?= (isset($_GET['status']) && $_GET['status'] == 'archived') ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">Filter</button>
                    <a href="files.php" class="btn btn-secondary" style="text-decoration:none;">Reset</a>
                </form>
            </div>

            <!-- CLEAN LIST TABLE -->
            <div class="card" style="padding: 0; overflow:hidden;">
                <table style="width:100%; border-collapse: collapse; min-width: 800px;">
                    <thead>
                        <tr style="background: #f8f9fa; text-align: left; border-bottom: 2px solid #eee;">
                            <th style="padding: 15px 20px; font-weight:600; color: #555;">File Details</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555;">Allocation & Box No.</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555;">Status</th>
                            <th style="padding: 15px 20px; font-weight:600; color: #555;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($files->rowCount() > 0): ?>
                            <?php while($row = $files->fetch()): ?>
                                <tr style="border-bottom: 1px solid #eee; transition: background 0.1s;">
                                    <td style="padding: 15px 20px; vertical-align: top;">
                                        <div style="font-weight:700; font-size:1rem; color:var(--text-dark); margin-bottom:4px;">
                                            <?= sanitize($row['file_name']) ?>
                                        </div>
                                        <div style="font-family:monospace; font-size:0.85rem; color:var(--text-light); background:#f4f4f4; display:inline-block; padding:2px 6px; border-radius:4px;">
                                            <?= sanitize($row['barcode']) ?>
                                        </div>
                                        <div style="font-size:0.8rem; color:#888; margin-top:4px;">
                                            <?= sanitize($row['department']) ?> &bull; <?= sanitize($row['category']) ?>
                                        </div>
                                    </td>
                                    <td style="padding: 15px 20px; vertical-align: top;">
                                        <div style="font-size:0.9rem; color:var(--text-dark); font-weight:500;">
                                            <?php if(!empty($row['location_text'])): ?>
                                                <span style="color:#888;"></span> <?= sanitize($row['location_text']) ?> <br>
                                            <?php else: ?>
                                                <span style="color:orange;">Legacy Data</span>
                                            <?php endif; ?>
                                            <small style="color:#666; display:block; margin-top:2px;">
                                                Retention: <?= sanitize($row['retention_period']) ?> 
                                            </small>
                                        </div>
                                    </td>
                                    <td style="padding: 15px 20px; vertical-align: top;">
                                        <!-- Status Badge -->
                                        <div style="margin-bottom: 8px;">
                                            <span style="font-size:0.75rem; font-weight:700; padding:4px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.5px;
                                                background:<?= ($row['status'] == 'available') ? '#e8f5e9' : (($row['status'] == 'borrowed') ? '#ffebee' : '#f0f0f0') ?>; color:<?= ($row['status'] == 'available') ? '#2e7d32' : (($row['status'] == 'borrowed') ? '#c62828' : '#666') ?>;">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                        </div>
                                        
                                        <!-- Who is assigned? (If borrowed) -->
                                        <div style="font-size:0.85rem; color:#666; line-height:1.4;">
                                            <?php if($row['status'] == 'borrowed'): ?>
                                                <div style="display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                                    <span style="color:var(--danger);">&#9888;</span>
                                                    <strong>In Workflow</strong>
                                                </div>
                                            <?php elseif($row['returned_at']): ?>
                                                <div style="display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                                    <span style="color:var(--success);">&#10003;</span>
                                                    <span>Returned on: <?= date('M j, Y', strtotime($row['returned_at'])) ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#aaa;">No recent history</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 15px 20px; vertical-align: top; text-align:right;">
                                        <div style="display:flex; justify-content:flex-end; gap:8px;">
                                            
                                            <!-- View History -->
                                            <a href="view_file.php?id=<?= $row['id'] ?>" style="text-decoration:none; color:#6c757d; border:1px solid #ddd; padding:6px 12px; border-radius:4px; font-size:0.85rem;" title="View History">
                                                Details
                                            </a>

                                            <?php if($row['status'] == 'available'): ?>

                                            <?php elseif($row['status'] == 'borrowed'): ?>
                                                <!-- RETURN BUTTON -->
                                                <form method="POST" action="../actions/file_actions.php" style="display:inline;">
                                                    <input type="hidden" name="file_id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="action" value="return_file">
                                                    <button type="submit" class="btn" style="background:#28a745; color:white; border:none; padding:6px 12px; border-radius:4px; font-size:0.85rem; cursor:pointer;">
                                                        Return
                                                    </button>
                                                </form>

                                            <?php endif; ?>

                                            <!-- Delete -->
                                            <form method="POST" action="../actions/file_actions.php" onsubmit="return confirm('Delete this file?');" style="display:inline;">
                                                <input type="hidden" name="file_id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="action" value="delete_file">
                                                <button type="submit" style="background:transparent; color:var(--danger); border:1px solid transparent; padding:6px 8px; font-size:1.1rem; cursor:pointer; line-height:1;">
                                                    &times;
                                                </button>
                                            </form>
                                        </div>
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

<!-- ADD FILE MODAL -->
<div id="addModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 450px; border-top: 5px solid var(--primary);">
        <div class="modal-header">
            <h2>Add Physical File</h2>
            <button class="modal-close" onclick="document.getElementById('addModal').style.display='none'">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="add_file" value="true">
            <div class="form-grid">
                <div class="input-group">
                    <label>File Name</label>
                    <input type="text" name="file_name" required>
                </div>
                <div class="input-group">
                    <label>File Number</label>
                    <input type="text" name="file_number" required>
                </div>
                <div class="input-group">
                    <label>Barcode</label>
                    <input type="text" name="barcode" placeholder="Scan or Type..." required autofocus>
                </div>
                <div class="input-group">
                    <label>Department</label>
                    <input type="text" name="department" required>
                </div>
                <div class="input-group">
                    <label>Category</label>
                    <input type="text" name="category" required>
                </div>
                <div class="input-group">
                    <label>Month/Year</label>
                    <input type="text" name="month_year" placeholder="YYYY-MM" required>
                </div>
                <div class="input-group" style="grid-column: span 2;">
                    <label>Storage Location</label>
                    <select name="location_id" required style="width: 100%; padding: 10px;">
                        <option value="">-- Select Location --</option>
                        <?php foreach($locations as $loc): ?>
                            <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group" style="grid-column: span 2;">
                    <label>Retention (Years)</label>
                    <input type="number" name="retention" required>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="document.getElementById('addModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn">Save File</button>
            </div>
        </form>
    </div>
</div>

<script>
function getStatusBg($status) {
    switch($status) {
        case 'borrowed': return '#ffebee'; 
        case 'archived': return '#f0f0f0'; 
        case 'available': return '#e8f5e9'; 
        default: return '#fff';
    }
}
function getStatusText($status) {
    switch($status) {
        case 'borrowed': return '#c62828';
        case 'archived': return '#555';
        case 'available': return '#2e7d32';
        default: return '#333';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>