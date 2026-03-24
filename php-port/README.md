# PHP port (basic)

Đây là bản chuyển đổi tối giản từ repo `openai-oauth` sang PHP, tập trung vào các phần cốt lõi:

- Đọc `auth.json` từ Codex/ChatGPT local cache.
- Tự refresh access token bằng refresh token.
- Gọi endpoint Codex `/responses` và `/models`.
- Cung cấp proxy đơn giản tương thích một phần OpenAI API (`/v1/responses`, `/v1/models`).

## Cấu trúc

- `src/OpenAIOAuth/Auth.php`: xử lý auth, đọc/ghi token, refresh token.
- `src/OpenAIOAuth/CodexClient.php`: HTTP client gọi Codex backend.
- `bin/proxy.php`: router/proxy đơn giản.

## Chạy nhanh

1. Đảm bảo bạn đã login trước:

```bash
npx @openai/codex login
```

2. Chạy PHP built-in server:

```bash
php -S 127.0.0.1:10531 php-port/bin/proxy.php
```

3. Gọi thử:

```bash
curl http://127.0.0.1:10531/v1/models
```

## Biến môi trường

- `OAUTH_FILE`: override đường dẫn `auth.json`.
- `CODEX_BASE_URL`: override upstream (mặc định `https://chatgpt.com/backend-api/codex`).

## Lưu ý

- Bản PHP này là **phiên bản nền tảng**, chưa cover đầy đủ tất cả behavior/streaming nâng cao của bản TypeScript.
- Mục tiêu là tạo nền để bạn tiếp tục mở rộng (SSE streaming, chat completions mapping, retry policy...).
