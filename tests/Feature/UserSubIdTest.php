<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sub_id là thứ nối một đơn hàng có thật trong báo cáo Shopee về đúng một khách hàng. Thiếu nó,
 * hoặc sinh trùng, là hoàn tiền sai người — nên các ràng buộc dưới đây phải được khoá bằng test
 * chứ không dựa vào việc nhớ.
 */
class UserSubIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_new_user_gets_a_sub_id(): void
    {
        $this->assertNotEmpty($this->createUser()->sub_id);
    }

    /** Bốn đường tạo user đều đi qua model event, nên đăng ký qua HTTP cũng phải có mã. */
    public function test_registration_through_http_also_gets_a_sub_id(): void
    {
        $this->setUpRoles();

        $this->post('/register', [
            'name' => 'Nguyen Van A',
            'email' => 'a@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $this->assertNotEmpty(User::where('email', 'a@example.com')->value('sub_id'));
    }

    public function test_sub_ids_are_unique_across_users(): void
    {
        $codes = collect(range(1, 20))->map(fn () => $this->createUser()->sub_id);

        $this->assertCount(20, $codes->unique());
    }

    /**
     * Dấu "-" là ký tự Shopee dùng tách 5 khe của ô Sub_id. Mã khách lẫn dấu này vào là khâu
     * đọc báo cáo tách nhầm khe và quy đơn về sai người.
     */
    public function test_sub_id_never_contains_the_slot_separator(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', User::generateSubId());
        }
    }

    /**
     * Trùng marker kênh thì AffiliateLinkRewriterService::resolveSubId() nhận nhầm mã khách
     * thành mã kênh IG/YT và gắn sai nhãn cho cả nhóm traffic.
     *
     * Kiểm tra thẳng hàm chặn qua reflection: mã ngẫu nhiên 10 ký tự gần như không bao giờ rơi
     * trúng marker, nên một test kiểu "sinh mã rồi so với marker" sẽ xanh kể cả khi hàm chặn bị
     * xoá sạch — tức không chứng minh được gì.
     */
    public function test_reserved_labels_are_rejected_as_sub_ids(): void
    {
        config([
            'services.shopee_affiliate.ig_markers' => ['ig', 'insta'],
            'services.shopee_affiliate.yt_markers' => ['yt3'],
            'services.shopee_affiliate.utm_content' => 'fb',
        ]);

        $isReserved = new \ReflectionMethod(User::class, 'subIdIsReserved');

        foreach (['ig', 'insta', 'yt3', 'fb'] as $reserved) {
            $this->assertTrue($isReserved->invoke(null, $reserved), "'{$reserved}' phải bị từ chối");
        }

        $this->assertFalse($isReserved->invoke(null, 'k3m9x1qp7a'));
    }

    /** Không mass-assignable: chỗ này quyết định tiền về ví ai, như 'role'. */
    public function test_sub_id_cannot_be_mass_assigned(): void
    {
        $user = $this->createUser();
        $original = $user->sub_id;

        $user->update(['sub_id' => 'cuatoi']);

        $this->assertSame($original, $user->fresh()->sub_id);
    }
}
