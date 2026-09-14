<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /profile/password: đổi mật khẩu cho khách đăng ký bằng email, hoặc ĐẶT mật khẩu lần đầu cho
 * khách chỉ từng đăng nhập Google — hai nhóm này khác nhau ở việc có bắt nhập mật khẩu cũ hay
 * không, xem User::hasUsablePassword().
 */
class ProfilePasswordTest extends TestCase
{
    use RefreshDatabase;

    /** Khách đăng ký thường: GoogleController không đụng tới nên password là hash thật. */
    private function customerWithPassword(string $password = 'OldPass1'): User
    {
        $user = $this->createUser(['password' => Hash::make($password)]);

        return $user;
    }

    /** Y hệt cách GoogleController tạo user mới — password '' được cast 'hashed' băm lại. */
    private function googleOnlyCustomer(): User
    {
        return $this->createUser(['google_id' => '1234567890', 'password' => '']);
    }

    public function test_guest_cannot_change_password(): void
    {
        $this->post('/profile/password', [])->assertRedirect('/login');
    }

    public function test_admin_is_blocked_from_the_customer_profile_password_route(): void
    {
        $this->actingAs($this->createAdmin())
            ->post('/profile/password', [])
            ->assertForbidden();
    }

    public function test_customer_with_a_real_password_must_provide_the_current_one(): void
    {
        $user = $this->customerWithPassword('OldPass1');

        $this->actingAs($user)
            ->post('/profile/password', [
                'password' => 'NewPass2',
                'password_confirmation' => 'NewPass2',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('OldPass1', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->customerWithPassword('OldPass1');

        $this->actingAs($user)
            ->post('/profile/password', [
                'current_password' => 'WrongOne1',
                'password' => 'NewPass2',
                'password_confirmation' => 'NewPass2',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('OldPass1', $user->fresh()->password));
    }

    public function test_customer_can_change_password_with_the_correct_current_one(): void
    {
        $user = $this->customerWithPassword('OldPass1');

        $this->actingAs($user)
            ->post('/profile/password', [
                'current_password' => 'OldPass1',
                'password' => 'NewPass2',
                'password_confirmation' => 'NewPass2',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Đã đổi mật khẩu.');

        $this->assertTrue(Hash::check('NewPass2', $user->fresh()->password));
    }

    /** Trọng tâm của tính năng: khách Google không có gì để nhập vào ô mật khẩu cũ. */
    public function test_google_only_customer_can_set_a_password_without_a_current_one(): void
    {
        $user = $this->googleOnlyCustomer();
        $this->assertFalse($user->hasUsablePassword());

        $this->actingAs($user)
            ->post('/profile/password', [
                'password' => 'NewPass2',
                'password_confirmation' => 'NewPass2',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Đã đặt mật khẩu.');

        $user = $user->fresh();
        $this->assertTrue(Hash::check('NewPass2', $user->password));
        $this->assertTrue($user->hasUsablePassword());
    }

    public function test_password_must_meet_the_complexity_rules(): void
    {
        $user = $this->googleOnlyCustomer();

        $this->actingAs($user)
            ->post('/profile/password', [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_password_confirmation_must_match(): void
    {
        $user = $this->googleOnlyCustomer();

        $this->actingAs($user)
            ->post('/profile/password', [
                'password' => 'NewPass2',
                'password_confirmation' => 'Different2',
            ])
            ->assertSessionHasErrors('password');
    }

    /**
     * Trang này sống ở /profile/mat-khau (mục "Mật khẩu & Bảo mật" trong sidebar Tài khoản —
     * xem AccountLayout.vue), tách khỏi /profile (Tổng quan) chứ không gộp chung một trang nữa.
     */
    public function test_password_page_exposes_has_password_flag(): void
    {
        $this->actingAs($this->googleOnlyCustomer())
            ->get('/profile/mat-khau')
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Password')
                ->where('hasPassword', false)
            );

        $this->actingAs($this->customerWithPassword())
            ->get('/profile/mat-khau')
            ->assertInertia(fn ($page) => $page->where('hasPassword', true));
    }
}
