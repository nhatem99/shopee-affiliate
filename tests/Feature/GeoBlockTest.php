<?php

namespace Tests\Feature;

use App\Services\GeoIpDatabase;
use App\Services\ShortLinkService;
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

    /** @param array<string, string> $countries IP => mã quốc gia mà "file dữ liệu IP" trả về */
    private function fakeDatabase(array $countries): void
    {
        $this->app->instance(GeoIpDatabase::class, new class($countries) extends GeoIpDatabase
        {
            public function __construct(private array $countries) {}

            public function countryCode(string $ip): ?string
            {
                return $this->countries[$ip] ?? null;
            }
        });
    }

    public function test_local_database_blocks_foreign_ip_on_first_visit(): void
    {
        $this->fakeCountry('VN');
        $this->fakeDatabase(['45.79.128.205' => 'US']);

        $this->visit('45.79.128.205')
            ->assertForbidden()
            ->assertSee('Việt Nam và Nhật Bản')
            ->assertSee('db-ip.com');
        $this->assertSame(0, $this->geoLookups());
    }

    public function test_local_database_lets_vietnam_through_without_ip_api(): void
    {
        $this->fakeCountry('US');
        $this->fakeDatabase(['113.160.0.1' => 'VN']);

        $this->visit('113.160.0.1')->assertOk();
        $this->assertSame(0, $this->geoLookups());
    }

    public function test_ip_unknown_to_local_database_falls_back_to_ip_api(): void
    {
        $this->fakeCountry('US');
        $this->fakeDatabase([]);

        $this->visit('8.8.8.8')->assertOk();
        $this->assertSame(1, $this->geoLookups());
        $this->visit('8.8.8.8')->assertForbidden();
    }

    /** Đúng chuyện click Ireland/Hà Lan/Mỹ trong báo cáo Shopee: máy quét giả trình duyệt mở /go/. */
    public function test_foreign_scanner_never_reaches_shopee_through_short_link(): void
    {
        $this->fakeDatabase(['52.169.0.1' => 'IE']);
        $link = app(ShortLinkService::class)->create('https://shopee.vn/product/1/2?mmp_pid=an_17332410386', 'kieushopee');

        $this->withServerVariables(['REMOTE_ADDR' => '52.169.0.1'])
            ->withHeader('User-Agent', self::UA)
            ->get('/go/'.$link->code)
            ->assertForbidden();

        $this->assertSame(0, $link->fresh()->clicks);
    }
}
