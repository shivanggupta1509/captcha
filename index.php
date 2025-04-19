<?php
session_start();

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if GD library is enabled
if (!extension_loaded('gd')) {
    die('GD library is not enabled. Please enable it in your PHP configuration.');
}

// Function to get client IP
function getClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $headers = [
        'REMOTE_ADDR' => $ip,
        'HTTP_CLIENT_IP' => $_SERVER['HTTP_CLIENT_IP'] ?? null,
        'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null
    ];
    if (!empty($headers['HTTP_CLIENT_IP'])) {
        $ip = $headers['HTTP_CLIENT_IP'];
    } elseif (!empty($headers['HTTP_X_FORWARDED_FOR'])) {
        $ip = $headers['HTTP_X_FORWARDED_FOR'];
    }
    return ['ip' => $ip, 'headers' => $headers];
}

// Function to check if IP is non-Indian
function isNonIndianIp() {
    $ip_data = getClientIp();
    $ip = $ip_data['ip'];
    
    // Debug logging
    $log = date('Y-m-d H:i:s') . " - IP Check - IP: $ip\n";
    $log .= "IP Headers: " . json_encode($ip_data['headers']) . "\n";
    
    // Try ipinfo.io
    $url = "https://ipinfo.io/$ip/json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    $log .= "ipinfo.io HTTP Status: $http_code\n";
    if ($curl_error) {
        $log .= "ipinfo.io cURL Error: $curl_error\n";
    }
    
    if ($response !== false && $http_code === 200) {
        $data = json_decode($response, true);
        $log .= "ipinfo.io JSON Response: " . json_encode($data) . "\n";
        $country_code = $data['country'] ?? 'unknown';
        $log .= "ipinfo.io Country Code: $country_code\n";
        file_put_contents('ip_debug.log', $log, FILE_APPEND);
        return ['is_non_indian' => $country_code !== 'IN', 'country_code' => $country_code, 'ip' => $ip, 'json_response' => $data, 'error' => ''];
    }
    
    $log .= "ipinfo.io failed, trying ipapi.co\n";
    
    // Fallback to ipapi.co
    $url = "https://ipapi.co/$ip/json/";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    $log .= "ipapi.co HTTP Status: $http_code\n";
    if ($curl_error) {
        $log .= "ipapi.co cURL Error: $curl_error\n";
    }
    
    if ($response !== false && $http_code === 200) {
        $data = json_decode($response, true);
        $log .= "ipapi.co JSON Response: " . json_encode($data) . "\n";
        $country_code = $data['country_code'] ?? 'unknown';
        $log .= "ipapi.co Country Code: $country_code\n";
        file_put_contents('ip_debug.log', $log, FILE_APPEND);
        return ['is_non_indian' => $country_code !== 'IN', 'country_code' => $country_code, 'ip' => $ip, 'json_response' => $data, 'error' => ''];
    }
    
    $log .= "All APIs failed, assuming non-Indian IP\n";
    file_put_contents('ip_debug.log', $log, FILE_APPEND);
    return ['is_non_indian' => true, 'country_code' => 'unknown', 'ip' => $ip, 'json_response' => [], 'error' => 'All APIs failed'];
}

// Generate random captcha code
function generateCaptchaCode($length = 6) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $captcha = '';
    for ($i = 0; $i < $length; $i++) {
        $captcha .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $captcha;
}

// Create captcha image
function createCaptchaImage($code) {
    $image = imagecreatetruecolor(150, 50);
    if (!$image) {
        die('Failed to create image resource.');
    }

    // Colors
    $bg_color = imagecolorallocate($image, 255, 255, 255);
    $text_color = imagecolorallocate($image, 0, 0, 0);
    $noise_color = imagecolorallocate($image, 100, 100, 100);

    // Fill background
    imagefilledrectangle($image, 0, 0, 150, 50, $bg_color);

    // Add noise
    for ($i = 0; $i < 100; $i++) {
        imagesetpixel($image, rand(0, 150), rand(0, 50), $noise_color);
    }

    // Use built-in font
    imagestring($image, 5, 30, 15, $code, $text_color);

    // Add lines
    for ($i = 0; $i < 3; $i++) {
        imageline($image, rand(0, 150), rand(0, 50), rand(0, 150), rand(0, 50), $noise_color);
    }

    // Output image
    header('Content-Type: image/png');
    imagepng($image);
    imagedestroy($image);
    exit;
}

// Handle CAPTCHA verification and redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['captcha'])) {
    if (isset($_SESSION['captcha_code']) && strtoupper(trim($_POST['captcha'])) === $_SESSION['captcha_code']) {
        // CAPTCHA solved, redirect to random blog
        $blogs = ['blog1.html', 'blog2.html', 'blog3.html']; // Add your blog pages here
        $random_blog = $blogs[array_rand($blogs)];
        header("Location: $random_blog");
        exit;
    } else {
        $message = 'Incorrect CAPTCHA, please try again.';
    }
}

// Serve CAPTCHA image if requested
if (isset($_GET['get_captcha_image'])) {
    if (!isset($_SESSION['captcha_code'])) {
        $_SESSION['captcha_code'] = generateCaptchaCode();
    }
    createCaptchaImage($_SESSION['captcha_code']);
}

// Check if IP is non-Indian or force CAPTCHA for testing
$ip_check = isNonIndianIp();
$show_captcha = (isset($_GET['force_captcha']) && $_GET['force_captcha'] == '1') || $ip_check['is_non_indian'];

// If Indian IP and not forced, redirect to random blog
if (!$show_captcha) {
    $blogs = ['blog1.html', 'blog2.html', 'blog3.html']; // Add your blog pages here
    $random_blog = $blogs[array_rand($blogs)];
    header("Location: $random_blog");
    exit;
}

// Generate new CAPTCHA code for display
$_SESSION['captcha_code'] = generateCaptchaCode();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifying Your Connection</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 400px;
            width: 100%;
        }
        .logo {
            margin-bottom: 20px;
        }
        .logo h2 {
            color: #333;
            margin: 0;
        }
        h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
        }
        p {
            font-size: 16px;
            color: #666;
            margin-bottom: 20px;
        }
        .loading {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .dot {
            width: 10px;
            height: 10px;
            background: #f60;
            border-radius: 50%;
            margin: 0 5px;
            animation: bounce 0.4s infinite alternate;
        }
        .dot:nth-child(2) { animation-delay: 0.2s; }
        .dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes bounce {
            to { transform: translateY(-10px); }
        }
        .captcha-container {
            margin-top: 20px;
        }
        .captcha-container img {
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        input[type="submit"] {
            background: #f60;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        input[type="submit"]:hover {
            background: #e55;
        }
        .message {
            color: red;
            margin-top: 10px;
        }
        .debug-info {
            color: #333;
            font-size: 14px;
            margin-top: 10px;
            background: #f0f0f0;
            padding: 10px;
            border-radius: 4px;
            word-wrap: break-word;
        }
        .footer {
            margin-top: 20px;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h2>SecureWeb</h2>
        </div>
        <h1>Just a moment...</h1>
        <p>We're verifying that you're not a bot.</p>
        <div class="loading">
            <div class="dot"></div>
            <div class="dot"></div>
            <div class="dot"></div>
        </div>
        <div class="captcha-container">
            <img src="?get_captcha_image=1" alt="CAPTCHA Image">
            <form method="POST">
                <input type="text" name="captcha" placeholder="Enter CAPTCHA code" required>
                <input type="submit" value="Verify">
            </form>
            <?php if (isset($message) && $message): ?>
                <p class="message"><?php echo $message; ?></p>
            <?php else: ?>
                <p class="message">Debug: Check ip_debug.log for IP details</p>
            <?php endif; ?>
            <?php if (isset($_GET['debug_ip']) && $_GET['debug_ip'] == '1'): ?>
                <div class="debug-info">
                    <p>IP: <?php echo htmlspecialchars($ip_check['ip']); ?></p>
                    <p>Country Code: <?php echo htmlspecialchars($ip_check['country_code']); ?></p>
                    <p>Non-Indian: <?php echo $ip_check['is_non_indian'] ? 'Yes' : 'No'; ?></p>
                    <p>JSON Response: <?php echo htmlspecialchars(json_encode($ip_check['json_response'], JSON_PRETTY_PRINT)); ?></p>
                    <p>Error: <?php echo htmlspecialchars($ip_check['error']); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <div class="footer">
            Powered by SecureWeb
        </div>
    </div>
</body>
</html>