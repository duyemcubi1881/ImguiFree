<?php
// File helper để kiểm tra IP Outgoing của máy chủ Hosting
header('Content-Type: text/html; charset=utf-8');

echo "<h3>Hệ thống kiểm tra thông tin Máy chủ Render</h3>";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.ipify.org");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
$ip = curl_exec($ch);

if ($ip === false) {
    echo "<p style='color:red;'>Lỗi cURL khi kết nối để lấy IP: " . htmlspecialchars(curl_error($ch)) . "</p>";
} else {
    echo "<p style='font-size: 16px;'>Địa chỉ IP Outgoing của Render là: <strong style='color:blue; font-size: 20px;'>" . htmlspecialchars($ip) . "</strong></p>";
    echo "<p>Hãy gửi địa chỉ IP này cho Admin của Funlink để họ whitelist (cho vào danh sách trắng) nhé!</p>";
}
curl_close($ch);
?>
