<?php

namespace Tests\Unit;

use App\Models\FacebookGroup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FacebookGroupUrlTest extends TestCase
{
    public static function groupLinks(): array
    {
        return [
            'link chuẩn' => ['https://www.facebook.com/groups/123456789012345', '123456789012345'],
            'có dấu / cuối' => ['https://www.facebook.com/groups/shopee1111/', 'shopee1111'],
            'chữ hoa + query' => ['https://www.facebook.com/groups/SanSale/?ref=bookmarks', 'sansale'],
            'link bài trong nhóm' => ['https://www.facebook.com/groups/shopee1111/posts/123456/', 'shopee1111'],
            'm.' => ['https://m.facebook.com/groups/abc.def/permalink/9/', 'abc.def'],
            'web.' => ['https://web.facebook.com/groups/abc_def', 'abc_def'],
            'không có https' => ['facebook.com/groups/abc-def', 'abc-def'],
        ];
    }

    #[DataProvider('groupLinks')]
    public function test_extracts_group_key(string $url, string $key): void
    {
        $this->assertSame($key, FacebookGroup::keyFromUrl($url));
    }

    public static function notGroupLinks(): array
    {
        return [
            'rỗng' => [''],
            'trang cá nhân' => ['https://www.facebook.com/zuck'],
            'trang Nhóm của bạn' => ['https://www.facebook.com/groups/joins/'],
            'bảng tin nhóm' => ['https://www.facebook.com/groups/feed/'],
            'khám phá' => ['https://www.facebook.com/groups/discover'],
            'thiếu key' => ['https://www.facebook.com/groups/'],
            'domain giả' => ['https://facebook.com.evil.test/groups/abc'],
            'domain khác' => ['https://notfacebook.com/groups/abc'],
            'ký tự lạ' => ['https://www.facebook.com/groups/abc%3Cscript%3E'],
            'chỉ có dấu chấm' => ['https://www.facebook.com/groups/../'],
            'bắt đầu bằng dấu chấm' => ['https://www.facebook.com/groups/.abc'],
        ];
    }

    #[DataProvider('notGroupLinks')]
    public function test_rejects_non_group_links(string $url): void
    {
        $this->assertNull(FacebookGroup::keyFromUrl($url));
    }

    public function test_canonical_url(): void
    {
        $this->assertSame('https://www.facebook.com/groups/abc/', FacebookGroup::urlFor('abc'));
    }
}
