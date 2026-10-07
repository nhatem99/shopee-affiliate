<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoBlockTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    private function visit(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', self::UA)
            ->get('/');
    }

    private function fakeCountry(string $code): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'countryCode' => $code])]);
    }

    /** Số lượt GeoIpService hỏi ip-api — job tra vị trí của tracking cũng gọi ip-api nên phải lọc. */
    private function geoLookups(): int
    {
        return Http::recorded(fn ($request) => ($request->data()['fields'] ?? null) === 'status,countryCode')->count();
    }

    public function test_new_ip_is_not_kept_waiting_and_gets_looked_up_after_response(): void
    {
        $this->fakeCountry('US');

        // Lượt đầu của IP lạ: trả trang ngay, việc tra ip-api dời ra sau response.
        $this->visit('8.8.8.8')->assertOk();
        $this->assertSame(1, $this->geoLookups());
        $this->assertSame('US', Cache::get('geoip_code:8.8.8.8'));

        // Đã biết là nước ngoài: từ lượt sau thì chặn, không hỏi lại ip-api.
        $this->visit('8.8.8.8')->assertForbidden();
        $this->assertSame(1, $this->geoLookups());
    }

    public function test_allowed_country_keeps_passing(): void
    {
        $this->fakeCountry('VN');

        $this->visit('113.160.0.1')->assertOk();
        $this->visit('113.160.0.1')->assertOk();
        $this->assertSame(1, $this->geoLookups());
    }

    public function test_unknown_country_is_not_blocked(): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'fail'])]);

        $this->visit('8.8.4.4')->assertOk();
        $this->visit('8.8.4.4')->assertOk();
    }

    public function test_private_ip_is_never_looked_up(): void
    {
        $this->fakeCountry('US');

        $this->visit('192.168.1.10')->assertOk();
        $this->assertSame(0, $this->geoLookups());
    }
}
