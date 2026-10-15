<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Cấu hình bình luận dạo trong nhóm follow chéo, chỉnh ở /admin/fb-engage. Nằm trong bảng settings
 * như FacebookGroupRunnerSettings: đổi trên web là bot thấy ở lượt hỏi việc kế tiếp.
 */
class FacebookEngageSettings
{
    private const CONFIG_KEY = 'fb_engage_config';

    private const TEMPLATES_KEY = 'fb_engage_templates';

    private const NEXT_AT_KEY = 'fb_engage_next_at';

    private const STOPPED_REASON_KEY = 'fb_engage_stopped_reason';

    /**
     * Mặc định tắt và thưa: bình luận giống nhau, đều tay, dồn dập là thứ Facebook bắt nhanh nhất
     * — bị chặn bình luận vài ngày, nặng thì bắt xác minh cả nick (dừng luôn bot đăng deal).
     * profile_id: page dùng để bình luận (FacebookProfile), null là chưa chọn.
     */
    public const DEFAULT_CONFIG = [
        'enabled' => false,
        'profile_id' => null,
        'max_per_day' => 30,
        'gap_min' => 4,
        'gap_max' => 10,
        'group_gap_minutes' => 60,
        'window_start' => '08:00',
        'window_end' => '22:00',
    ];

    /**
     * @return array{enabled: bool, profile_id: ?int, max_per_day: int, gap_min: int, gap_max: int, group_gap_minutes: int, window_start: string, window_end: string}
     */
    public function config(): array
    {
        $stored = json_decode((string) Setting::get(self::CONFIG_KEY, '{}'), true);

        return array_merge(self::DEFAULT_CONFIG, array_intersect_key(is_array($stored) ? $stored : [], self::DEFAULT_CONFIG));
    }

    public function saveConfig(array $config): void
    {
        Setting::set(self::CONFIG_KEY, json_encode(array_intersect_key($config + $this->config(), self::DEFAULT_CONFIG)));
    }

    public function enabled(): bool
    {
        return (bool) $this->config()['enabled'];
    }

    /** Bật lại là admin đã xem lý do bot tự dừng — xoá lý do cũ. */
    public function setEnabled(bool $enabled): void
    {
        $this->saveConfig(['enabled' => $enabled]);
        if ($enabled) {
            Setting::set(self::STOPPED_REASON_KEY, '');
        }
    }

    /** Server tự tắt (Facebook chặn bình luận) — giữ lý do cho trang admin. */
    public function stop(string $reason): void
    {
        $this->saveConfig(['enabled' => false]);
        Setting::set(self::STOPPED_REASON_KEY, Str::limit($reason, 500));
    }

    public function stoppedReason(): ?string
    {
        return $this->enabled() ? null : (Setting::get(self::STOPPED_REASON_KEY) ?: null);
    }

    /**
     * Câu mẫu: mỗi dòng một câu, bỏ dòng trống. Cú pháp {a|b} như mẫu bài deal; {ten} là tên người
     * đăng bài (bot điền, không thấy tên thì bỏ).
     *
     * @return list<string>
     */
    public function templates(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", str_replace("\r\n", "\n", $this->templatesText()))),
            fn (string $line) => $line !== '',
        ));
    }

    public function templatesText(): string
    {
        return (string) Setting::get(self::TEMPLATES_KEY, '');
    }

    public function saveTemplates(string $text): void
    {
        Setting::set(self::TEMPLATES_KEY, trim(str_replace("\r\n", "\n", $text)));
    }

    /** Lượt bình luận kế tiếp được giao từ lúc nào — khoảng nghỉ ngẫu nhiên tính lúc giao việc. */
    public function nextAt(): ?CarbonImmutable
    {
        $value = Setting::get(self::NEXT_AT_KEY);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function setNextAt(?CarbonInterface $at): void
    {
        Setting::set(self::NEXT_AT_KEY, $at?->toIso8601String() ?? '');
    }
}
