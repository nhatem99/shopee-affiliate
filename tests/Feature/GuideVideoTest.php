<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\GuideVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Trang hướng dẫn /huong-dan và video gắn vào nó.
 *
 * Hai thứ ở đây hỏng mà KHÔNG ai biết, nên phải khoá bằng test:
 *  1. Link không nhận dạng được mà vẫn lưu → khách chỉ thấy một khung trắng, admin thì tưởng
 *     đã xong vì trang cài đặt báo "Đã lưu".
 *  2. Hai nguồn (link nhúng và file tải lên) cùng sống → không ai đoán được trang đang phát
 *     cái nào, và file cũ nằm lại chiếm ổ đĩa mãi mãi.
 */
class GuideVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_trang_huong_dan_mo_duoc_khi_chua_dat_video(): void
    {
        // Không video thì trang vẫn phải sống: phần hướng dẫn bằng chữ mới là nội dung chính.
        $this->get('/huong-dan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Guide')->where('video', null));
    }

    public function test_link_youtube_thanh_link_nhung_dung_dinh_dang(): void
    {
        $service = app(GuideVideoService::class);

        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ&feature=share',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
        ] as $url) {
            $parsed = $service->parse($url);

            $this->assertNotNull($parsed, "Không nhận dạng được: {$url}");
            $this->assertSame('embed', $parsed['kind']);
            $this->assertStringContainsString('/embed/dQw4w9WgXcQ', $parsed['src']);
            $this->assertSame('landscape', $parsed['orientation']);
        }
    }

    public function test_youtube_shorts_va_tiktok_bao_la_khung_doc(): void
    {
        $service = app(GuideVideoService::class);

        // Khung dọc mà dựng theo 16:9 thì video chỉ còn một dải nhỏ giữa hai mảng đen.
        $this->assertSame('portrait', $service->parse('https://www.youtube.com/shorts/dQw4w9WgXcQ')['orientation']);
        $this->assertSame('portrait', $service->parse('https://www.tiktok.com/@ten/video/7300000000000000000')['orientation']);
    }

    public function test_link_khong_nhan_dang_duoc_thi_khong_luu(): void
    {
        $admin = $this->createAdmin();

        // vt.tiktok.com là link rút gọn — phải gọi mạng mới ra id, cố ý không nhận.
        foreach (['https://vt.tiktok.com/ZSabc123/', 'https://example.com/khong-phai-video'] as $url) {
            $this->actingAs($admin)
                ->post('/admin/settings/guide-video', ['video_url' => $url])
                ->assertSessionHasErrors('video_url');
        }

        $this->assertSame('', (string) Setting::get(GuideVideoService::URL_KEY, ''));
    }

    public function test_tai_file_len_thi_link_cu_bi_go(): void
    {
        Storage::fake('uploads');

        $admin = $this->createAdmin();
        $service = app(GuideVideoService::class);

        $service->saveUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->actingAs($admin)
            ->post('/admin/settings/guide-video', [
                'video_file' => UploadedFile::fake()->create('huong-dan.mp4', 512, 'video/mp4'),
            ])
            ->assertSessionHasNoErrors();

        // Đúng MỘT nguồn sống: link cũ phải biến mất, không để hai thứ cùng trỏ vào trang khách.
        $this->assertSame('', (string) Setting::get(GuideVideoService::URL_KEY, ''));

        $path = (string) Setting::get(GuideVideoService::PATH_KEY, '');
        $this->assertNotSame('', $path);
        Storage::disk('uploads')->assertExists($path);

        $this->assertSame('file', $service->current()['kind']);
    }

    public function test_luu_link_thi_file_cu_bi_xoa_khoi_o_dia(): void
    {
        Storage::fake('uploads');

        $admin = $this->createAdmin();
        $service = app(GuideVideoService::class);

        $service->storeFile(UploadedFile::fake()->create('cu.mp4', 128, 'video/mp4'));
        $oldPath = (string) Setting::get(GuideVideoService::PATH_KEY, '');

        $this->actingAs($admin)
            ->post('/admin/settings/guide-video', ['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
            ->assertSessionHasNoErrors();

        // Không xoá thì mỗi lần admin đổi ý lại bỏ lại một file vài chục MB không ai dọn.
        Storage::disk('uploads')->assertMissing($oldPath);
        $this->assertSame('', (string) Setting::get(GuideVideoService::PATH_KEY, ''));
        $this->assertSame('youtube', $service->current()['provider']);
    }

    public function test_file_bi_mat_khoi_o_dia_thi_coi_nhu_chua_dat(): void
    {
        Storage::fake('uploads');

        $service = app(GuideVideoService::class);
        $service->storeFile(UploadedFile::fake()->create('mat.mp4', 128, 'video/mp4'));

        // Deploy bằng cách copy mã nguồn không mang theo public/uploads — setting còn, file mất.
        Storage::disk('uploads')->delete((string) Setting::get(GuideVideoService::PATH_KEY, ''));

        $this->assertNull($service->current());
        $this->get('/huong-dan')->assertOk();
    }

    public function test_khach_thuong_khong_dat_duoc_video(): void
    {
        $this->post('/admin/settings/guide-video', ['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
            ->assertRedirect();

        $this->actingAs($this->createUser())
            ->post('/admin/settings/guide-video', ['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
            ->assertForbidden();

        $this->assertSame('', (string) Setting::get(GuideVideoService::URL_KEY, ''));
    }

    public function test_trang_cai_dat_mang_du_thong_tin_de_admin_dat_video(): void
    {
        // maxUploadMb hiện thẳng trên giao diện: file nặng hơn post_max_size chết ở tầng PHP
        // trước khi vào validate, admin chỉ thấy lỗi chung chung rồi thử lại mãi.
        $this->actingAs($this->createAdmin())
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('guideVideo.url')
                ->has('guideVideo.video')
                ->where('guideVideo.maxUploadMb', fn ($mb) => $mb >= 1)
            );
    }

    public function test_go_video_thi_trang_ve_lai_trang_thai_chi_co_chu(): void
    {
        $admin = $this->createAdmin();
        $service = app(GuideVideoService::class);

        $service->saveUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->actingAs($admin)
            ->delete('/admin/settings/guide-video')
            ->assertSessionHasNoErrors();

        $this->assertNull($service->current());
    }
}
