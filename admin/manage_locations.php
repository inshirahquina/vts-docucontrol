<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');

// 1. Handle Add Location
if(isset($_POST['add_location'])) {
    // Check if this specific room/rack/box combination already exists
    $check = $pdo->prepare("SELECT id FROM locations WHERE room = ? AND rack = ? AND box = ?");
    $check->execute([$_POST['room'], $_POST['rack'], $_POST['box']]);
    
    if($check->rowCount() > 0) {
        $msg = "error_duplicate";
    } else {
        $sql = "INSERT INTO locations (room, rack, box, location_name) VALUES (?,?,?)";
        $stmt = $pdo->prepare($sql);
        try {
            // Create a readable name string like "Room 1 - A1 - Box 2"
            $locName = $_POST['room'] . " - " . $_POST['rack'] . " - " . $_POST['box'];
            
            $stmt->execute([$_POST['room'], $_POST['rack'], $_POST['box'], $locName]);
            echo "<script>window.location.href='manage_locations.php?msg=success';</script>";
        } catch (Exception $e) {
            echo "<script>window.location.href='manage_locations.php?msg=error';</script>";
        }
    }
}

// 2. Handle Delete Location
if(isset($_POST['delete_location'])) {
    // Check if files are using this location (Safety Check)
    $checkFiles = $pdo->prepare("SELECT COUNT(*) FROM files WHERE location_id = ?");
    $checkFiles->execute([$_POST['location_id']]);
    $fileCount = $checkFiles->fetchColumn();

    if($fileCount > 0) {
        // Don't delete if files exist, or force user to move them first
        echo "<script>alert('Cannot delete! There are $fileCount files in this location.'); window.location.href='manage_locations.php';</script>";
    } else {
        $stmt = $pdo->prepare("DELETE FROM locations WHERE id = ?");
        $stmt->execute([$_POST['location_id']]);
        echo "<script>window.location.href='manage_locations.php?msg=deleted';</script>";
    }
}
?>

<!-- WRAPPER START -->
<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>Manage Storage Locations</h2>
                <button class="btn" onclick="document.getElementById('addModal').style.display='flex'">+ Add New Location</button>
            </div>

            <!-- ADD LOCATION MODAL -->
            <div id="addModal" class="modal-overlay">
                <div class="modal-content" style="max-width: 500px;">
                    <div class="modal-header">
                        <h3>Add Storage Space</h3>
                        <button class="modal-close" onclick="document.getElementById('addModal').style.display='none'">&times;</button>
                    </div>
                    
                    <form method="POST">
                        <div class="form-grid">
                            <div class="input-group">
                                <label>Room</label>
                                <input type="text" name="room" placeholder="e.g., 101" required>
                            </div>
                            <div class="input-group">
                                <label>Rack</label>
                                <input type="text" name="rack" placeholder="e.g., A1" required>
                            </div>
                            <div class="input-group">
                                <label>Box</label>
                                <input type="text" name="box" placeholder="e.g., 01" required>
                            </div>
                        </div>
                        
                        <div class="modal-actions">
                            <button type="button" class="btn-secondary" onclick="document.getElementById('addModal').style.display='none'">Cancel</button>
                            <button type="submit" name="add_location" class="btn">Add Location</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- LOCATIONS TABLE -->
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Rack</th>
                            <th>Box</th>
                            <th>Full Label</th>
                            <th>Files Count</th> <!-- Shows how many files are inside -->
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch locations and count files in one query for efficiency
                        $sql = "SELECT l.*, 
                                        (SELECT COUNT(*) FROM files f WHERE f.location_id = l.id) as file_count 
                                        FROM locations l 
                                        ORDER BY l.room, l.rack, l.box";
                        $locations = $pdo->query($sql);
                        
                        if($locations->rowCount() > 0):
                            while($row = $locations->fetch()):
                        ?>
                        <tr>
                            <td><strong><?= sanitize($row['room']) ?></strong></td>
                            <td><?= sanitize($row['rack']) ?></td>
                            <td><?= sanitize($row['box']) ?></td>
                            <td><span style="color:var(--text-light);"><?= sanitize($row['location_name']) ?></span></td>
                            <td>
                                <?php if($row['file_count'] > 0): ?>
                                    <span class="badge available"><?= $row['file_count'] ?> Files</span>
                                <?php else: ?>
                                    <span style="color: #aaa;">Empty</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($row['file_count'] == 0): ?>
                                    <form method="POST" onsubmit="return confirm('Delete this location?');" style="display:inline;">
                                        <input type="hidden" name="location_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="delete_location" value="1">
                                        <button class="btn-sm" style="background:var(--danger);">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn-sm" disabled style="background:#ccc; cursor:not-allowed;" title="Location not empty">Locked</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr><td colspan="6" style="text-align:center;">No locations found. Add one to get started.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>