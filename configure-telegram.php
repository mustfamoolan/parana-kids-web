<?php

/**
 * Telegram Mini App Configuration Script
 * Run this script from the terminal to set up your Telegram Bot Menu Button:
 * php configure-telegram.php <YOUR_STOREFRONT_URL>
 */

echo "==================================================\n";
echo "    Telegram Mini App Setup - Parana Kids\n";
echo "==================================================\n\n";

$botToken = "8789857780:AAF1VI67V_fTbClk0XA3VvYtBbykVDlJbU4";

// Get URL from command line argument or prompt the user
$webAppUrl = isset($argv[1]) ? trim($argv[1]) : '';

if (empty($webAppUrl)) {
    echo "Please enter your Storefront Web URL (e.g. https://parana-kids-web.laravel.cloud):\n> ";
    $handle = fopen("php://stdin", "r");
    $webAppUrl = trim(fgets($handle));
    fclose($handle);
}

if (empty($webAppUrl) || !filter_var($webAppUrl, FILTER_VALIDATE_URL)) {
    echo "\n[ERROR] Invalid URL provided. Setup aborted.\n";
    exit(1);
}

// Ensure URL does not end with a slash for consistency
$webAppUrl = rtrim($webAppUrl, '/');

echo "\nConfiguring Telegram Bot Menu Button...\n";
echo "Bot Token: " . substr($botToken, 0, 15) . "...\n";
echo "WebApp URL: " . $webAppUrl . "\n\n";

// 1. Set Chat Menu Button
$menuButtonData = [
    'menu_button' => [
        'type' => 'web_app',
        'text' => '🛍️ تصفح المتجر',
        'web_app' => [
            'url' => $webAppUrl
        ]
    ]
];

$ch = curl_init("https://api.telegram.org/bot{$botToken}/setChatMenuButton");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($menuButtonData));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resData = json_decode($response, true);

if ($httpCode === 200 && isset($resData['ok']) && $resData['ok'] === true) {
    echo "[SUCCESS] Bot Menu Button configured successfully!\n";
    echo "Now, the button '🛍️ تصفح المتجر' will appear in the bot chat, opening your storefront.\n";
} else {
    echo "[ERROR] Failed to configure Menu Button.\n";
    echo "Response: " . $response . "\n";
}

echo "\n==================================================\n";
