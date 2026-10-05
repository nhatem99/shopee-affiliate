<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Cấu hình bot đăng nhóm Facebook, chỉnh ở /admin/fb-groups. Tất cả nằm trong bảng settings:
 * repo là PUBLIC nên không ghi token vào code/config, và cấu hình đổi được ngay trên web mà
 * không phải deploy.
 *
 * Token bot lưu đã mã hoá bằng APP_KEY (Crypt) — bản dump DB không đủ để giả làm bot.
 */
class FacebookGroupRunnerSettings
{
    private const TOKEN_KEY = 'fb_runner_token';

    private const PAUSED_KEY = 'fb_runner_paused';

    private const PAUSED_REASON_KEY = 'fb_runner_paused_reason';

    private const CADENCE_KEY = 'fb_runner_cadence';

    private const NEXT_ALLOWED_KEY = 'fb_runner_next_allowed_at';

    private const STATUS_KEY = 'fb_runner_status';

    private const SYNC_REQUESTED_KEY = 'fb_runner_sync_requested_at';

    private const SYNCED_KEY = 'fb_runner_synced_at';

    private const NEXT_REVIEW_KEY = 'fb_runner_next_review_at';

    private const REVIEW_REQUESTED_KEY = 'fb_runner_review_requested_at';

    /**
     * Mặc định "thận trọng": một nick cá nhân đăng cùng một link vào nhiều nhóm là đúng kiểu
     * Facebook bắt spam — mất nick đắt hơn nhiều so với đăng chậm.
     */
    public const DEFAULT_CADENCE = [
        'max_per_day' => 10,
        'gap_min' => 5,
        'gap_max' => 10,
        'cooldown_hours' => 24,
        'window_start' => '08:00',
        'window_end' => '22:00',
        'expire_hours' => 48,
    ];

    public function token(): ?string
    {
        $value = Setting::get(self::TOKEN_KEY);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // APP_KEY đổi thì token cũ không đọc được nữa — coi như chưa cấu hình.
            return null;
        }
    }

    /**
     * Đổi token là bot đang chạy bị từ chối ngay cho tới khi dán token mới vào máy nhà.
     */
    public function regenerateToken(): string
    {
        $token = Str::random(48);
        Setting::set(self::TOKEN_KEY, Crypt::encryptString($token));

        return $token;
    }

    public function paused(): bool
    {
        return Setting::getBool(self::PAUSED_KEY, false);
    }

    public function pausedReason(): ?string
    {
        return $this->paused() ? (Setting::get(self::PAUSED_REASON_KEY) ?: null) : null;
    }

    public function pause(string $reason): void
    {
        Setting::set(self::PAUSED_KEY, '1');
        Setting::set(self::PAUSED_REASON_KEY, Str::limit($reason, 500));
    }

    public function resume(): void
    {
        Setting::set(self::PAUSED_KEY, '0');
        Setting::set(self::PAUSED_REASON_KEY, '');
    }

    /**
     * @return array{max_per_day: int, gap_min: int, gap_max: int, cooldown_hours: int, window_start: string, window_end: string, expire_hours: int}
     */
    public function cadence(): array
    {
        $stored = json_decode((string) Setting::get(self::CADENCE_KEY, '{}'), true);

        return array_merge(self::DEFAULT_CADENCE, array_intersect_key(is_array($stored) ? $stored : [], self::DEFAULT_CADENCE));
    }

    public function saveCadence(array $cadence): void
    {
        Setting::set(self::CADENCE_KEY, json_encode(array_intersect_key($cadence, self::DEFAULT_CADENCE)));
    }

    public function nextAllowedAt(): ?CarbonImmutable
    {
        $value = Setting::get(self::NEXT_ALLOWED_KEY);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function setNextAllowedAt(?CarbonInterface $at): void
    {
        Setting::set(self::NEXT_ALLOWED_KEY, $at?->toIso8601String() ?? '');
    }

    /** Lượt kiểm tra duyệt bài kế tiếp được giao từ lúc nào — các lượt cách nhau vài phút. */
    public function nextReviewAt(): ?CarbonImmutable
    {
        $value = Setting::get(self::NEXT_REVIEW_KEY);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function setNextReviewAt(?CarbonInterface $at): void
    {
        Setting::set(self::NEXT_REVIEW_KEY, $at?->toIso8601String() ?? '');
    }

    /** Admin bấm "Kiểm tra duyệt bài ngay": nhóm nào chưa kiểm tra từ lúc đó thì kiểm tra luôn. */
    public function reviewRequestedAt(): ?CarbonImmutable
    {
        $value = Setting::get(self::REVIEW_REQUESTED_KEY);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function requestReview(): void
    {
        Setting::set(self::REVIEW_REQUESTED_KEY, now()->toIso8601String());
        $this->setNextReviewAt(null);
    }

    /**
     * Mỗi lượt bot hỏi việc là một nhịp "còn sống" — trang admin dựa vào đây để biết bot có
     * đang chạy trên máy nhà không.
     *
     * @param  array{state?: ?string, version?: ?string, account?: ?string}  $info
     */
    public function recordRunner(array $info): void
    {
        Setting::set(self::STATUS_KEY, json_encode([
            'last_seen_at' => now()->toIso8601String(),
            'state' => $info['state'] ?? 'ok',
            'version' => $info['version'] ?? null,
            'account' => $info['account'] ?? null,
        ]));
    }

    /**
     * @return array{last_seen_at: ?string, state: ?string, version: ?string, account: ?string}
     */
    public function runnerStatus(): array
    {
        $stored = json_decode((string) Setting::get(self::STATUS_KEY, '{}'), true);

        return array_merge(
            ['last_seen_at' => null, 'state' => null, 'version' => null, 'account' => null],
            is_array($stored) ? $stored : [],
        );
    }

    public function requestSync(): void
    {
        Setting::set(self::SYNC_REQUESTED_KEY, now()->toIso8601String());
    }

    public function syncRequested(): bool
    {
        return (string) Setting::get(self::SYNC_REQUESTED_KEY, '') !== '';
    }

    public function markSynced(): void
    {
        Setting::set(self::SYNC_REQUESTED_KEY, '');
        Setting::set(self::SYNCED_KEY, now()->toIso8601String());
    }

    public function lastSyncedAt(): ?string
    {
        return Setting::get(self::SYNCED_KEY) ?: null;
    }
}
