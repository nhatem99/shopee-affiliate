<?php

namespace Tests\Feature;

use App\Models\PayoutAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mục Tài khoản dùng chung một sidebar (AccountLayout.vue): Tổng quan (/profile), Thông tin cá
 * nhân (/profile/thong-tin), Mật khẩu & Bảo mật (/profile/mat-khau, xem ProfilePasswordTest),
 * Lịch sử số dư ví (/vi/lich-su, xem WelcomeBonusTest) và Thông báo (/thong-bao, xem
 * WelcomeBonusTest). File này chỉ phủ hai trang chưa ai test riêng: Tổng quan rút gọn và trang
 * Thông tin cá nhân mới tách ra.
 */
class AccountPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_page_no_longer_carries_personal_info_or_password_fields(): void
    {
        $user = $this->createUser(['phone' => '0901112222']);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile')
                ->where('profile.name', $user->name)
                ->where('profile.email', $user->email)
                ->missing('profile.phone')
                ->missing('profile.has_password'));
    }

    public function test_personal_info_page_exposes_profile_and_payout_accounts(): void
    {
        $user = $this->createUser(['phone' => '0901112222']);
        PayoutAccount::create([
            'user_id' => $user->id,
            'provider' => 'momo',
            'account_number' => '0901112222',
            'account_name' => 'NGUYEN VAN A',
        ]);

        $this->actingAs($user)->get('/profile/thong-tin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/PersonalInfo')
                ->where('profile.name', $user->name)
                ->where('profile.phone', '0901112222')
                ->where('payoutAccounts.momo.account_number', '0901112222'));
    }

    public function test_admin_is_blocked_from_personal_info_page(): void
    {
        $this->actingAs($this->createAdmin())
            ->get('/profile/thong-tin')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/profile/thong-tin')->assertRedirect('/login');
    }
}
