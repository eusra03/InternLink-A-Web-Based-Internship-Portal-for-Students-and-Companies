<?php
// Database configuration
$host = '127.0.0.1';
$port = '3306';
$dbname = 'internlink';
$username = 'root';
$password = '';

try {
    // Create PDO connection
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname",
        $username,
        $password,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to check user role
function getUserRole() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : null;
}

// Helper function to redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

// Helper function to sanitize input
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Base32 encode function
function base32_encode($input) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    $bits = '';
    for ($i = 0; $i < strlen($input); $i++) {
        $bits .= str_pad(decbin(ord($input[$i])), 8, '0', STR_PAD_LEFT);
    }
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0');
        }
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

// Base32 decode function
function base32_decode($input) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    $bits = '';
    $input = strtoupper($input);
    for ($i = 0; $i < strlen($input); $i++) {
        $pos = strpos($alphabet, $input[$i]);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $chunk = substr($bits, $i, 8);
        if (strlen($chunk) < 8) continue;
        $output .= chr(bindec($chunk));
    }
    return $output;
}

// Generate TOTP code
function generate_totp($secret, $time = null) {
    if ($time === null) {
        $time = time();
    }
    $time = floor($time / 30);
    $time = pack('N*', 0, $time);
    $secret = base32_decode($secret);
    $hmac = hash_hmac('sha1', $time, $secret, true);
    $offset = ord($hmac[19]) & 0xf;
    $code = (ord($hmac[$offset]) & 0x7f) << 24 |
            (ord($hmac[$offset + 1]) & 0xff) << 16 |
            (ord($hmac[$offset + 2]) & 0xff) << 8 |
            (ord($hmac[$offset + 3]) & 0xff);
    return str_pad($code % 1000000, 6, '0', STR_PAD_LEFT);
}

// Generate 2FA secret
function generate_2fa_secret() {
    return base32_encode(random_bytes(10));
}
?>
