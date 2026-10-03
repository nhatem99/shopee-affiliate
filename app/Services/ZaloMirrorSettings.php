<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Đọc/ghi cấu hình tính năng đăng lại bài (mirror) cho bot nick cá nhân Zalo.
 * Lưu trong bảng settings (admin nhập ở /admin/zalo-nick) — production không sửa .env được.
 *
 * Mirror CHỈ hoạt động khi đủ tất cả điều kiện (xem isActive):
 *   • enabled = true
 *   • có nhóm đích
 *   • có ít nhất một nhóm nguồn
 *   • nhóm đích không nằm trong danh sách nguồn (tránh spam vòng lặp)
 */
class ZaloMirrorSettings
{
    private const ENABLED_KEY = 'zalo_mirror_enabled';

    private const SOURCE_GROUP_IDS_KEY = 'zalo_mirror_source_group_ids';

    private const TARGET_GROUP_ID_KEY = 'zalo_mirror_target_group_id';

    private const POSTS_PER_HOUR_KEY = 'zalo_mirror_posts_per_hour';

    private const ADMINS_ONLY_KEY = 'zalo_mirror_admins_only';

    public function enabled(): bool
    {
        return Setting::getBool(self::ENABLED_KEY, false);
    }

    /** @return list<string> */
    public function sourceGroupIds(): array
    {
        $stored = json_decode((string) Setting::get(self::SOURCE_GROUP_IDS_KEY, '[]'), true);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    public function targetGroupId(): ?string
    {
        $v = Setting::get(self::TARGET_GROUP_ID_KEY);

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function postsPerHour(): int
    {
        return max(1, (int) Setting::get(self::POSTS_PER_HOUR_KEY, 10));
    }

    /**
     * Chỉ đăng lại bài của admin nhóm nguồn — lọc tin nhắn của thành viên thường (hỏi mã, spam...).
     */
    public function adminsOnly(): bool
    {
        return Setting::getBool(self::ADMINS_ONLY_KEY, true);
    }

    /**
     * Mirror đang thật sự hoạt động khi đủ tất cả điều kiện. Nhóm đích không được nằm trong nguồn:
     * bot ở nhóm đích đọc bài của chính mình rồi đăng lại là spam vô tận.
     */
    public function isActive(): bool
    {
        $target = $this->targetGroupId();
        $sources = $this->sourceGroupIds();

        return $this->enabled()
            && $target !== null
            && count($sources) > 0
            && ! in_array($target, $sources, true);
    }

    public function isSourceGroup(string $groupId): bool
    {
        return in_array($groupId, $this->sourceGroupIds(), true);
    }

    /** @return array{enabled: bool, sourceGroupIds: list<string>, targetGroupId: ?string, postsPerHour: int, adminsOnly: bool, isActive: bool} */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled(),
            'sourceGroupIds' => $this->sourceGroupIds(),
            'targetGroupId' => $this->targetGroupId(),
            'postsPerHour' => $this->postsPerHour(),
            'adminsOnly' => $this->adminsOnly(),
            'isActive' => $this->isActive(),
        ];
    }

    /**
     * @param  array{enabled?: bool, sourceGroupIds?: list<string>, targetGroupId?: ?string, postsPerHour?: int, adminsOnly?: bool}  $data
     */
    public function save(array $data): void
    {
        Setting::set(self::ENABLED_KEY, ($data['enabled'] ?? false) ? '1' : '0');
        Setting::set(self::SOURCE_GROUP_IDS_KEY, json_encode(array_values($data['sourceGroupIds'] ?? [])));
        Setting::set(self::TARGET_GROUP_ID_KEY, (string) ($data['targetGroupId'] ?? ''));
        Setting::set(self::POSTS_PER_HOUR_KEY, (string) max(1, min(60, (int) ($data['postsPerHour'] ?? 10))));
        Setting::set(self::ADMINS_ONLY_KEY, ($data['adminsOnly'] ?? true) ? '1' : '0');
    }
}
