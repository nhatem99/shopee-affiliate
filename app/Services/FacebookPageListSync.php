<?php

namespace App\Services;

use App\Models\FacebookProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Nút "Lấy danh sách page" ở /admin/fb-groups: bot (bằng nick chính) đọc trang "Trang của bạn" trên
 * Facebook, gửi về link + tên từng page nick đang quản lý. Đối chiếu với các page ở admin:
 *  • page mới — thêm vào, TẮT sẵn: admin tự bật page muốn dùng (bật lần đầu thì bot lấy nhóm của page);
 *  • page đã có — khớp theo uid, rồi link, rồi tên (chỉ khi tên không trùng) — xoá dấu "không còn";
 *  • page ở admin mà không có trong danh sách — tắt và ghi missing_since, không xoá (giữ lịch sử bài).
 *    Bot gửi danh sách rỗng thì coi như đọc hỏng, không tắt page nào.
 */
class FacebookPageListSync
{
    public function __construct(private FacebookGroupRunnerSettings $settings) {}

    /**
     * @param  list<array{url: string, name?: ?string}>  $pages
     * @return array{created: int, updated: int, missing: int, error: ?string}
     */
    public function apply(array $pages): array
    {
        $primaryId = FacebookProfile::where('is_primary', true)->value('fb_id');
        /** @var Collection<int, FacebookProfile> $profiles */
        $profiles = FacebookProfile::where('is_primary', false)->get();
        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($pages as $item) {
            $parsed = FacebookProfile::parseUrl((string) ($item['url'] ?? ''));
            if ($parsed === null || ($parsed['fb_id'] !== null && $parsed['fb_id'] === $primaryId)) {
                continue;
            }
            $name = Str::limit(self::cleanName((string) ($item['name'] ?? '')), 250, '') ?: null;

            $match = $this->match($profiles, $parsed, $name);
            if ($match) {
                if (isset($seen[$match->id])) {
                    continue;
                }
                // Tên cũ đọc nhầm chữ mô tả ảnh đại diện (bot 1.7.0 đầu tiên) thì lấy tên mới.
                $stale = $match->name === null || self::cleanName($match->name) !== $match->name;
                $changes = ['missing_since' => null, 'name' => $stale ? ($name ?? (self::cleanName((string) $match->name) ?: null)) : $match->name];
                if ($match->fb_id === null && $parsed['fb_id'] !== null && ! FacebookProfile::where('fb_id', $parsed['fb_id'])->exists()) {
                    $changes['fb_id'] = $parsed['fb_id'];
                }
                $match->forceFill($changes)->save();
                $updated++;
            } else {
                $match = FacebookProfile::create([
                    'fb_id' => $parsed['fb_id'],
                    'url' => $parsed['url'],
                    'name' => $name,
                    'enabled' => false,
                    'position' => (int) FacebookProfile::max('position') + 1,
                ]);
                $profiles->push($match);
                $created++;
            }
            $seen[$match->id] = true;
        }

        $missing = 0;
        if ($seen) {
            foreach ($profiles as $profile) {
                if (! isset($seen[$profile->id]) && $profile->missing_since === null) {
                    $profile->forceFill(['missing_since' => now(), 'enabled' => false])->save();
                    $missing++;
                }
            }
        }

        $result = [
            'created' => $created,
            'updated' => $updated,
            'missing' => $missing,
            'error' => $seen ? null : 'Bot không đọc được page nào ở "Trang của bạn" — không đổi gì.',
        ];
        $this->settings->recordPagesSyncResult($result);
        Log::info('FacebookPageListSync: đã đối chiếu danh sách page', $result + ['received' => count($pages)]);

        return $result;
    }

    /**
     * Bỏ chữ mô tả ảnh đại diện ("Ảnh đại diện của <tên>") Facebook để ở aria-label — giống
     * cleanName() trong tools/fb-group-poster/lib/pages.mjs.
     */
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/^\s*(ảnh đại diện( của)?|profile (picture|photo)( of)?)\s*/iu', '', $name));
    }

    /** Bot không đọc được trang (Facebook bắt xác minh, đổi giao diện...) — chỉ ghi lại để admin thấy. */
    public function fail(string $error): void
    {
        $this->settings->recordPagesSyncResult(['created' => 0, 'updated' => 0, 'missing' => 0, 'error' => Str::limit($error, 490)]);
    }

    /**
     * @param  Collection<int, FacebookProfile>  $profiles
     * @param  array{url: string, fb_id: ?string}  $parsed
     */
    private function match(Collection $profiles, array $parsed, ?string $name): ?FacebookProfile
    {
        if ($parsed['fb_id'] !== null && $hit = $profiles->first(fn (FacebookProfile $p) => $p->fb_id === $parsed['fb_id'])) {
            return $hit;
        }
        if ($hit = $profiles->first(fn (FacebookProfile $p) => $p->url !== null && strcasecmp(rtrim($p->url, '/'), rtrim($parsed['url'], '/')) === 0)) {
            return $hit;
        }
        if ($name === null) {
            return null;
        }
        // Page thêm bằng link tên rút gọn mà Facebook liệt kê bằng profile.php?id= — khớp theo tên,
        // chỉ khi đúng một page mang tên đó và page đó chưa có uid khác.
        $byName = $profiles->filter(fn (FacebookProfile $p) => $p->name !== null && mb_strtolower($p->name) === mb_strtolower($name)
            && ($p->fb_id === null || $parsed['fb_id'] === null));

        return $byName->count() === 1 ? $byName->first() : null;
    }
}
