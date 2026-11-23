<?php
session_start();
include 'config.php';

// Check for the tier filter
$tier_filter = isset($_GET['tier']) ? $_GET['tier'] : 'all';

/* ================= ACTIONS ================= */
if(isset($_GET['action']) && isset($_GET['id'])){
    $id = intval($_GET['id']);
    $status = $_GET['action'] === 'approve' ? 'approved' : 'rejected';
    $conn->query("UPDATE sponsorships SET status='$status' WHERE id=$id");
    header("Location: admin_sponsorships.php?success=1&tier=$tier_filter");
    exit();
}

if(isset($_GET['delete'])){
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM sponsorships WHERE id=$id");
    header("Location: admin_sponsorships.php?deleted=1&tier=$tier_filter");
    exit();
}

/* ================= FETCH DATA WITH TIER FILTER ================= */
$query = "SELECT * FROM sponsorships ";
if($tier_filter !== 'all') {
    $query .= " WHERE type = '" . $conn->real_escape_string($tier_filter) . "' ";
}
$query .= " ORDER BY FIELD(status, 'pending', 'approved', 'rejected'), created_at DESC";
$all_requests = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Peura | Control Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --bg: #020617; --card: #0f172a; --accent: #6366f1;
            --vip: #fbbf24; --success: #10b981; --danger: #ef4444;
            --border: rgba(255,255,255,0.08);
        }
        body { margin: 0; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: white; padding: 40px; }
        
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 40px; }
        .header h1 { font-weight: 800; font-size: 2rem; margin: 0; letter-spacing: -1px; }

        .tabs { display: flex; gap: 10px; background: var(--card); padding: 6px; border-radius: 16px; border: 1px solid var(--border); }
        .tab { 
            padding: 10px 20px; border-radius: 12px; text-decoration: none; color: #64748b; 
            font-weight: 600; font-size: 0.9rem; transition: 0.3s;
        }
        .tab:hover { color: #fff; }
        .tab.active { background: var(--accent); color: white; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); }
        .tab.vip-tab.active { background: var(--vip); color: #000; box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3); }

        .admin-table { width: 100%; border-collapse: separate; border-spacing: 0 12px; }
        .admin-table th { text-align: left; padding: 0 20px; color: #475569; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; }
        
        .row { background: var(--card); transition: 0.3s; }
        .row:hover { background: #1e293b; transform: translateY(-2px); }
        .row td { padding: 20px; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); vertical-align: middle; }
        .row td:first-child { border-left: 1px solid var(--border); border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
        .row td:last-child { border-right: 1px solid var(--border); border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

        .brand-box { display: flex; align-items: center; gap: 15px; }
        .brand-img { width: 45px; height: 45px; border-radius: 10px; object-fit: cover; background: #000; }
        
        .media-preview { width: 120px; height: 70px; border-radius: 10px; object-fit: cover; background: #000; cursor: pointer; transition: 0.3s; }
        .media-preview:hover { transform: scale(2.5); position: relative; z-index: 10; border: 2px solid var(--accent); }

        .tier-pill { padding: 4px 10px; border-radius: 8px; font-size: 0.7rem; font-weight: 800; border: 1px solid rgba(255,255,255,0.1); }
        .tier-VIP { color: var(--vip); border-color: var(--vip); }
        
        .status-pill { padding: 5px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
        .pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .approved { background: rgba(16, 185, 129, 0.15); color: #10b981; }

        .actions { display: flex; gap: 8px; }
        .btn { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; border-radius: 12px; text-decoration: none; color: white; transition: 0.2s; border: 1px solid rgba(255,255,255,0.05); }
        .btn-approve { background: var(--success); }
        .btn-reject { background: #334155; }
        .btn-delete { color: var(--danger); font-size: 1.2rem; }
        .btn:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>Command Center</h1>
        <p style="color: #64748b; margin: 5px 0 0;">Manage your brand ecosystem</p>
    </div>

    <div class="tabs">
        <a href="?tier=all" class="tab <?php echo $tier_filter == 'all' ? 'active' : ''; ?>">All</a>
        <a href="?tier=Standard" class="tab <?php echo $tier_filter == 'Standard' ? 'active' : ''; ?>">Standard</a>
        <a href="?tier=Premium" class="tab <?php echo $tier_filter == 'Premium' ? 'active' : ''; ?>">Premium</a>
        <a href="?tier=Exclusive VIP" class="tab vip-tab <?php echo $tier_filter == 'Exclusive VIP' ? 'active' : ''; ?>">💎 VIP</a>
    </div>
</div>

<table class="admin-table">
<thead>
<tr>
    <th>Brand & Contact</th>
    <th>Ad Asset (Meme/Video)</th>
    <th>Tier</th>
    <th>Price</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>

<tbody>
<?php while($row = $all_requests->fetch_assoc()): ?>
<tr class="row">

    <td>
        <div class="brand-box">
            <img src="uploads/<?php echo $row['image']; ?>" class="brand-img">
            <div>
                <div style="font-weight: 700;"><?php echo htmlspecialchars($row['title']); ?></div>

                <!-- NEW: CAPTION -->
                <?php if(!empty($row['caption'])): ?>
                    <div style="font-size: 0.75rem; color: #94a3b8;">
                        <?php echo htmlspecialchars($row['caption']); ?>
                    </div>
                <?php endif; ?>

                <div style="font-size: 0.75rem; color: var(--accent);">
                    <?php echo htmlspecialchars($row['contact_info']); ?>
                </div>

                <!-- NEW: LINK PREVIEW (admin-only view) -->
                <?php if(!empty($row['link'])): ?>
    <div style="font-size: 0.7rem; margin-top: 4px;">
        🔗 <a href="<?php echo htmlspecialchars($row['link']); ?>" target="_blank" style="color: #ef4444; text-decoration: underline;">
             View Brand Link
        </a>
    </div>
<?php endif; ?>

            </div>
        </div>
    </td>

    <td>
        <?php 
            $ext = pathinfo($row['meme_image'], PATHINFO_EXTENSION);
            if(in_array(strtolower($ext), ['mp4', 'webm', 'mov'])): 
        ?>
            <video src="uploads/<?php echo $row['meme_image']; ?>" class="media-preview" muted loop></video>
        <?php else: ?>
            <img src="uploads/<?php echo $row['meme_image']; ?>" class="media-preview">
        <?php endif; ?>
    </td>

    <td>
        <span class="tier-pill tier-<?php echo str_replace(' ', '', $row['type']); ?>">
            <?php echo $row['type']; ?>
        </span>
    </td>

    <td style="font-weight: 800; color: var(--success);">
        $<?php echo number_format($row['price'], 0); ?>
    </td>

    <td>
        <span class="status-pill <?php echo $row['status']; ?>">
            <?php echo $row['status']; ?>
        </span>
    </td>

    <td class="actions">
        <?php if($row['status'] === 'pending'): ?>
            <a href="?action=approve&id=<?php echo $row['id']; ?>&tier=<?php echo $tier_filter; ?>" class="btn btn-approve"><i class="ri-check-line"></i></a>
            <a href="?action=reject&id=<?php echo $row['id']; ?>&tier=<?php echo $tier_filter; ?>" class="btn btn-reject"><i class="ri-close-line"></i></a>
        <?php endif; ?>

        <a href="?delete=<?php echo $row['id']; ?>&tier=<?php echo $tier_filter; ?>" class="btn btn-delete" onclick="return confirm('Erase this partner?')">
            <i class="ri-delete-bin-7-line"></i>
        </a>
    </td>

</tr>
<?php endwhile; ?>
</tbody>
</table>

</body>
</html>