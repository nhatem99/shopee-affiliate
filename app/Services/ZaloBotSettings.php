<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Nơi duy nhất đọc cấu hình Zalo Bot. Ưu tiên .env (config/services.php) nếu có, không thì
 * lấy từ bảng settings do admin nhập ở /admin/zalo-bot — trên production không sửa .env được,
 * và repo là PUBLIC nên tuyệt đối không ghi token vào code.
 *
 * Token và webhook secret lưu đã mã hoá bằng APP_KEY (Crypt), để bản dump DB không lộ quyền
 * điều khiển bot.
 */
class ZaloBotSettings
{
    private const TOKEN_KEY = 'zalo_bot_token';

    private const SECRET_KEY = 'zalo_bot_webhook_secret';

    private const ADMIN_IDS_KEY = 'zalo_bot_admin_chat_ids';

    public function token(): ?string
    {
        return $this->filled(config('services.zalo_bot.token')) ?? $this->decrypted(self::TOKEN_KEY);
    }

    public function webhookSecret(): ?string
    {
        return $this->filled(config('services.zalo_bot.webhook_secret')) ?? $this->decrypted(self::SECRET_KEY);
    }

    /**
     * @return list<string>
     */
    public function adminChatIds(): array
    {
        $fromEnv = config('services.zalo_bot.admin_chat_ids', []);
        if ($fromEnv) {
            return $fromEnv;
        }

        $stored = json_decode((string) Setting::get(self::ADMIN_IDS_KEY, '[]'), true);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /** Token đang lấy từ .env thì trang admin không sửa được (sửa cũng không có tác dụng). */
    public function tokenFromEnv(): bool
    {
        return $this->filled(config('services.zalo_bot.token')) !== null;
    }

    public function adminChatIdsFromEnv(): bool
    {
        return (bool) config('services.zalo_bot.admin_chat_ids', []);
    }

    public function saveToken(string $token): void
    {
        Setting::set(self::TOKEN_KEY, Crypt::encryptString(trim($token)));
        $this->ensureWebhookSecret();
    }

    /**
     * @param  list<string>  $chatIds
     */
    public function saveAdminChatIds(array $chatIds): void
    {
        Setting::set(self::ADMIN_IDS_KEY, json_encode(array_values(array_unique($chatIds))));
    }

    /**
     * Secret webhook tự sinh, admin không cần biết. Chỉ sinh một lần: đổi secret là webhook
     * đang đặt trên Zalo hỏng cho tới khi đặt lại.
     */
    public function ensureWebhookSecret(): string
    {
        if ($existing = $this->webhookSecret()) {
            return $existing;
        }

        $secret = Str::random(48);
        Setting::set(self::SECRET_KEY, Crypt::encryptString($secret));

        return $secret;
    }

    private function decrypted(string $key): ?string
    {
        $value = Setting::get($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // APP_KEY đổi thì giá trị cũ không đọc được nữa — coi như chưa cấu hình.
            return null;
        }
    }

    private function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
