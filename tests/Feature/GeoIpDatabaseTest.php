<?php

namespace Tests\Feature;

use App\Services\GeoIpDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoIpDatabaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/geoip-'.uniqid());
        $this->travelTo('2026-10-03 04:00:00');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function database(): GeoIpDatabase
    {
        return new GeoIpDatabase($this->dir.'/country.mmdb');
    }

    private function install(string $month, string $contents = 'mmdb cũ'): void
    {
        File::ensureDirectoryExists($this->dir);
        file_put_contents($this->dir.'/country.mmdb', $contents);
        file_put_contents($this->dir.'/country.mmdb.month', $month);
    }

    public function test_no_file_means_no_answer(): void
    {
        $this->assertNull($this->database()->countryCode('8.8.8.8'));
        $this->assertNull($this->database()->installedMonth());
    }

    public function test_current_month_already_installed_skips_network(): void
    {
        Http::fake();
        $this->install('2026-10');

        $result = $this->database()->update();

        $this->assertSame('current', $result['status']);
        Http::assertNothingSent();
    }

    public function test_falls_back_to_last_month_while_this_month_is_not_out_yet(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response('', 404)]);
        $this->install('2026-09');

        $result = $this->database()->update();

        // Bản tháng 10 chưa có → máy đang có đúng bản tháng 9 là đủ, không tải lại.
        $this->assertSame('current', $result['status']);
        $this->assertSame('2026-09', $result['month']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dbip-country-lite-2026-10.mmdb.gz'));
        Http::assertSentCount(1);
    }

    public function test_broken_download_keeps_the_file_in_use(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response(gzencode('không phải mmdb'))]);
        $this->install('2026-08');

        $result = $this->database()->update();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('mmdb cũ', file_get_contents($this->dir.'/country.mmdb'));
        $this->assertSame('2026-08', $this->database()->installedMonth());
        $this->assertFileDoesNotExist($this->dir.'/country.mmdb.download');
    }

    public function test_command_fails_loudly_when_nothing_can_be_downloaded(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response('', 404)]);
        config(['services.geoip.database' => $this->dir.'/country.mmdb']);

        $this->artisan('geoip:update')
            ->expectsOutputToContain('Không tải được')
            ->expectsOutputToContain('ip-api')
            ->assertFailed();
    }
}
