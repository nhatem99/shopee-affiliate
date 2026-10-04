<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ShortLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Công tắc "Link /go/ chỉ mở trên điện thoại" ở Admin > Cài đặt. */
class ShortLinkMobileOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (Linux; Android 10; Mi) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36';

    private const PC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36';

    private const TARGET = 'https://shopee.vn/product/1/2?mmp_pid=an_x';

    private function link()
    {
        return app(ShortLinkService::class)->create(self::TARGET, 'kieushopee', 'Áo thun', null);
    }

    private function open(string $code, string $ua)
    {
        return $this->withHeader('User-Agent', $ua)->get("/go/{$code}");
    }

    public function test_off_by_default_so_desktop_is_redirected(): void
    {
        $link = $this->link();

        $this->open($link->code, self::PC)->assertRedirect(self::TARGET);
    }

    public function test_when_on_phone_is_redirected_and_desktop_gets_notice_without_a_click(): void
    {
        Setting::set('go_links_mobile_only', '1');
        $link = $this->link();

        $this->open($link->code, self::PHONE)->assertRedirect(self::TARGET);
        $this->assertSame(1, $link->fresh()->clicks);

        $this->open($link->code, self::PC)->assertOk()->assertSee('dành cho điện thoại');
        $this->assertSame(1, $link->fresh()->clicks);
    }

    public function test_admin_on_desktop_still_gets_through_and_bots_keep_their_preview(): void
    {
        Setting::set('go_links_mobile_only', '1');
        $link = $this->link();

        $this->actingAs($this->createAdmin())->withHeader('User-Agent', self::PC)
            ->get("/go/{$link->code}")->assertRedirect(self::TARGET);

        $this->open($link->code, 'facebookexternalhit/1.1')->assertOk()->assertSee('og:url', false);
    }

    public function test_setting_is_saved_from_admin_settings(): void
    {
        $this->actingAs($this->createAdmin())->post('/admin/settings', ['go_links_mobile_only' => true])->assertSessionHasNoErrors();

        $this->assertTrue(Setting::getBool('go_links_mobile_only', false));
    }
}
