<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

$current_user = $_SESSION['user_id'] ?? 0;
if (!$current_user) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$action = $_POST['action'] ?? 'core';

// ── Family: Add ────────────────────────────────────────────────────────────
if ($action === 'family_add') {
    $name      = trim($_POST['member_name'] ?? '');
    $rel       = trim($_POST['relationship'] ?? '');
    $consent   = intval($_POST['consent_given'] ?? 0);

    if (!$name || !$rel || !$consent) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $pic_path = null;
    if (!empty($_FILES['member_pic']['tmp_name'])) {
        $ext      = strtolower(pathinfo($_FILES['member_pic']['name'], PATHINFO_EXTENSION));
        $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid image type']);
            exit;
        }
        $filename = 'uploads/family/' . time() . '_' . uniqid() . '.' . $ext;
        if (!is_dir('uploads/family')) mkdir('uploads/family', 0755, true);
        if (move_uploaded_file($_FILES['member_pic']['tmp_name'], $filename)) {
            $pic_path = $filename;
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO user_family_members (user_id, member_name, relationship, profile_pic, consent_given)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssi", $current_user, $name, $rel, $pic_path, $consent);

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        echo json_encode([
            'success' => true,
            'member'  => [
                'id'          => $new_id,
                'member_name' => $name,
                'relationship'=> $rel,
                'profile_pic' => $pic_path,
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not add member']);
    }
    exit;
}

// ── Family: Delete ─────────────────────────────────────────────────────────
if ($action === 'family_delete') {
    $member_id = intval($_POST['member_id'] ?? 0);
    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid member']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM user_family_members WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $member_id, $current_user);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
    exit;
}

// ── Event: Add ─────────────────────────────────────────────────────────────
if ($action === 'event_add') {
    $type  = trim($_POST['event_type'] ?? '');
    $title = trim($_POST['event_title'] ?? '');
    $date  = trim($_POST['event_date'] ?? '');
    $desc  = trim($_POST['event_description'] ?? '');

    if (!$type || !$title) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $date = $date !== '' ? $date : null;
    $desc = $desc !== '' ? $desc : null;

    $stmt = $conn->prepare("
        INSERT INTO user_life_events (user_id, event_type, event_title, event_date, description)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issss", $current_user, $type, $title, $date, $desc);

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        echo json_encode([
            'success' => true,
            'event'   => [
                'id'          => $new_id,
                'event_type'  => $type,
                'event_title' => $title,
                'event_date'  => $date,
                'description' => $desc,
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not add event']);
    }
    exit;
}

// ── Event: Delete ──────────────────────────────────────────────────────────
if ($action === 'event_delete') {
    $event_id = intval($_POST['event_id'] ?? 0);
    if (!$event_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid event']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM user_life_events WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $event_id, $current_user);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
    exit;
}

// ── Core: Save ─────────────────────────────────────────────────────────────
$fields = [
    'full_name', 'nickname', 'occupation', 'education',
    'current_location', 'hometown', 'relationship_status',
    'website', 'interests', 'description',
    'contact_email', 'contact_phone',
    'political_views', 'religious_views', 'favorite_quotes'
];

$values = [];
foreach ($fields as $f) {
    $v = trim($_POST[$f] ?? '');
    $values[$f] = $v === '' ? null : $v;
}

$dob = trim($_POST['date_of_birth'] ?? '');
$values['date_of_birth'] = $dob !== '' ? $dob : null;

if ($values['website'] && !preg_match('#^https?://#i', $values['website'])) {
    $values['website'] = 'https://' . $values['website'];
}

$stmt = $conn->prepare("
    INSERT INTO user_about (
        user_id, full_name, nickname, occupation, education,
        current_location, hometown, relationship_status, date_of_birth,
        website, interests, description,
        contact_email, contact_phone, political_views, religious_views, favorite_quotes
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        full_name           = VALUES(full_name),
        nickname            = VALUES(nickname),
        occupation          = VALUES(occupation),
        education           = VALUES(education),
        current_location    = VALUES(current_location),
        hometown            = VALUES(hometown),
        relationship_status = VALUES(relationship_status),
        date_of_birth       = VALUES(date_of_birth),
        website             = VALUES(website),
        interests           = VALUES(interests),
        description         = VALUES(description),
        contact_email       = VALUES(contact_email),
        contact_phone       = VALUES(contact_phone),
        political_views     = VALUES(political_views),
        religious_views     = VALUES(religious_views),
        favorite_quotes     = VALUES(favorite_quotes)
");

$stmt->bind_param(
    "issssssssssssssss",
    $current_user,
    $values['full_name'],
    $values['nickname'],
    $values['occupation'],
    $values['education'],
    $values['current_location'],
    $values['hometown'],
    $values['relationship_status'],
    $values['date_of_birth'],
    $values['website'],
    $values['interests'],
    $values['description'],
    $values['contact_email'],
    $values['contact_phone'],
    $values['political_views'],
    $values['religious_views'],
    $values['favorite_quotes']
);

echo json_encode(['success' => $stmt->execute()]);
$stmt->close();