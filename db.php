<?php
require_once __DIR__ . '/config.php';

// Đường dẫn tới file cơ sở dữ liệu SQLite
define('DB_FILE', __DIR__ . '/getkey.db');

/**
 * Kết nối tới cơ sở dữ liệu SQLite
 */
function db_connect() {
    try {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Khởi tạo bảng nếu chưa tồn tại
        $db->exec("CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            ip_address TEXT NOT NULL,
            hwid TEXT DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending', -- 'pending', 'verified', 'claimed'
            key_value TEXT DEFAULT NULL,
            current_step INTEGER NOT NULL DEFAULT 1, -- Bước hiện tại của người dùng (1, 2, 3, 4, 5)
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");
        
        // Kiểm tra nâng cấp bảng nếu cột current_step chưa tồn tại
        try {
            $db->query("SELECT current_step FROM sessions LIMIT 1");
        } catch (PDOException $e) {
            $db->exec("ALTER TABLE sessions ADD COLUMN current_step INTEGER NOT NULL DEFAULT 1");
        }

        // Kiểm tra nâng cấp bảng nếu cột hwid chưa tồn tại
        try {
            $db->query("SELECT hwid FROM sessions LIMIT 1");
        } catch (PDOException $e) {
            $db->exec("ALTER TABLE sessions ADD COLUMN hwid TEXT DEFAULT ''");
        }
        
        return $db;
    } catch (PDOException $e) {
        die("Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage());
    }
}

function create_session($token, $ip, $hwid = '') {
    $db = db_connect();
    $time = time();
    $ip = $ip ? $ip : 'UNKNOWN'; // Đảm bảo IP không bao giờ rỗng hoặc null
    $stmt = $db->prepare("INSERT INTO sessions (token, ip_address, hwid, status, current_step, created_at, updated_at) VALUES (:token, :ip, :hwid, 'pending', 1, :created_at, :updated_at)");
    return $stmt->execute([
        ':token' => $token,
        ':ip' => $ip,
        ':hwid' => $hwid,
        ':created_at' => $time,
        ':updated_at' => $time
    ]);
}

/**
 * Cập nhật HWID thiết bị của session
 */
function update_session_hwid($token, $hwid) {
    $db = db_connect();
    $time = time();
    $stmt = $db->prepare("UPDATE sessions SET hwid = :hwid, updated_at = :updated_at WHERE token = :token");
    return $stmt->execute([
        ':token' => $token,
        ':hwid' => $hwid,
        ':updated_at' => $time
    ]);
}

/**
 * Lấy thông tin phiên theo token
 */
function get_session($token) {
    $db = db_connect();
    $stmt = $db->prepare("SELECT * FROM sessions WHERE token = :token");
    $stmt->execute([':token' => $token]);
    return $stmt->fetch();
}

/**
 * Cập nhật trạng thái vượt bước hiện tại và chuyển sang bước tiếp theo
 */
function advance_step($token) {
    $db = db_connect();
    $time = time();
    // Tăng current_step lên 1
    $stmt = $db->prepare("UPDATE sessions SET current_step = current_step + 1, updated_at = :updated_at WHERE token = :token");
    return $stmt->execute([
        ':token' => $token,
        ':updated_at' => $time
    ]);
}

/**
 * Lưu key đã sinh từ server và chuyển sang trạng thái đã nhận (claimed)
 */
function claim_session($token, $key_value) {
    $db = db_connect();
    $time = time();
    $stmt = $db->prepare("UPDATE sessions SET status = 'claimed', key_value = :key_value, current_step = 5, updated_at = :updated_at WHERE token = :token");
    return $stmt->execute([
        ':token' => $token,
        ':key_value' => $key_value,
        ':updated_at' => $time
    ]);
}

/**
 * Cập nhật thời gian tương tác gần nhất của session để chống bypass sớm
 */
function update_session_time($token) {
    $db = db_connect();
    $time = time();
    $stmt = $db->prepare("UPDATE sessions SET updated_at = :updated_at WHERE token = :token");
    return $stmt->execute([
        ':token' => $token,
        ':updated_at' => $time
    ]);
}

/**
 * Xóa các phiên cũ hơn 24 giờ để giải phóng dung lượng
 */
/**
 * Đếm số lần một địa chỉ IP đã nhận key thành công trong ngày hôm nay (từ 00:00:00)
 */
function get_ip_claim_count_today($ip) {
    $db = db_connect();
    // Lấy thời điểm bắt đầu ngày hôm nay (00:00:00) theo giờ máy chủ
    $start_of_day = strtotime('today midnight');
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM sessions WHERE ip_address = :ip AND status = 'claimed' AND created_at >= :start_of_day");
    $stmt->execute([
        ':ip' => $ip,
        ':start_of_day' => $start_of_day
    ]);
    $res = $stmt->fetch();
    return $res ? intval($res['count']) : 0;
}

function cleanup_old_sessions() {
    $db = db_connect();
    $one_day_ago = time() - (24 * 3600);
    $stmt = $db->prepare("DELETE FROM sessions WHERE created_at < :time");
    $stmt->execute([':time' => $one_day_ago]);
}

// Tự động dọn dẹp các session rác
if (rand(1, 100) === 50) {
    cleanup_old_sessions();
}
?>
