<?php

namespace Tests\Unit;

use App\Services\FacebookDealCaption;
use App\Services\FacebookDealLink;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

class FacebookDealCaptionTest extends TestCase
{
    private FacebookDealCaption $captions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captions = new FacebookDealCaption;
    }

    public function test_spins_nested_choices_and_keeps_link_placeholder_for_the_link(): void
    {
        $template = "{Deal {hời|ngon}|Giá tốt} hôm nay\n\n{link}\n\n{nhanh tay|tranh thủ} {👇|}";
        $seen = [];

        for ($seed = 1; $seed <= 60; $seed++) {
            $text = $this->captions->render($template, "👉 Mua:\nhttps://x.test/go/abc", new Randomizer(new Xoshiro256StarStar($seed)));

            $this->assertStringNotContainsString('{', $text);
            $this->assertStringContainsString("👉 Mua:\nhttps://x.test/go/abc", $text);
            $this->assertMatchesRegularExpression('/^(Deal hời|Deal ngon|Giá tốt) hôm nay\n/u', $text);
            $this->assertDoesNotMatchRegularExpression('/[ \t]+$/m', $text, 'không để khoảng trắng thừa cuối dòng');
            $seen[strtok($text, "\n")] = true;
        }

        $this->assertCount(3, $seen, 'mỗi lựa chọn đều có lúc được chọn');
    }

    public function test_default_template_has_price_and_link_and_neutralises_braces_in_name(): void
    {
        $template = $this->captions->defaultTemplate([
            'product_name' => 'Tai nghe {Pro} | bản mới',
            'original_price' => 300000,
            'discounted_price' => 199000,
            'discount_percent' => 33.6,
        ]);

        $this->assertStringContainsString('{link}', $template);
        $this->assertStringContainsString('Tai nghe (Pro) | bản mới', $template);
        $this->assertStringContainsString('199.000₫ (giá gốc 300.000₫), giảm 34%', $template);

        $text = $this->captions->render($template, 'LINK', new Randomizer(new Xoshiro256StarStar(7)));
        $this->assertStringContainsString('Tai nghe (Pro) | bản mới', $text, 'dấu | trong tên không bị coi là lựa chọn');
        $this->assertStringContainsString("\nLINK\n", $text);
    }

    public function test_default_template_without_product_info(): void
    {
        $template = $this->captions->defaultTemplate(null);

        $this->assertStringContainsString('Sản phẩm Shopee đang giảm giá', $template);
        $this->assertStringNotContainsString('Giá còn', $template);
    }

    public function test_link_block_follows_zalo_wording_and_ytb_two_steps(): void
    {
        $this->assertSame(
            "👉 Bấm link này để mua có mã giảm giá:\nhttps://x.test/go/a",
            (new FacebookDealLink('https://x.test/go/a'))->captionBlock(),
        );

        $ytb = (new FacebookDealLink('https://x.test/go/a', 'https://x.test/ytb/r'))->captionBlock();
        $this->assertStringStartsWith("1️⃣ Mở link này trước để kích hoạt mã:\nhttps://x.test/ytb/r\n2️⃣", $ytb);
        $this->assertStringEndsWith('https://x.test/go/a', $ytb);
    }
}
