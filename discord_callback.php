<?php
session_start();
include 'config.php';

$client_id     = '1505179992345022565';
$client_secret = 'Ztdmo97KcR5--XUFMIdmteH4SzZ-jkvd';
$redirect_uri  = 'http://localhost/Peura/discord_callback.php';

// Step 1: redirect to Discord
if (!isset($_GET['code'])) {
    $url = 'https://discord.com/oauth2/authorize?'
        . http_build_query([
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'identify email',
        ]);
    header("Location: $url");
    exit;
}

// Step 2: exchange code for token
$token_res = file_get_contents('https://discord.com/api/oauth2/token', false,
    stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $redirect_uri,
        ]),
    ]])
);

$token = json_decode($token_res, true);

if (!isset($token['access_token'])) {
    die("Discord login failed — could not get access token. <a href='login.php'>Try again</a>");
}

// Step 3: get user info
$user_res = file_get_contents('https://discord.com/api/users/@me', false,
    stream_context_create(['http' => [
        'header' => 'Authorization: Bearer ' . $token['access_token'],
    ]])
);

$duser = json_decode($user_res, true);

if (!isset($duser['email'])) {
    die("Discord didn't share your email. <a href='login.php'>Try again</a>");
}

$email    = $conn->real_escape_string($duser['email']);
$name     = $conn->real_escape_string($duser['global_name'] ?? $duser['username'] ?? 'user');
$avatar   = '';

// build avatar URL if they have one
if (!empty($duser['avatar'])) {
    $avatar = 'https://cdn.discordapp.com/avatars/' . $duser['id'] . '/' . $duser['avatar'] . '.png';
}
$pic = $conn->real_escape_string($avatar);

// Step 4: find or create user
$row = $conn->query("SELECT id FROM users WHERE email='$email'")->fetch_assoc();

if ($row) {
    $_SESSION['user_id'] = $row['id'];
} else {
    $base     = strtolower(str_replace(' ', '_', $name));
    $username = $conn->real_escape_string($base . rand(10, 99));

    while ($conn->query("SELECT id FROM users WHERE username='$username'")->num_rows > 0) {
        $username = $conn->real_escape_string($base . rand(100, 999));
    }

    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, email, password, profile_pic)
                  VALUES ('$username','$email','$password','$pic')");

    $new_id = $conn->insert_id;
    $conn->query("INSERT INTO user_settings (user_id) VALUES ($new_id)");
    $_SESSION['user_id'] = $new_id;
}

header("Location: index.php");
exit;
?>