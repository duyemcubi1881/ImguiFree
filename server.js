const express = require('express');
const sqlite3 = require('sqlite3').verbose();
const crypto = require('crypto');
const cors = require('cors');
const path = require('path');
const https = require('https');

const app = express();
const PORT = process.env.PORT || 3000;

// Cho phép CORS và parse JSON
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Serve tĩnh thư mục public cho frontend giao diện
app.use(express.static(path.join(__dirname, 'public')));

// Khởi tạo Database SQLite lưu trữ trong file
const dbPath = path.join(__dirname, 'database.db');
const db = new sqlite3.Database(dbPath, (err) => {
    if (err) {
        console.error('Lỗi kết nối database:', err.message);
    } else {
        console.log('Đã kết nối cơ sở dữ liệu SQLite.');
    }
});

// Tạo bảng lưu trữ các session xác thực bypass
db.serialize(() => {
    db.run(`CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY,
        ip TEXT,
        hwid TEXT,
        status TEXT,
        created_at INTEGER,
        updated_at INTEGER
    )`);
});

// Hàm lấy địa chỉ IP của Client chính xác (hỗ trợ reverse proxy như Cloudflare, Render...)
function getClientIp(req) {
    const cfIp = req.headers['cf-connecting-ip'];
    const forwardedIp = req.headers['x-forwarded-for'];
    if (cfIp) return cfIp;
    if (forwardedIp) {
        return forwardedIp.split(',')[0].trim();
    }
    return req.socket.remoteAddress;
}

// Kiểm tra và xóa các session hết hạn (quá 24h hoặc đã sang ngày mới)
function cleanExpiredSessions() {
    const now = Date.now();
    // Xóa session tạo quá 24h trước
    const oneDayAgo = now - 24 * 60 * 60 * 1000;
    
    // Tùy chọn: Reset vào lúc 00:00 mỗi ngày.
    // Dưới đây chúng ta xóa các session đã quá 24h để đảm bảo người dùng chỉ được dùng tối đa 24h từ lúc vượt link.
    db.run("DELETE FROM sessions WHERE created_at < ?", [oneDayAgo], function(err) {
        if (err) {
            console.error("Lỗi dọn dẹp database:", err.message);
        } else if (this.changes > 0) {
            console.log(`Đã dọn dẹp ${this.changes} session hết hạn.`);
        }
    });
}

// Chạy dọn dẹp mỗi 1 giờ
setInterval(cleanExpiredSessions, 60 * 60 * 1000);

// Hàm gọi API Funlink để tạo link rút gọn
function getFunlinkShortUrl(targetUrl) {
    return new Promise((resolve, reject) => {
        const apikey = '65d4f6c0bb16481fbe5f6b69f9922bcb';
        const apiUrl = `https://private.funlink.io/api/cong-khai/tao-lien-ket?apikey=${apikey}&url=${encodeURIComponent(targetUrl)}`;

        https.get(apiUrl, (res) => {
            let data = '';
            res.on('data', (chunk) => {
                data += chunk;
            });
            res.on('end', () => {
                try {
                    const json = JSON.parse(data);
                    if (json && json.id) {
                        // Trả về link rút gọn đầy đủ
                        resolve(`https://private.funlink.io/${json.id}`);
                    } else {
                        reject(new Error(json.message || JSON.stringify(json) || 'Lỗi không rõ từ Funlink API'));
                    }
                } catch (e) {
                    reject(e);
                }
            });
        }).on('error', (err) => {
            reject(err);
        });
    });
}

// API Step 1: Tạo session mới khi người dùng truy cập web
app.get('/api/create-session', async (req, res) => {
    cleanExpiredSessions(); // Dọn dẹp session cũ
    const ip = getClientIp(req);
    const sessionId = crypto.randomBytes(16).toString('hex');
    const now = Date.now();

    db.run("INSERT INTO sessions (id, ip, status, created_at, updated_at) VALUES (?, ?, 'pending', ?, ?)", 
        [sessionId, ip, now, now], async (err) => {
            if (err) {
                console.error(err);
                return res.status(500).json({ success: false, message: "Lỗi tạo phiên giao dịch." });
            }

            // Tự động nhận diện giao thức (http/https) và tên miền của server hiện tại
            const protocol = req.secure || req.headers['x-forwarded-proto'] === 'https' ? 'https' : 'http';
            const host = req.headers.host;
            const targetCallbackUrl = `${protocol}://${host}/callback?session=${sessionId}`;

            console.log(`Đang yêu cầu rút gọn link cho callback: ${targetCallbackUrl}`);

            try {
                // Chỉ gọi API Funlink nếu không phải là localhost (hoặc nếu là localhost thì cho chạy thử nghiệm trực tiếp)
                if (host.includes('localhost') || host.includes('127.0.0.1')) {
                    console.log("Đang chạy ở môi trường Localhost. Bỏ qua Funlink API và dùng link trực tiếp để test.");
                    return res.json({ 
                        success: true, 
                        sessionId, 
                        shortLink: targetCallbackUrl,
                        isLocal: true 
                    });
                }

                const shortLink = await getFunlinkShortUrl(targetCallbackUrl);
                console.log(`Đã tạo link rút gọn từ Funlink thành công: ${shortLink}`);
                res.json({ success: true, sessionId, shortLink });
            } catch (apiErr) {
                console.error("Lỗi khi gọi Funlink API:", apiErr.message);
                // Fallback: Trả về link trực tiếp nếu API lỗi hoặc hết lượt (tối đa 100 lượt/ngày)
                res.json({ 
                    success: true, 
                    sessionId, 
                    shortLink: targetCallbackUrl,
                    warning: "Lỗi Funlink API, đang chạy ở chế độ dự phòng trực tiếp." 
                });
            }
        }
    );
});

// API Step 3: Callback nhận từ link rút gọn (sau khi hoàn thành vượt link)
app.get('/callback', (req, res) => {
    const sessionId = req.query.session;
    const currentIp = getClientIp(req);

    if (!sessionId) {
        return res.status(400).send("Thiếu thông tin session.");
    }

    db.get("SELECT * FROM sessions WHERE id = ?", [sessionId], (err, row) => {
        if (err) {
            return res.status(500).send("Lỗi cơ sở dữ liệu.");
        }
        if (!row) {
            return res.status(400).send("Phiên giao dịch không tồn tại hoặc đã hết hạn.");
        }
        if (row.status !== 'pending') {
            return res.status(400).send("Phiên giao dịch này đã được kích hoạt hoặc không còn hợp lệ.");
        }

        // BẢO MẬT: So khớp IP tạo session lúc đầu và IP hiện tại khi vượt link xong
        if (row.ip !== currentIp) {
            return res.status(403).send("Bypass thất bại! IP hiện tại của bạn không khớp với IP ban đầu yêu cầu vượt link.");
        }

        // Cập nhật trạng thái thành completed để kích hoạt quyền
        const now = Date.now();
        db.run("UPDATE sessions SET status = 'completed', updated_at = ? WHERE id = ?", [now, sessionId], (err) => {
            if (err) {
                return res.status(500).send("Lỗi cập nhật trạng thái.");
            }
            // Chuyển hướng người dùng về trang tải file thành công
            res.redirect(`/success.html?session=${sessionId}`);
        });
    });
});

// API verify dành cho ứng dụng ImGui DLL Client gửi lên để xác thực quyền
app.post('/api/verify', (req, res) => {
    const { hwid } = req.body;
    const currentIp = getClientIp(req);

    if (!hwid) {
        return res.json({ success: false, message: "Thiếu thông tin HWID thiết bị." });
    }

    const oneDayAgo = Date.now() - 24 * 60 * 60 * 1000;

    // Tìm kiếm xem IP này đã hoàn thành vượt link (completed) trong vòng 24 giờ qua chưa
    db.get(
        "SELECT * FROM sessions WHERE ip = ? AND status = 'completed' AND updated_at > ? ORDER BY updated_at DESC LIMIT 1",
        [currentIp, oneDayAgo],
        (err, row) => {
            if (err) {
                return res.json({ success: false, message: "Lỗi cơ sở dữ liệu xác thực." });
            }

            if (!row) {
                return res.json({ success: false, message: "IP này chưa vượt link trong ngày hôm nay." });
            }

            // Nếu IP đã hợp lệ, bắt đầu kiểm tra HWID
            if (!row.hwid) {
                // Đây là lần chạy DLL đầu tiên từ IP đã vượt link -> Tiến hành liên kết HWID thiết bị
                db.run("UPDATE sessions SET hwid = ? WHERE id = ?", [hwid, row.id], (err) => {
                    if (err) {
                        return res.json({ success: false, message: "Lỗi lưu liên kết phần cứng thiết bị." });
                    }
                    return res.json({ success: true, message: "Xác thực & Liên kết thiết bị thành công!" });
                });
            } else {
                // Đã có HWID liên kết với IP này -> Bắt buộc HWID gửi lên phải khớp (Chống leak app cho máy khác sử dụng chung IP)
                if (row.hwid === hwid) {
                    return res.json({ success: true, message: "Xác thực thiết bị thành công!" });
                } else {
                    return res.json({ success: false, message: "Thiết bị phần cứng của bạn không khớp với máy đã vượt link đăng ký!" });
                }
            }
        }
    );
});

// Khởi động server
app.listen(PORT, () => {
    console.log(`Server đang chạy tại: http://localhost:${PORT}`);
});
