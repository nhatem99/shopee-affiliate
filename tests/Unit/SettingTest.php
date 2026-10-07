<?php

namespace Tests\Unit;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_whole_table_once_per_request(): void
    {
        Setting::set('a', '1');
        Setting::set('b', 'x');

        DB::enableQueryLog();

        $this->assertTrue(Setting::getBool('a', false));
        $this->assertSame('x', Setting::get('b'));
        $this->assertSame('mặc định', Setting::get('chua_co', 'mặc định'));

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_write_is_visible_right_away_in_same_request(): void
    {
        Setting::set('maintenance_mode', '0');
        $this->assertFalse(Setting::getBool('maintenance_mode', false));

        Setting::set('maintenance_mode', '1');
        $this->assertTrue(Setting::getBool('maintenance_mode', false));

        Setting::where('key', 'maintenance_mode')->first()->delete();
        $this->assertFalse(Setting::getBool('maintenance_mode', false));
    }

    public function test_each_request_reads_fresh_values(): void
    {
        Setting::set('community_url', 'https://cu');
        $this->assertSame('https://cu', Setting::get('community_url'));

        // Ghi lách model event (query builder thẳng) rồi sang request mới: request mới không
        // được dùng lại bản đã nạp của request trước.
        Setting::where('key', 'community_url')->update(['value' => 'https://moi']);
        $this->app->instance('request', Request::create('/'));

        $this->assertSame('https://moi', Setting::get('community_url'));
    }
}
