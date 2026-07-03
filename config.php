<?php
// Configuration File for Getkey System

// 1. FUNLINK Configuration
define('FUNLINK_API_KEY', '65d4f6c0bb16481fbe5f6b69f9922bcb'); 
define('FUNLINK_API_URL', 'https://private.funlink.io/api/cong-khai/tao-lien-ket');

// 2. Backend Server Configuration (Render)
define('BACKEND_BASE_URL', 'https://aovduy-1-1cg0.onrender.com');
define('BACKEND_USERNAME', 'duyemcubi188');
define('BACKEND_PASSWORD', 'ngoducduy1107@');

// 3. Website Configuration
// Tự động nhận diện URL của website hiện tại để tạo link callback vượt link
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$current_dir = dirname($script_name);
// Xử lý dấu gạch chéo ngược trên Windows/IIS
$current_dir = str_replace('\\', '/', $current_dir);
if ($current_dir === '/') {
    $current_dir = '';
}
define('BASE_URL', $protocol . $domain . $current_dir);

// Key duration settings
define('KEY_DURATION_HOURS', 6);
define('SESSION_LIFETIME_MINUTES', 30); // Thời gian token chờ có hiệu lực để vượt link (phút)
define('MAX_CLAIMS_PER_DAY', 2); // Số lần tối đa một IP được lấy key trong ngày

// 4. Adsterra Configuration
define('ADSTERRA_SMARTLINK', 'https://globalimmaturelunatic.com/p6whufnkb?key=a9d4231ece4478fb6b44a7202733a49c');
?>
