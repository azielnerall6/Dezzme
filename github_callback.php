<?php
session_start();
include 'config.php';

$client_id     = 'Ov23liwcsXm4iaC6jgip';
$client_secret = 'f32cf4d3e3a23aac4996f61b303727e5c9fe2a17';
$redirect_uri  = 'http://localhost/Peura/github_callback.php';

// Step 1: redirect to GitHub
if (!isset($_GET['code'])) {
    $url = 'https://github.com/login/oauth/authorize?'
        . http_build_query([
            'client_id'    => $client_id,
            'redirect_uri' => $redirect_uri,
            'scope'        => 'user:email',
        ]);
    header("Location: $url");
    exit;
}

// Step 2: exchange code for token
$token_res = file_get_contents('https://github.com/login/oauth/access_token', false,
    stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
        'content' => http_build_query([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'code'          => $_GET['code'],
            'redirect_uri'  => $redirect_uri,
        ]),
    ]])
);

$token = json_decode($token_res, true);

if (!isset($token['access_token'])) {
    die("GitHub login failed — could not get access token. <a href='login.php'>Try again</a>");
}

// Step 3: get user info
$user_res = file_get_contents('https://api.github.com/user', false,
    stream_context_create(['http' => [
        'header' => "Authorization: Bearer " . $token['access_token'] . "\r\nUser-Agent: Peura",
    ]])
);

$guser = json_decode($user_res, true);

// Step 4: get email separately (GitHub sometimes hides it)
$email = '';
if (!empty($guser['email'])) {
    $email = $guser['email'];
} else {
    $email_res = file_get_contents('https://api.github.com/user/emails', false,
        stream_context_create(['http' => [
            'header' => "Authorization: Bearer " . $token['access_token'] . "\r\nUser-Agent: Peura",
        ]])
    );
    $emails = json_decode($email_res, true);
    foreach ($emails as $e) {
        if ($e['primary'] && $e['verified']) {
            $email = $e['email'];
            break;
        }
    }
}

if (!$email) {
    die("GitHub didn't share your email. Make sure your GitHub email is public or verified. <a href='login.php'>Try again</a>");
}

$email = $conn->real_escape_string($email);
$name  = $conn->real_escape_string($guser['name'] ?? $guser['login'] ?? 'user');
$pic   = $conn->real_escape_string($guser['avatar_url'] ?? '');

// Step 5: find or create user
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