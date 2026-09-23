<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Setting;
use App\Models\User;
use App\Services\AvatarService;
use App\Services\CashbackLeaderboardService;
use App\Services\CashbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ảnh đại diện khách tự tải ở /profile/thong-tin, hiện lại ở bảng vàng hoàn tiền.
 *
 * Ảnh này đi ra trang CÔNG KHAI nên ngoài chuyện lưu/xoá được, phải chắc hai việc: file gốc
 * (kèm EXIF vị trí) không bao giờ được đăng nguyên xi, và đường dẫn file không nhận được từ
 * request.
 */
class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function jpeg(string $name = 'anh.jpg', int $width = 800, int $height = 400): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    public function test_tai_anh_len_thi_luu_file_va_gan_vao_tai_khoan(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => $this->jpeg()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk('uploads')->assertExists($user->avatar_path);
        $this->assertStringStartsWith('avatars/', $user->avatar_path);
    }

    /**
     * Ảnh được vẽ lại chứ không lưu nguyên file: đây là thứ làm rụng EXIF (toạ độ GPS nơi chụp)
     * trước khi ảnh lên bảng vàng, và là lý do 10 avatar trên trang chủ không kéo theo 40MB.
     */
    public function test_anh_duoc_cat_vuong_va_thu_nho(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => $this->jpeg('to.jpg', 1200, 900)]);

        $binary = Storage::disk('uploads')->get($user->refresh()->avatar_path);
        [$width, $height, $type] = getimagesizefromstring($binary);

        $this->assertSame(AvatarService::SIZE, $width);
        $this->assertSame(AvatarService::SIZE, $height);
        $this->assertSame(IMAGETYPE_JPEG, $type);
    }

    public function test_tu_choi_file_khong_phai_anh_hoac_qua_nang(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('avatar');

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => $this->jpeg()->size(AvatarService::MAX_IMAGE_KB + 1)])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_doi_anh_thi_xoa_anh_cu_va_go_anh_thi_xoa_han(): void
    {
        Storage::fake('uploads');
        $user = $this->createUser();

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => $this->jpeg('cu.jpg')]);
        $old = $user->refresh()->avatar_path;

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => $this->jpeg('moi.jpg')]);
        $new = $user->refresh()->avatar_path;

        $this->assertNotSame($old, $new);
        Storage::disk('uploads')->assertMissing($old);
        Storage::disk('uploads')->assertExists($new);

        $this->actingAs($user)->delete('/profile/avatar')->assertRedirect();

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('uploads')->assertMissing($new);
    }

    /**
     * avatar_path là đường dẫn file trên server. Nhận được nó từ form sửa hồ sơ là khách trỏ
     * avatar của mình vào bất kỳ file nào trong thư mục uploads (vd: ảnh chat của người khác).
     */
    public function test_khong_dat_duoc_duong_dan_anh_qua_form_ho_so(): void
    {
        $user = $this->createUser(['name' => 'Nguyễn Văn An']);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Nguyễn Văn An',
            'avatar_path' => 'chat/2026/09/cua-nguoi-khac.jpg',
        ])->assertRedirect();

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_bang_vang_tra_ve_anh_cua_nguoi_co_anh(): void
    {
        Storage::fake('uploads');
        Setting::set(CashbackService::RATE_KEY, '50');
        Cache::flush();

        $withPhoto = $this->createUser(['name' => 'Nguyễn Văn An']);
        $withoutPhoto = $this->createUser(['name' => 'Trần Bình']);

        $this->credit($withPhoto, 20000);
        $this->credit($withoutPhoto, 10000);

        $this->actingAs($withPhoto)->post('/profile/avatar', ['avatar' => $this->jpeg()]);

        $board = app(CashbackLeaderboardService::class)->forMonth();

        // URL đầy đủ (tiền tố do disk quyết định, test chạy trên disk giả) — cái cần chắc là nó
        // trỏ đúng file vừa lưu, không phải đường dẫn thô trong DB.
        $this->assertStringContainsString($withPhoto->refresh()->avatar_path, $board['entries'][0]['avatar']);
        $this->assertNull($board['entries'][1]['avatar']);

        // Tên vẫn phải che như cũ — có ảnh không phải là lý do để hiện tên đầy đủ.
        $this->assertSame('Nguyễn V*** A***', $board['entries'][0]['name']);
    }

    /**
     * Bảng vàng cache 10 phút. Đổi ảnh xong mà vào xem vẫn thấy chữ cái cũ thì khách tưởng tải
     * hỏng và tải lại mãi.
     */
    public function test_doi_anh_thi_bang_vang_cap_nhat_ngay(): void
    {
        Storage::fake('uploads');
        Setting::set(CashbackService::RATE_KEY, '50');
        Cache::flush();

        $user = $this->createUser();
        $this->credit($user, 20000);

        $this->assertNull(app(CashbackLeaderboardService::class)->forMonth()['entries'][0]['avatar']);

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => $this->jpeg()]);

        $this->assertNotNull(app(CashbackLeaderboardService::class)->forMonth()['entries'][0]['avatar']);
    }

    private function credit(User $user, float $amount): Commission
    {
        static $n = 0;
        $n++;

        return Commission::create([
            'user_id' => $user->id,
            'affiliate_link_id' => null,
            'order_id' => 'AV'.$n,
            'amount' => $amount,
            'status' => 'approved',
            'confirmed_at' => now(),
        ]);
    }
}
