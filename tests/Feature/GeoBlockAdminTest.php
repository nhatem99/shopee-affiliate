<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GeoBlock: chỉ IP Việt Nam và Nhật Bản vào được site; admin đã đăng nhập thì đi qua bất kể IP.
 * Quốc gia nạp sẵn vào cache như lúc IP đã được tra ngầm ở lượt trước (xem GeoBlockTest).
 */
class GeoBlockAdminTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    private const FOREIGN_IP = '8.8.8.8';

    private function fromCountry(string $code, string $ip): static
    {
        Cache::put("geoip_code:{$ip}", $code, now()->addDay());

        return $this->withHeaders(['User-Agent' => self::BROWSER])
            ->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_vietnam_and_japan_ips_are_allowed(): void
    {
        $this->fromCountry('VN', '14.161.0.1')->get('/')->assertOk();
        $this->fromCountry('JP', '133.0.0.1')->get('/')->assertOk();
    }

    public function test_foreign_ip_is_blocked(): void
    {
        $this->fromCountry('US', self::FOREIGN_IP)->get('/')->assertForbidden();
    }

    public function test_logged_in_admin_passes_from_a_foreign_ip(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->fromCountry('US', self::FOREIGN_IP)->get('/admin/dashboard')->assertOk();
    }

    public function test_logged_in_regular_user_is_still_blocked_from_a_foreign_ip(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->fromCountry('US', self::FOREIGN_IP)->get('/')->assertForbidden();
    }

    public function test_admin_login_page_is_open_from_a_foreign_ip(): void
    {
        $this->fromCountry('US', self::FOREIGN_IP)->get('/admin/login')->assertOk();
    }
}
