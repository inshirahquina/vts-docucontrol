<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(isAdmin()) redirect('../admin/dashboard.php');

// Search Logic
 $where = "WHERE 1=1";
 $params = [];

if (!empty($_GET['search'])) {
    $term = "%" . $_GET['search'] . "%";
    $where .= " AND (file_name LIKE ? OR file_number LIKE ? OR barcode LIKE ?)";
    $params = array_fill(0, 3, $term);
}

if (!empty($_GET['dept'])) {
    $where .= " AND department = ?";
    $params[] = $_GET['dept'];
}

 // We use LEFT JOIN so files WITHOUT a location still show up (they just won't have room/rack data)
 $sql = "SELECT f.*, l.room, l.rack, l.box 
         FROM files f 
         LEFT JOIN locations l ON f.location_id = l.id 
         $where 
         ORDER BY f.file_name ASC";
 $stmt = $pdo->prepare($sql);
 $stmt->execute($params);
 $files = $stmt->fetchAll();

// Get departments for filter
 $depts = $pdo->query("SELECT DISTINCT department FROM files")->fetchAll(PDO::FETCH_COLUMN);
?>

<!-- WRAPPER START -->
<div class="layout-wrapper">
    <!-- SIDEBAR INCLUDED ONLY HERE (Removed duplicate from top) -->
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">

        <div class="content-area">
            <!-- SEARCH CARD -->
            <div class="card" style="padding: 20px;">
                <form method="GET" action="">
                    <div style="display:grid; grid-template-columns: 2fr 1fr auto auto; gap: 10px; align-items:center;">
                        
                        <!-- Search Input -->
                        <div class="input-wrapper">
                            <input type="text" name="search" placeholder="Search Name, Number, or Barcode..." value="<?= isset($_GET['search'])?sanitize($_GET['search']):'' ?>">
                        </div>
                        
                        <!-- Dept Select -->
                        <div class="input-wrapper" style="padding-left:15px;">
                            <select name="dept" style="border:none; outline:none; background:transparent; width:100%; cursor:pointer;">
                                <option value="">All Departments</option>
                                <?php foreach($depts as $d): ?>
                                    <option value="<?= $d ?>" <?= (isset($_GET['dept']) && $_GET['dept']==$d)?'selected':'' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit">Filter</button>
                        <a href="browse.php" style="text-decoration:none; color:var(--accent); align-self:center;">Reset</a>
                    </div>
                </form>
            </div>

            <!-- FILES TABLE -->
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>File # / Barcode</th>
                            <th>Dept / Cat</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($files) > 0): ?>
                            <?php foreach($files as $f): ?>
                            <tr>
                                <td><?= sanitize($f['file_name']) ?></td>
                                <td>
                                    <strong><?= sanitize($f['file_number']) ?></strong><br>
                                    <small style="color:#777"><?= sanitize($f['barcode']) ?></small>
                                </td>
                                <td><?= sanitize($f['department']) ?> / <?= sanitize($f['category']) ?></td>
                                <td>
                                    <?php if(!empty($f['room'])): ?>
                                        <span style="font-size:0.85rem; color:var(--text-light);">
                                            R<?= sanitize($f['room']) ?> / R<?= sanitize($f['rack']) ?> / B<?= sanitize($f['box']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size:0.85rem; color:#999; font-style:italic;">No Location</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $f['status'] ?>"><?= $f['status'] ?></span></td>
                                <td>
                                    <?php if($f['status'] == 'available'): ?>
                                    <form method="POST" action="../actions/file_actions.php" style="display:inline;">
                                        <input type="hidden" name="barcode_input" value="<?= $f['barcode'] ?>">
                                        <input type="hidden" name="action" value="request_borrow">
                                        <button class="btn-sm" type="submit">Request</button>
                                    </form>
                                    <?php else: ?>
                                    <span style="color:#999; font-size:0.85rem;">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center; padding: 20px;">No files found matching your criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>