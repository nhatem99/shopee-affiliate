<?php

namespace Tests\Unit;

use App\Models\VoucherRef;
use App\Services\VoucherRefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherRefServiceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://shopee.vn/product-i.1.2?mmp_pid=kieushopee';

    public function test_issue_returns_32_char_ref_and_resolve_gives_back_url_and_source(): void
    {
        $service = new VoucherRefService;

        $ref = $service->issue(self::URL, 'ganma');

        $this->assertSame(32, strlen($ref));

        $found = $service->resolve($ref);

        $this->assertNotNull($found);
        $this->assertSame(self::URL, $found->url);
        $this->assertSame('ganma', $found->source);
        $this->assertTrue($found->expires_at->isAfter(now()->addDays(VoucherRefService::TTL_DAYS - 1)));
    }

    public function test_resolve_returns_null_for_unknown_or_expired_ref(): void
    {
        $service = new VoucherRefService;

        $this->assertNull($service->resolve(str_repeat('z', 32)));

        $ref = $service->issue(self::URL, 'kieushopee');
        VoucherRef::where('ref', $ref)->update(['expires_at' => now()->subSecond()]);

        $this->assertNull($service->resolve($ref));
    }

    public function test_prune_removes_only_expired_refs(): void
    {
        $service = new VoucherRefService;

        $alive = $service->issue(self::URL, 'kieushopee');
        $dead = $service->issue(self::URL, 'kieushopee');
        VoucherRef::where('ref', $dead)->update(['expires_at' => now()->subDay()]);

        $this->artisan('model:prune', ['--model' => [VoucherRef::class]]);

        $this->assertDatabaseHas('voucher_refs', ['ref' => $alive]);
        $this->assertDatabaseMissing('voucher_refs', ['ref' => $dead]);
    }
}
