<?php
// Bật hiển thị lỗi để debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Hàm lấy địa chỉ IP của client
function get_client_ip() {
    $ipaddress = '';
    if (isset($_SERVER['HTTP_CLIENT_IP']))
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_X_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    else if(isset($_SERVER['REMOTE_ADDR']))
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipaddress = 'UNKNOWN';
        
    if (empty($ipaddress)) {
        $ipaddress = 'UNKNOWN';
    }
    return explode(',', $ipaddress)[0];
}

// Hàm gửi cURL GET request
function curl_get($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // Bỏ qua xác minh SSL để tránh lỗi chứng chỉ trên hosting
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// Hàm gọi Backend Render để tạo Key 3 giờ
function generate_key_from_backend() {
    if (!extension_loaded('curl')) {
        return ['status' => 'error', 'message' => 'PHP cURL extension chưa được kích hoạt trên hosting.'];
    }

    // Tạo file lưu trữ cookie tạm thời để duy trì session (lưu trong thư mục hiện tại tránh lỗi open_basedir của hosting)
    $cookie_file = __DIR__ . '/cookie_' . bin2hex(random_bytes(8)) . '.txt';
    
    // 1. Đăng nhập vào Backend
    $login_url = BACKEND_BASE_URL . '/api/login';
    $login_payload = json_encode([
        'username' => BACKEND_USERNAME,
        'password' => BACKEND_PASSWORD
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $login_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $login_payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file); // Lưu cookie vào file
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    ]);
    
    $login_response = curl_exec($ch);
    $login_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($login_status !== 200) {
        @unlink($cookie_file);
        $res_json = json_decode($login_response, true);
        $msg = isset($res_json['error']) ? $res_json['error'] : 'Đăng nhập backend thất bại.';
        return ['status' => 'error', 'message' => "Lỗi Đăng Nhập Backend (HTTP {$login_status}): {$msg}"];
    }
    
    // 2. Tạo Key
    $create_url = BACKEND_BASE_URL . '/api/createkey';
    $create_payload = json_encode([
        'hours' => KEY_DURATION_HOURS
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $create_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $create_payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file); // Gửi kèm cookie đã lưu
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    ]);
    
    $create_response = curl_exec($ch);
    $create_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Xóa file cookie tạm thời
    @unlink($cookie_file);
    
    $res_json = json_decode($create_response, true);
    
    if ($create_status === 201 && isset($res_json['key'])) {
        return [
            'status' => 'success',
            'key' => $res_json['key'],
            'duration_label' => isset($res_json['duration_label']) ? $res_json['duration_label'] : '3 giờ'
        ];
    } else {
        $msg = isset($res_json['error']) ? $res_json['error'] : 'Không tạo được key từ backend.';
        return ['status' => 'error', 'message' => "Lỗi Tạo Key Backend (HTTP {$create_status}): {$msg}"];
    }
}

// Lấy tham số
$action = isset($_GET['action']) ? $_GET['action'] : '';
$token = isset($_GET['token']) ? $_GET['token'] : '';
$error = '';
$success_key = '';
$session = null;

// Lấy địa chỉ IP người dùng
$ip = get_client_ip();

// Kiểm tra giới hạn số lần nhận key trong ngày (Tối đa 2 lần/ngày)
$claims_today = get_ip_claim_count_today($ip);
$is_blocked = false;

if ($claims_today >= MAX_CLAIMS_PER_DAY) {
    $is_blocked = true;
    
    // Ngoại lệ: Nếu họ đang truy cập bằng token đã nhận key thành công trước đó thì cho xem lại key
    if (!empty($token)) {
        $check_session = get_session($token);
        if ($check_session && $check_session['status'] === 'claimed') {
            $is_blocked = false;
        }
    }
}

if ($is_blocked) {
    $error = "Bạn đã đạt giới hạn lấy key tối đa trong ngày (tối đa " . MAX_CLAIMS_PER_DAY . " lần/ngày). Vui lòng quay lại vào ngày mai!";
    $action = ''; // Vô hiệu hóa mọi hành động nếu bị chặn
}

// Tự động tạo token nếu truy cập trang chủ không có token
if (empty($token) && empty($action) && !$is_blocked) {
    $token = bin2hex(random_bytes(16));
    create_session($token, $ip);
    header("Location: index.php?token=" . urlencode($token));
    exit;
}

// Load session hiện tại nếu có token
if (!empty($token)) {
    $session = get_session($token);
}

// --- XỬ LÝ CÁC ACTION ---

if ($action === 'getlink') {
    if (!$session) {
        $error = "Phiên làm việc không tồn tại hoặc đã hết hạn.";
    } else if ($session['status'] === 'claimed') {
        header("Location: index.php?status=success&token=" . urlencode($token));
        exit;
    } else {
        // Tạo link callback
        $callback_url = BASE_URL . '/index.php?action=verify&token=' . urlencode($token);
        
        // Kiểm tra cấu hình FUNLINK
        if (FUNLINK_API_KEY === 'YOUR_FUNLINK_API_KEY_HERE' || empty(FUNLINK_API_KEY)) {
            // Chế độ test không cần FUNLINK, tự động tạo key luôn
            $res = generate_key_from_backend();
            if ($res['status'] === 'success') {
                claim_session($token, $res['key']);
                header("Location: index.php?status=success&token=" . urlencode($token));
                exit;
            } else {
                $error = $res['message'];
            }
        } else {
            // Cập nhật thời gian tương tác gần nhất vào CSDL trước khi chuyển hướng người dùng đi vượt link
            update_session_time($token);
            
            // Gọi API của funlink.io để rút gọn link
            $api_request_url = FUNLINK_API_URL . '?apikey=' . urlencode(FUNLINK_API_KEY) . '&url=' . urlencode($callback_url);
            
            // Thực hiện gọi cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_request_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            $response = curl_exec($ch);
            
            if ($response === false) {
                $curl_err = curl_error($ch);
                curl_close($ch);
                $error_msg = "Không thể kết nối máy chủ Funlink (Lỗi cURL: " . $curl_err . "). Vui lòng tải lại trang!";
            } else {
                curl_close($ch);
                $res_data = json_decode($response, true);
                
                if ($res_data && isset($res_data['id'])) {
                    $short_url = 'https://funlink.io/' . $res_data['id'];
                    header("Location: " . $short_url);
                    exit;
                } else {
                    $error_msg = "Phản hồi lỗi từ Funlink: " . htmlspecialchars(substr($response, 0, 300));
                }
            }
            $error = "Lỗi hệ thống: " . $error_msg;
        }
    }
} 
else if ($action === 'verify') {
    // Nhận callback sau khi vượt funlink.io (hoàn thành)
    if (!$session) {
        $error = "Token xác thực không hợp lệ.";
    } else {
        // Kiểm tra thời gian chênh lệch để chống bypass / tự động chuyển hướng sớm
        $time_elapsed = time() - $session['updated_at'];
        if ($time_elapsed < 25) { // Người dùng phải mất tối thiểu 25 giây để làm nhiệm vụ
            $error = "Hệ thống phát hiện bạn quay lại quá nhanh (hoàn thành trước thời gian tối thiểu 25s). Vui lòng thực hiện lại đúng các bước hướng dẫn!";
        } else if ($session['status'] === 'pending') {
            // Gọi sang backend tạo key luôn
            $res = generate_key_from_backend();
            if ($res['status'] === 'success') {
                claim_session($token, $res['key']);
                header("Location: index.php?status=success&token=" . urlencode($token));
                exit;
            } else {
                $error = $res['message'];
            }
        } else {
            header("Location: index.php?token=" . urlencode($token));
            exit;
        }
    }
}
else if (isset($_GET['status']) && $_GET['status'] === 'success') {
    if ($session && $session['status'] === 'claimed') {
        $success_key = $session['key_value'];
    } else {
        header("Location: index.php");
        exit;
    }
}

// Lấy thông tin bước hiện tại để hiển thị giao diện
$step = 1;
if ($session) {
    $step = intval($session['current_step']);
    if ($session['status'] === 'claimed') {
        $step = 5;
        $success_key = $session['key_value'];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Authentic Key — Premium Bypass System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* Reset and Design Variables */
*, ::before, ::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
  --bg: #020205;
  --panel: rgba(8, 8, 20, 0.65);
  --border: rgba(255, 255, 255, 0.08);
  --border-active: rgba(139, 92, 246, 0.3);
  
  --primary: #8b5cf6;
  --primary-glow: rgba(139, 92, 246, 0.35);
  --secondary: #06b6d4;
  --secondary-glow: rgba(6, 182, 212, 0.3);
  --accent: #ec4899;
  --accent-glow: rgba(236, 72, 153, 0.25);
  
  --text: #f3f4f6;
  --text-muted: #9ca3af;
  --text-dark: #4b5563;
  
  --font-sans: 'Plus Jakarta Sans', system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', monospace;
  --radius: 16px;
  --radius-lg: 24px;
}

html { scroll-behavior: smooth; }
body {
  font-family: var(--font-sans);
  background-color: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px 16px 60px;
  overflow-x: hidden;
  position: relative;
  -webkit-font-smoothing: antialiased;
}

/* Cyber Ambient Background */
.bg-overlay {
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 0;
  overflow: hidden;
}
.bg-radial {
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at 10% 20%, rgba(139, 92, 246, 0.12) 0%, transparent 45%),
              radial-gradient(circle at 90% 80%, rgba(236, 72, 153, 0.08) 0%, transparent 45%),
              radial-gradient(circle at 50% 50%, rgba(6, 182, 212, 0.06) 0%, transparent 50%);
  animation: bgMove 25s ease-in-out infinite alternate;
}
@keyframes bgMove {
  0% { transform: scale(1); }
  100% { transform: scale(1.05) translate(1%, -1%); }
}
.bg-grid {
  position: absolute;
  inset: 0;
  background-image: linear-gradient(rgba(255, 255, 255, 0.007) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255, 255, 255, 0.007) 1px, transparent 1px);
  background-size: 40px 40px;
  mask-image: radial-gradient(ellipse at center, black, transparent 80%);
  -webkit-mask-image: radial-gradient(ellipse at center, black, transparent 80%);
}
.orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(120px);
  animation: orbFloat var(--d, 24s) ease-in-out infinite var(--dl, 0s) alternate;
}
.o1 { width: 500px; height: 400px; background: var(--primary); top: -150px; left: -100px; opacity: 0.12; --d: 28s; }
.o2 { width: 400px; height: 500px; background: var(--accent); bottom: -200px; right: -80px; opacity: 0.08; --d: 32s; --dl: -16s; }

/* Wrapper Container */
.wrapper {
  position: relative;
  z-index: 10;
  width: 100%;
  max-width: 480px;
  animation: slideUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes slideUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Brand Header */
.brand-header {
  text-align: center;
  margin-bottom: 26px;
}
.logo-container {
  width: 60px;
  height: 60px;
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(139, 92, 246, 0.15), rgba(6, 182, 212, 0.05));
  border: 1px solid var(--border-active);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 8px 32px rgba(139, 92, 246, 0.15);
  margin-bottom: 12px;
  position: relative;
  animation: pulseLogo 3s ease-in-out infinite;
}
@keyframes pulseLogo {
  0%, 100% { box-shadow: 0 8px 30px rgba(139, 92, 246, 0.15); }
  50% { box-shadow: 0 8px 40px rgba(6, 182, 212, 0.3); }
}
.logo-container::after {
  content: '';
  position: absolute;
  inset: -1px;
  border-radius: 18px;
  padding: 1px;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  pointer-events: none;
}
.logo-container svg {
  width: 26px;
  height: 26px;
  color: #c084fc;
}
.brand-title {
  font-size: 24px;
  font-weight: 800;
  letter-spacing: -0.5px;
  background: linear-gradient(135deg, #ffffff 40%, #c084fc 80%, #22d3ee);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  text-transform: uppercase;
}
.brand-sub {
  font-size: 10px;
  color: var(--secondary);
  font-family: var(--font-mono);
  margin-top: 5px;
  letter-spacing: 2px;
  text-transform: uppercase;
  font-weight: 600;
}

/* Glass Card */
.glass-card {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 34px 28px;
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.05);
  position: relative;
  overflow: hidden;
}
.glass-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--primary), var(--secondary), transparent);
}

/* System HUD Status Panel */
.hud-panel {
  background: rgba(255, 255, 255, 0.012);
  border: 1px solid rgba(255, 255, 255, 0.03);
  border-radius: var(--radius);
  padding: 14px 18px;
  margin-bottom: 20px;
}
.hud-item {
  display: flex;
  justify-content: space-between;
  font-size: 10.5px;
  font-family: var(--font-mono);
  color: var(--text-muted);
  margin-bottom: 6px;
  text-transform: uppercase;
}
.hud-item:last-child { margin-bottom: 0; }
.hud-value {
  color: var(--text);
  font-weight: 600;
}
.hud-value.online {
  color: var(--secondary);
  text-shadow: 0 0 8px rgba(6, 182, 212, 0.4);
}

/* High Tech Scanner Timer */
.scanner-box {
  background: rgba(255, 255, 255, 0.015);
  border: 1px solid rgba(255, 255, 255, 0.03);
  border-radius: var(--radius);
  padding: 28px 20px;
  margin: 20px 0 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  overflow: hidden;
}
.scanner-box::after {
  content: '';
  position: absolute;
  left: 0; right: 0; top: 0; height: 1.5px;
  background: linear-gradient(90deg, transparent, var(--secondary), transparent);
  animation: scan 2.5s linear infinite;
}
@keyframes scan {
  0% { top: 0%; opacity: 0; }
  10% { opacity: 1; }
  90% { opacity: 1; }
  100% { top: 100%; opacity: 0; }
}
.timer-val {
  font-size: 46px;
  font-weight: 800;
  font-family: var(--font-mono);
  color: var(--text);
  line-height: 1;
  margin-bottom: 8px;
  text-shadow: 0 0 15px var(--primary-glow);
}
.timer-val.ready {
  color: var(--secondary);
  text-shadow: 0 0 20px var(--secondary-glow);
  animation: pulseReady 1.5s ease-in-out infinite;
}
@keyframes pulseReady {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.05); }
}
.timer-lbl {
  font-size: 11px;
  color: var(--text-muted);
  font-family: var(--font-mono);
  text-transform: uppercase;
  letter-spacing: 1.5px;
}

/* Neon Button */
.btn-cyber {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  padding: 16px 24px;
  border-radius: var(--radius);
  font-size: 13.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  border: 1px solid rgba(255, 255, 255, 0.04);
  cursor: not-allowed;
  text-decoration: none;
  background: rgba(255, 255, 255, 0.02);
  color: var(--text-dark);
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  position: relative;
}
.btn-cyber.active {
  cursor: pointer;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: #ffffff;
  border: 1px solid rgba(255, 255, 255, 0.1);
  box-shadow: 0 6px 20px rgba(139, 92, 246, 0.35);
}
.btn-cyber.active:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 25px rgba(6, 182, 212, 0.45);
  border-color: rgba(255, 255, 255, 0.2);
}
.btn-cyber svg {
  width: 18px;
  height: 18px;
  transition: transform 0.2s;
  stroke: currentColor;
}
.btn-cyber.active:hover svg {
  transform: translateX(4px);
}

/* Key container */
.key-container {
  background: rgba(2, 2, 8, 0.8);
  border: 1px solid rgba(6, 182, 212, 0.3);
  border-radius: var(--radius);
  padding: 24px 20px;
  margin: 20px 0 24px;
  text-align: center;
  position: relative;
  box-shadow: 0 8px 32px rgba(6, 182, 212, 0.05);
}
.key-container::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--secondary), transparent);
}
.key-title {
  font-size: 10px;
  color: var(--text-muted);
  font-family: var(--font-mono);
  text-transform: uppercase;
  letter-spacing: 2px;
  margin-bottom: 8px;
}
.key-display {
  font-family: var(--font-mono);
  font-size: 20px;
  font-weight: 700;
  color: var(--secondary);
  word-break: break-all;
  text-shadow: 0 0 10px rgba(6, 182, 212, 0.4);
  user-select: all;
  cursor: pointer;
}

/* Instructions */
.instruction-box {
  background: rgba(255, 255, 255, 0.015);
  border: 1px solid rgba(255, 255, 255, 0.03);
  border-radius: var(--radius);
  padding: 18px 20px;
  margin-top: 24px;
}
.instruction-title {
  font-size: 11px;
  font-weight: 700;
  color: var(--secondary);
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 7px;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  font-family: var(--font-mono);
}
.instruction-title svg {
  width: 14px;
  height: 14px;
}
.instruction-list {
  list-style: none;
}
.instruction-list li {
  font-size: 12.5px;
  color: var(--text-muted);
  display: flex;
  align-items: start;
  gap: 8px;
  line-height: 1.5;
  margin-bottom: 8px;
}
.instruction-list li:last-child { margin-bottom: 0; }
.instruction-list li::before {
  content: '■';
  font-size: 8px;
  color: var(--secondary);
  margin-top: 3px;
  flex-shrink: 0;
  box-shadow: 0 0 6px var(--secondary);
}
.instruction-list li strong {
  color: var(--text);
}

/* Alerts */
.alert-error {
  display: flex;
  align-items: start;
  gap: 12px;
  padding: 16px 18px;
  border-radius: var(--radius);
  margin-bottom: 24px;
  font-size: 13px;
  line-height: 1.5;
  background: rgba(239, 68, 68, 0.06);
  border: 1px solid rgba(239, 68, 68, 0.2);
  color: #fca5a5;
  animation: slideUp .4s both;
}
.alert-error svg {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  margin-top: 2px;
}

.btn-re {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: var(--text-muted);
  text-decoration: none;
  font-size: 12px;
  font-weight: 600;
  font-family: var(--font-mono);
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-top: 20px;
  transition: color 0.2s;
}
.btn-re:hover { color: var(--accent); }

.footer-text {
  text-align: center;
  margin-top: 32px;
  font-size: 11px;
  color: var(--text-dark);
  font-family: var(--font-mono);
  letter-spacing: 1.5px;
}
.footer-text a {
  color: var(--accent);
  text-decoration: none;
  transition: color 0.2s;
}
.footer-text a:hover { color: #f472b6; }

/* Toast Notification */
#toast-hud {
  position: fixed;
  bottom: 30px;
  right: 30px;
  z-index: 9999;
  background: var(--secondary);
  color: #020205;
  font-weight: 800;
  font-size: 12px;
  padding: 12px 20px;
  border-radius: 12px;
  opacity: 0;
  transform: translateY(15px);
  transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  pointer-events: none;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 10px 30px rgba(6, 182, 212, 0.3);
}
#toast-hud.show {
  opacity: 1;
  transform: none;
}
#toast-hud svg {
  width: 16px;
  height: 16px;
  stroke: currentColor;
}

@media (max-width: 480px) {
  .glass-card { padding: 26px 18px; }
  .brand-title { font-size: 21px; }
  #toast-hud { bottom: 20px; right: 16px; left: 16px; justify-content: center; }
}
</style>
</head>
<body>
<div class="bg-overlay">
  <div class="bg-radial"></div>
  <div class="bg-grid"></div>
  <div class="orb o1"></div>
  <div class="orb o2"></div>
</div>

<div id="toast-hud">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
  <span>BẢN CHÉP KEY ĐÃ SẴN SÀNG!</span>
</div>

<div class="wrapper">
  <div class="brand-header">
    <div class="logo-container">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.955 11.955 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
    </div>
    <h1 class="brand-title">Authentic Key</h1>
    <div class="brand-sub">Auto Key Bypass HUD</div>
  </div>

  <div class="glass-card">
    
    <!-- HUD Telemetry Panel -->
    <div class="hud-panel">
      <div class="hud-item">
        <span>Handshake Protocol:</span>
        <span class="hud-value">TLS 1.3 / AES-256</span>
      </div>
      <div class="hud-item">
        <span>License Server:</span>
        <span class="hud-value online">Active (api3)</span>
      </div>
      <div class="hud-item">
        <span>Client Session IP:</span>
        <span class="hud-value"><?php echo htmlspecialchars($ip); ?></span>
      </div>
    </div>

    <?php if (!empty($error)): ?>
      <!-- ══ SYSTEM ERROR ══ -->
      <div class="alert-error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
        <div><strong>Lỗi hệ thống:</strong> <?php echo htmlspecialchars($error); ?></div>
      </div>
      <a href="index.php" class="btn-re">Quay lại từ đầu</a>

    <?php elseif ($step === 5): ?>
      <!-- ══ SUCCESS PAGE ══ -->
      <div class="key-container" onclick="copyKey()">
        <div class="key-title">Mã kích hoạt key <?php echo KEY_DURATION_HOURS; ?> giờ của bạn (Bấm để copy)</div>
        <div class="key-display" id="keyVal"><?php echo htmlspecialchars($success_key); ?></div>
      </div>

      <button onclick="copyKey()" class="btn-cyber active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 7.5V6.108c0-1.135.845-2.098 1.976-2.192.373-.03.748-.057 1.123-.08M15.75 18H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08M15.75 18.75v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5A3.375 3.375 0 006.375 7.5H5.25m11.9-3.664A2.251 2.251 0 0015 2.25h-1.5a2.251 2.251 0 00-2.15 1.586m5.8 0c.065.21.1.433.1.664v.75h-6V4.5c0-.231.035-.454.1-.664M6.75 7.5H4.875c-.621 0-1.125.504-1.125 1.125v12c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V16.5a9 9 0 00-9-9z"/></svg> <span>Sao chép kích hoạt</span>
      </button>

      <div class="instruction-box">
        <div class="instruction-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Thông số key
        </div>
        <ul class="instruction-list">
          <li>Loại Key: <strong>Kích Hoạt Client (Hạn <?php echo KEY_DURATION_HOURS; ?> giờ)</strong></li>
          <li>Giới Hạn: <strong>1 thiết bị duy nhất</strong>.</li>
          <li>Cảm ơn bạn đã đồng hành vượt link ủng hộ server duy trì hoạt động!</li>
        </ul>
      </div>

    <?php else: ?>
      <!-- ══ COUNTDOWN TIMER PAGE ══ -->
      <div class="scanner-box">
        <div class="timer-val" id="countdownVal">5</div>
        <div class="timer-lbl" id="countdownText">Vui lòng chờ giây lát...</div>
      </div>

      <?php if ($step === 1): ?>
        <a id="actionBtn" href="#" class="btn-cyber">
          <span>Hệ thống đang tải...</span>
        </a>
      <?php else: ?>
        <button id="actionBtn" class="btn-cyber">
          <span>Hệ thống đang tải...</span>
        </button>
      <?php endif; ?>

      <div class="instruction-box">
        <div class="instruction-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg> Chỉ dẫn
        </div>
        <ul class="instruction-list">
          <?php if ($step === 1): ?>
            <li>Vượt link của <strong>funlink.io</strong> để kích hoạt hệ thống lưu token xác minh.</li>
          <?php else: ?>
            <li>Nhấp vào nút sau khi đếm ngược kết thúc để mở link tài trợ.</li>
            <li>Sau khi vượt qua quảng cáo, hệ thống sẽ tự động cấp Key.</li>
          <?php endif; ?>
          <li>Vui lòng tắt các phần mềm chặn quảng cáo (AdBlock) để đảm bảo không bị lỗi link.</li>
        </ul>
      </div>

      <!-- Countdown Event Logic -->
      <script>
        document.addEventListener("DOMContentLoaded", function() {
          let count = 5;
          const countEl = document.getElementById("countdownVal");
          const textEl = document.getElementById("countdownText");
          const btn = document.getElementById("actionBtn");
          const token = "<?php echo urlencode($token); ?>";
          
          const timer = setInterval(() => {
            count--;
            if (count > 0) {
              countEl.textContent = count;
            } else {
              clearInterval(timer);
              countEl.textContent = "✓";
              countEl.classList.add("ready");
              textEl.textContent = "HỆ THỐNG ĐÃ SẴN SÀNG!";
              
              // Unlock neon button
              btn.classList.add("active");
              btn.querySelector("span").textContent = "Bắt đầu vượt link";
              btn.href = "index.php?action=getlink&token=" + token;
              
              // Add arrow icon dynamically to the active button
              const svgIcon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
              svgIcon.setAttribute("viewBox", "0 0 24 24");
              svgIcon.setAttribute("fill", "none");
              svgIcon.setAttribute("stroke-width", "2.5");
              svgIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>';
              btn.appendChild(svgIcon);
            }
          }, 1000);
        });
      </script>
    <?php endif; ?>

  </div>

  <div class="footer-text">AUTHENTIC BY D.DUY · <a href="index.php">RESET SESSION</a></div>
</div>

<script>
function copyKey() {
  const t = document.getElementById('keyVal')?.textContent?.trim();
  if (!t) return;
  const show = () => {
    const el = document.getElementById('toast-hud');
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 2500);
  };
  if (navigator.clipboard) {
    navigator.clipboard.writeText(t).then(show).catch(() => fallbackCopy(t, show));
  } else {
    fallbackCopy(t, show);
  }
}
function fallbackCopy(t, cb) {
  const a = document.createElement('textarea');
  a.value = t;
  a.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
  document.body.appendChild(a);
  a.select();
  try {
    document.execCommand('copy');
    cb();
  } catch (e) {
    alert('Không thể tự sao chép, hãy bôi đen mã để copy.');
  }
  document.body.removeChild(a);
}
</script>
</body>
</html>
