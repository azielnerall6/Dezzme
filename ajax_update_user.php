<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

// Make sure warnings never corrupt the JSON, and mysqli throws catchable exceptions
ini_set('display_errors', 0);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function respond($success, $msg = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'msg' => $msg], $extra));
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) respond(false, 'Not logged in');

$field = $_POST['field'] ?? '';
$value = trim($_POST['value'] ?? '');

// Username changes are limited to once every 14 days and are handled (checked + stamped)
// by the handler in settings.php. Block them here so this endpoint can't be used to bypass it.
if ($field === 'username') {
    respond(false, 'Username changes go through the profile editor or Settings.');
}

$user_fields = ['username', 'nickname', 'email', 'profile_pic'];

$settings_fields = [
    'bio', 'pronouns',
    'is_private', 'is_fully_private', 'show_profile_pic', 'allow_messages', 'show_online_status',
    'allow_anonymous_post', 'notify_likes', 'notify_follows', 'notify_messages',
    'dark_mode', 'autoplay_videos', 'reduce_animation',
    'activity_vibe', 'user_type', 'other_accounts',
    'profile_type'
];

if (!in_array($field, array_merge($user_fields, $settings_fields), true)) {
    respond(false, 'Field not allowed');
}

// ==========================
// FIELD VALIDATION
// ==========================
switch ($field) {

    case 'username':
        if (!preg_match('/^[A-Za-z0-9_]{2,30}$/', $value)) {
            respond(false, 'Username must be 2–30 characters: letters, numbers, underscore only');
        }
        break;

    case 'nickname':
        $value = mb_substr($value, 0, 50);
        if (mb_strlen($value) < 2) respond(false, 'Nickname is too short');
        break;

    case 'email':
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            respond(false, 'Please enter a valid email address');
        }
        break;

    case 'bio':
        $value = mb_substr($value, 0, 600);
        break;

    case 'pronouns':
        $value = mb_substr($value, 0, 30);
        break;

    case 'other_accounts':
        $value = mb_substr($value, 0, 255);
        break;

    case 'profile_type':
        $value = mb_substr($value, 0, 60);
        // Empty is allowed (the "Remove" button sends it).
        // Otherwise: letters, numbers, spaces and a few symbols used in the list
        // e.g. "UI/UX Designer", "Front-End Developer", "Stay-at-home Parent"
        if ($value !== '' && !preg_match('/^[\p{L}\p{N} \/&\-\'.]+$/u', $value)) {
            respond(false, 'Invalid profile type');
        }
        break;

    case 'user_type':
        if (!in_array($value, ['', 'Dezzmer'], true)) {
            respond(false, 'Invalid user type');
        }
        break;

    case 'activity_vibe':
        $allowed_vibes = [
            '', 'Flexible',
            'Early morning (5–9 AM)', 'Late morning (9–12 PM)', 'Afternoon (12–5 PM)',
            'Evening (5–9 PM)', 'Night (9 PM–1 AM)', 'Late night (1–5 AM)'
        ];
        if (!in_array($value, $allowed_vibes, true)) {
            respond(false, 'Invalid activity vibe');
        }
        break;
}

// Toggle fields must be 0 or 1
$toggle_fields = [
    'is_private', 'is_fully_private', 'show_profile_pic', 'allow_messages', 'show_online_status',
    'allow_anonymous_post', 'notify_likes', 'notify_follows', 'notify_messages',
    'dark_mode', 'autoplay_videos', 'reduce_animation'
];
if (in_array($field, $toggle_fields, true)) {
    $value = ($value === '1') ? 1 : 0;
}

try {

    // ==========================
    // UPDATE USERS TABLE
    // ==========================
    if (in_array($field, $user_fields, true)) {

        // Uniqueness check for username / nickname / email
        if (in_array($field, ['username', 'nickname', 'email'], true)) {
            $chk = $conn->prepare("SELECT id FROM users WHERE `$field` = ? AND id != ? LIMIT 1");
            $chk->bind_param("si", $value, $user_id);
            $chk->execute();
            $taken = $chk->get_result()->num_rows > 0;
            $chk->close();
            if ($taken) respond(false, ucfirst($field) . ' is already taken');
        }

        // Log old username before overwriting
        if ($field === 'username') {
            $log = $conn->prepare("INSERT INTO username_history (user_id, old_username) SELECT id, username FROM users WHERE id=?");
            $log->bind_param("i", $user_id);
            $log->execute();
            $log->close();
        }

        $stmt = $conn->prepare("UPDATE users SET `$field` = ? WHERE id = ?");
        $stmt->bind_param("si", $value, $user_id);

    // ==========================
    // UPDATE SETTINGS TABLE
    // ==========================
    } else {

        // Bio maps to the 'Bio' column (capital B)
        $column = ($field === 'bio') ? 'Bio' : $field;

        $stmt = $conn->prepare("UPDATE user_settings SET `$column` = ? WHERE user_id = ?");
        $stmt->bind_param("si", $value, $user_id);
    }

    $stmt->execute();
    $stmt->close();

    respond(true, '', ['field' => $field]);

} catch (mysqli_sql_exception $e) {
    // Shows e.g. "Unknown column 'profile_type'" instead of a silent failure.
    // Once everything works, replace the message with a generic one.
    respond(false, 'Database error: ' . $e->getMessage());
}