<?php

namespace Tests\Unit;

use App\Exceptions\AffiliateScanException;
use App\Services\UrlValidationService;
use PHPUnit\Framework\TestCase;

class UrlValidationServiceTest extends TestCase
{
    private UrlValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UrlValidationService();
    }

    // ── Platform detection ────────────────────────────────────────────────────

    public function test_detects_shopee_platform(): void
    {
        $this->assertSame('shopee', $this->service->validate('https://shopee.vn/product-i.123.456'));
    }

    public function test_detects_shopee_short_link(): void
    {
        $this->assertSame('shopee', $this->service->validate('https://shp.ee/abc123'));
    }

    public function test_detects_shopee_subdomain(): void
    {
        $this->assertSame('shopee', $this->service->validate('https://s.shopee.vn/abc'));
    }

    public function test_detects_shopee_with_www_prefix(): void
    {
        $this->assertSame('shopee', $this->service->validate('https://www.shopee.vn/product'));
    }

    public function test_detects_lazada_platform(): void
    {
        $this->assertSame('lazada', $this->service->validate('https://lazada.vn/products/item.html'));
    }

    public function test_detects_lazada_short_link(): void
    {
        $this->assertSame('lazada', $this->service->validate('https://lzd.co/abc123'));
    }

    public function test_detects_tiki_platform(): void
    {
        $this->assertSame('tiki', $this->service->validate('https://tiki.vn/p123456.html'));
    }

    public function test_detects_tiktok_platform(): void
    {
        $this->assertSame('tiktok', $this->service->validate('https://tiktok.com/shop/product/123'));
    }

    public function test_detects_tiktok_short_link(): void
    {
        $this->assertSame('tiktok', $this->service->validate('https://vt.tiktok.com/abc'));
    }

    // ── Validation failures ───────────────────────────────────────────────────

    public function test_throws_on_unsupported_domain(): void
    {
        $this->expectException(AffiliateScanException::class);
        $this->expectExceptionMessage('Chỉ hỗ trợ link từ Shopee, Lazada, Tiki và TikTok Shop.');
        $this->service->validate('https://amazon.com/product/123');
    }

    public function test_throws_on_url_without_host(): void
    {
        $this->expectException(AffiliateScanException::class);
        $this->service->validate('not-a-url-at-all');
    }

    public function test_throws_on_competitor_domain(): void
    {
        $this->expectException(AffiliateScanException::class);
        $this->service->validate('https://sendo.vn/product');
    }

    // ── Shopee ID extraction ──────────────────────────────────────────────────

    public function test_extracts_shopee_item_and_shop_ids(): void
    {
        $ids = $this->service->extractShopeeIds(
            'https://shopee.vn/ao-thun-i.123456789.987654321'
        );

        $this->assertSame(['item_id' => '123456789', 'shop_id' => '987654321'], $ids);
    }

    public function test_returns_empty_array_when_no_shopee_ids_in_url(): void
    {
        $ids = $this->service->extractShopeeIds('https://shp.ee/abc123');
        $this->assertSame([], $ids);
    }

    public function test_extracts_shopee_ids_from_full_url_with_query(): void
    {
        $ids = $this->service->extractShopeeIds(
            'https://shopee.vn/product-name-i.111.222?smtt=0.0.9'
        );

        $this->assertSame(['item_id' => '111', 'shop_id' => '222'], $ids);
    }
}
