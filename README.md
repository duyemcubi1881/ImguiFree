# Hướng Dẫn Sử Dụng Hệ Thống Gekey Unlocker

Dự án này bao gồm hai phần chính:
1. **Backend Web Server (Node.js + SQLite)**: Quản lý phiên làm việc (session), địa chỉ IP, và khóa thiết bị (HWID).
2. **Game Client DLL Logic (C#)**: Xác thực tự động với Backend trước khi mở menu ImGui.

---

## Phần 1: Cấu hình và Chạy Backend Web Server

### 1. Chuẩn bị môi trường
Máy chủ của bạn cần cài đặt sẵn **Node.js** (Phiên bản 16 trở lên).

### 2. Cài đặt các thư viện phụ thuộc
Mở Command Prompt/Terminal trong thư mục `gekey-unlocker` và chạy lệnh:
```bash
npm install
```

### 3. Thiết lập API Funlink
API Funlink đã được tích hợp trực tiếp ở phía **Backend** (`server.js`) để đảm bảo an toàn, tránh bị lộ API Token (`65d4f6c0bb16481fbe5f6b69f9922bcb`) ra trình duyệt của người dùng.

> [!TIP]
> **Cơ chế tự động tối ưu**: 
> * **Khi chạy ở Localhost**: Server sẽ nhận diện và tự động bỏ qua việc gọi API Funlink (do Funlink không thể chuyển hướng về địa chỉ local `127.0.0.1`). Server sẽ cho bạn chạy thẳng tới callback để việc kiểm thử ở local diễn ra mượt mà nhất.
> * **Khi chạy ở VPS / Render (Production)**: Hệ thống sẽ tự động gọi API Funlink để tạo link rút gọn bảo mật và trả về cho client.

### 4. Khởi chạy Server
Để chạy server ở local/VPS:
```bash
npm start
```
Server sẽ mặc định chạy trên cổng `3000` (địa chỉ `http://localhost:3000`).

---

## Phần 2: Chèn Mã Xác Thực vào Game Client (C### Phần 2: Chèn Mã Xác Thực vào Game Client (C#)

Vì tính năng kiểm tra vượt link (bypass check) đã được tích hợp trực tiếp bên trong API đăng nhập `/api/redeem` của Flask Server, bạn **không cần** thêm bất kỳ hàm xác thực hay API mới nào vào Client C#. 

Tất cả những gì bạn cần làm là mở rộng hàm `PerformLogin` hiện tại của bạn để tự động mở trình duyệt vượt link nếu Server trả về lỗi chưa bypass.

### Chỉnh sửa hàm `PerformLogin(string key)` của bạn:

Tìm đến đoạn xử lý lỗi đăng nhập thất bại (khi `response` không thành công) và chèn thêm đoạn mở trình duyệt như sau:

```csharp
        private async Task PerformLogin(string key)
        {
            if (string.IsNullOrEmpty(key))
            {
                _loginStatus = "Vui long nhap key.";
                _statusColor = new Vector4(1f, 0.3f, 0.3f, 1f);
                return;
            }

            _isLoggingIn = true;
            _loginStatus = "Dang xac thuc...";
            _statusColor = new Vector4(1f, 1f, 0f, 1f);

            string currentHwid = GetHardwareId();
            string jsonPayload = $"{{\"key\":\"{key}\",\"hwid\":\"{currentHwid}\"}}";

            try
            {
                using (var httpClient = new HttpClient())
                using (var content = new StringContent(jsonPayload, Encoding.UTF8, "application/json"))
                {
                    var response = await httpClient.PostAsync(API_REDEEM_URL, content);
                    string responseBody = await response.Content.ReadAsStringAsync();
                    string status = GetJsonValue(responseBody, "status");

                    if (response.IsSuccessStatusCode && status == "success")
                    {
                        if (_rememberKey) SaveSavedKey(key);
                        else SaveSavedKey(string.Empty);

                        // Đọc số giây còn lại từ server
                        string expiryLeftStr = GetJsonValue(responseBody, "expiry_left") ?? "0";
                        ExpiresAt = expiryLeftStr;

                        _loginStatus = "Dang nhap thanh cong!";
                        _statusColor = new Vector4(0.3f, 1f, 0.3f, 1f);

                        _ = Task.Run(async () =>
                        {
                            try { await SendScreenshotAndPCInfo(key); }
                            catch { }
                        });

                        await Task.Delay(1000);
                        _isAuthenticated = true;

                        _ = Task.Run(() => StartExpirationCheck(key));
                    }
                    else
                    {
                        string errorMsg = GetJsonValue(responseBody, "message") ?? GetJsonValue(responseBody, "error") ?? "Loi xac thuc khong ro.";
                        _loginStatus = errorMsg;
                        _statusColor = new Vector4(1f, 0.3f, 0.3f, 1f);

                        // ── TỰ ĐỘNG MỞ TRÌNH DUYỆT VƯỢT LINK NẾU CHƯA BYPASS ──
                        if (errorMsg.Contains("vượt link") || errorMsg.Contains("unlock") || errorMsg.Contains("chưa kích hoạt"))
                        {
                            try
                            {
                                // Thay tên miền của bạn tại đây
                                string unlockUrl = "https://ten-mien-cua-ban.com/unlock"; 
                                Process.Start(new ProcessStartInfo(unlockUrl) { UseShellExecute = true });
                            }
                            catch { }
                            
                            // Đóng game và tắt overlay để chặn sử dụng trái phép
                            CloseEmulatorAndExit();
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                _loginStatus = "Loi ket noi: " + ex.Message;
                _statusColor = new Vector4(1f, 0.3f, 0.3f, 1f);
            }
            finally
            {
                _isLoggingIn = false;
            }
        }
```

---

## Nguyên lý Chống Bypass & Hoạt động thực tế

1. **Step 1**: Người dùng vào web, server lưu lại IP của người dùng và sinh một `session_id` ngẫu nhiên lưu vào Firestore collection `bypass_sessions` dưới dạng `pending`.
2. **Step 2**: Người dùng bấm nút đi qua Funlink với link callback dạng `https://ten-mien.com/callback?session=<session_id>`.
3. **Step 3**: Sau khi vượt xong link rút gọn, hệ thống chuyển hướng người dùng về `/callback`. Server kiểm tra chéo IP hiện tại lúc này có trùng khớp với IP lúc truy cập Step 1 hay không. 
   - Nếu trùng khớp $\rightarrow$ Kích hoạt session sang trạng thái `completed`.
   - Nếu không trùng khớp $\rightarrow$ Báo lỗi `403` và không kích hoạt.
4. **Xác thực Client**: Khi client thực hiện đăng nhập hoặc gọi định kỳ lên `/api/redeem` để xác thực:
   - Server lấy IP hiện tại của client đó.
   - Tìm kiếm bản ghi `completed` tương ứng với IP đó trong 24 giờ qua.
   - Nếu thấy bản ghi chưa liên kết HWID $\rightarrow$ Liên kết HWID này vào IP này luôn.
   - Nếu thấy đã có HWID liên kết $\rightarrow$ Yêu cầu HWID gửi lên phải khớp 100% (Chặn việc nhiều người dùng chung IP share app cho nhau sử dụng).
   - Nếu hoàn tất tất cả bước trên, Server mới cho phép kiểm tra Key đăng nhập của họ có hợp lệ không.
   - **Tự động hết hạn**: Khi sang ngày mới, phiên kiểm tra định kỳ từ Client gọi lên Server sẽ lập tức bị chặn (vì phiên bypass hôm trước đã quá 24h và bị vô hiệu hóa), Client nhận mã lỗi và tự động tắt game.

