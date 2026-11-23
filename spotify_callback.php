<?php
session_start();
include 'config.php';

$client_id     = '4354ff2676b3446ab876bdebc522c064';
$client_secret = '289ba3d5f4c5403f9facd7946e8d4ea2';
$redirect_uri  = 'https://localhost/Peura/spotify_callback.php';

// Step 1: redirect to Spotify
if (!isset($_GET['code'])) {
    $url = 'https://accounts.spotify.com/authorize?'
        . http_build_query([
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'user-read-email user-read-private',
        ]);
    header("Location: $url");
    exit;
}

// Step 2: exchange code for token
$credentials  = base64_encode($client_id . ':' . $client_secret);
$token_res = file_get_contents('https://accounts.spotify.com/api/token', false,
    stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Authorization: Basic $credentials\r\nContent-Type: application/x-www-form-urlencoded",
        'content' => http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $_GET['code'],
            'redirect_uri' => $redirect_uri,
        ]),
    ]])
);

$token = json_decode($token_res, true);

if (!isset($token['access_token'])) {
    die("Spotify login failed — could not get access token. <a href='login.php'>Try again</a>");
}

// Step 3: get user info
$user_res = file_get_contents('https://api.spotify.com/v1/me', false,
    stream_context_create(['http' => [
        'header' => 'Authorization: Bearer ' . $token['access_token'],
    ]])
);

$suser = json_decode($user_res, true);

if (!isset($suser['email'])) {
    die("Spotify didn't share your email. Make sure your Spotify account has an email. <a href='login.php'>Try again</a>");
}

$email = $conn->real_escape_string($suser['email']);
$name  = $conn->real_escape_string($suser['display_name'] ?? 'user');
$pic   = $conn->real_escape_string($suser['images'][0]['url'] ?? '');

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