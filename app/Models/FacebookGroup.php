<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fb_group_key', 'name', 'url', 'enabled', 'source', 'disabled_reason', 'last_seen_at', 'last_attempt_at', 'last_posted_at', 'last_checked_at'])]
class FacebookGroup extends Model
{
    /**
     * Các trang của Facebook cũng nằm dưới /groups/ nhưng không phải một nhóm — bot cào trang
     * "Nhóm của bạn" sẽ gặp đủ loại link này lẫn với link nhóm thật.
     */
    private const NOT_GROUP_KEYS = [
        'joins', 'feed', 'discover', 'create', 'search', 'notifications', 'your_groups',
        'categories', 'category', 'invites', 'browse', 'membership', 'manage', 'settings',
        'saved', 'you', 'pending', 'posts', 'permalink',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_posted_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(FacebookGroupPost::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Phần định danh nhóm trong một link Facebook bất kỳ trỏ vào nhóm (link bài viết trong
     * nhóm, link m./web./mbasic., có query...). null nếu không phải link nhóm.
     */
    public static function keyFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'facebook.com' && ! str_ends_with($host, '.facebook.com')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));
        if (($segments[0] ?? null) !== 'groups' || ! isset($segments[1])) {
            return null;
        }

        $key = strtolower(rawurldecode($segments[1]));
        // Bắt đầu bằng chữ/số: chặn "..", "./" — link /groups/../ đưa trình duyệt về trang chủ.
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,149}$/', $key) || in_array($key, self::NOT_GROUP_KEYS, true)) {
            return null;
        }

        return $key;
    }

    public static function urlFor(string $key): string
    {
        return 'https://www.facebook.com/groups/'.$key.'/';
    }
}
