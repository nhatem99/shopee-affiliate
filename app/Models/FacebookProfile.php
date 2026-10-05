<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một danh tính bot dùng để đăng nhóm: nick chính (is_primary) hoặc một Trang nick đó quản lý.
 * Page đăng theo thứ tự position — page đầu đăng đủ max_per_day mới tới page sau.
 */
#[Fillable(['fb_id', 'name', 'url', 'is_primary', 'enabled', 'position', 'max_per_day', 'blocked_at', 'blocked_reason', 'last_synced_at'])]
class FacebookProfile extends Model
{
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'enabled' => 'boolean',
            'position' => 'integer',
            'max_per_day' => 'integer',
            'blocked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(FacebookGroup::class, 'facebook_group_profile')->withPivot('last_seen_at')->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(FacebookGroupPost::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** Bật và không bị chặn — được giao bài (còn tuỳ trần mỗi ngày). */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('enabled', true)->whereNull('blocked_at');
    }

    public static function primary(): self
    {
        return static::where('is_primary', true)->firstOrFail();
    }

    public function label(): string
    {
        return $this->name ?: ($this->is_primary ? 'Nick chính' : 'Page #'.$this->id);
    }

    /** Thông tin bot cần để chuyển sang page này (tools/fb-group-poster/lib/profiles.mjs). */
    public function forRunner(): array
    {
        return [
            'id' => $this->id,
            'fb_id' => $this->fb_id,
            'name' => $this->label(),
            'url' => $this->url,
            'primary' => $this->is_primary,
        ];
    }

    /**
     * Link trang của page trên facebook.com — bot mở link này rồi bấm "Chuyển ngay". Nhận
     * profile.php?id=..., tên rút gọn, link m./web., hoặc chỉ id số. null nếu không phải link page.
     *
     * @return array{url: string, fb_id: ?string}|null
     */
    public static function parseUrl(string $input): ?array
    {
        $input = trim($input);
        if (preg_match('/^\d{5,30}$/', $input)) {
            return ['url' => self::profileUrl($input), 'fb_id' => $input];
        }
        if (! preg_match('#^https?://#i', $input)) {
            $input = 'https://'.$input;
        }

        $host = strtolower((string) parse_url($input, PHP_URL_HOST));
        if ($host !== 'facebook.com' && ! str_ends_with($host, '.facebook.com')) {
            return null;
        }

        $path = trim((string) parse_url($input, PHP_URL_PATH), '/');
        parse_str((string) parse_url($input, PHP_URL_QUERY), $query);

        if ($path === 'profile.php') {
            $id = (string) ($query['id'] ?? '');

            return preg_match('/^\d{5,30}$/', $id) ? ['url' => self::profileUrl($id), 'fb_id' => $id] : null;
        }

        // facebook.com/<tên rút gọn> hoặc facebook.com/people/<tên>/<id>/
        $segments = explode('/', $path);
        if (($segments[0] ?? '') === 'people' && preg_match('/^\d{5,30}$/', $segments[2] ?? '')) {
            return ['url' => self::profileUrl($segments[2]), 'fb_id' => $segments[2]];
        }
        if (count($segments) !== 1 || ! preg_match('/^[A-Za-z0-9.]{5,80}$/', $segments[0])
            || in_array(strtolower($segments[0]), ['groups', 'pages', 'watch', 'marketplace', 'gaming', 'events', 'home.php', 'login'], true)) {
            return null;
        }

        return ['url' => 'https://www.facebook.com/'.$segments[0], 'fb_id' => null];
    }

    public static function profileUrl(string $fbId): string
    {
        return 'https://www.facebook.com/profile.php?id='.$fbId;
    }
}
