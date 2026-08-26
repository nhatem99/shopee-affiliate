<?php

namespace Database\Seeders;

use App\Models\BlogPost;
use Illuminate\Database\Seeder;

class BlogPostSeeder extends Seeder
{
    public function run(): void
    {
        $posts = [
            [
                // slug cố định — không suy ra từ title để đổi tên miền/tiêu đề sau này không tạo bài trùng.
                'slug' => 'cach-lay-voucher-facebook-shopee-2026',
                'title' => 'Cách lấy voucher Facebook Shopee 2026',
                'excerpt' => 'Hướng dẫn nhanh cách lấy voucher Shopee độc quyền dành cho người theo dõi Facebook, chỉ với vài bước đơn giản.',
                'body' => <<<'TXT'
Nhiều shop và fanpage trên Facebook chia sẻ voucher Shopee độc quyền cho người theo dõi, nhưng không phải ai cũng biết cách lấy đúng link để được áp mã.

Các bước thực hiện:

1. Copy link sản phẩm Shopee bạn muốn mua (từ ứng dụng Shopee hoặc trình duyệt).
2. Dán link vào ô tìm kiếm trên tietkiemvi.com.
3. Chọn nút "Lấy link Facebook" để tạo link voucher dành riêng cho kênh Facebook.
4. Bấm vào link vừa tạo để mở Shopee, giá hiển thị đã bao gồm voucher đang áp dụng.

Voucher hiển thị là giá dự kiến theo chương trình khuyến mãi hiện tại — mức giảm chính xác sẽ được xác nhận khi bạn hoàn tất thanh toán trên Shopee.
TXT,
            ],
            [
                'slug' => 'voucher-shopee-doc-quyen-youtube-la-gi',
                'title' => 'Voucher Shopee độc quyền YouTube là gì',
                'excerpt' => 'Voucher Shopee độc quyền YouTube là mã ưu đãi được các kênh YouTube giới thiệu, giúp người xem mua hàng với giá tốt hơn.',
                'body' => <<<'TXT'
Voucher Shopee độc quyền YouTube là các mã giảm giá, ưu đãi được đội ngũ Shopee cấp riêng cho những người xem đến từ kênh YouTube, nhằm khuyến khích mua sắm qua các video giới thiệu sản phẩm.

Vì sao nên dùng link riêng cho YouTube?

- Một số chương trình chỉ áp dụng cho nguồn truy cập từ YouTube.
- Giúp nhà sáng tạo nội dung (KOC) được ghi nhận đúng nguồn traffic.
- Người xem dễ dàng lấy đúng ưu đãi đang được giới thiệu trong video.

Trên tietkiemvi.com, bạn chỉ cần dán link sản phẩm và chọn nút "Lấy link YouTube" để tạo link phù hợp, không cần thao tác phức tạp.
TXT,
            ],
            [
                // slug cũ để tìm đúng bài đã seed trước khi đổi tên miền — 'slug' bên dưới mới là slug hiển thị.
                'match_slug' => 'huong-dan-su-dung-sanvouchervn',
                'slug' => 'huong-dan-su-dung-tietkiemvi-com',
                'title' => 'Hướng dẫn sử dụng tietkiemvi.com',
                'excerpt' => 'Toàn bộ hướng dẫn sử dụng tietkiemvi.com từ A-Z: dán link, lấy voucher, và mẹo sử dụng cho người sáng tạo nội dung.',
                'body' => <<<'TXT'
tietkiemvi.com là công cụ miễn phí giúp bạn dán link sản phẩm Shopee và nhận ngay link voucher độc quyền cho Facebook, YouTube hoặc Instagram.

Cách sử dụng:

1. Truy cập trang chủ tietkiemvi.com.
2. Dán link sản phẩm Shopee (link đầy đủ hoặc link rút gọn s.shopee.vn) vào ô nhập.
3. Nhấn nút lấy voucher và chờ hệ thống xử lý.
4. Chọn nền tảng bạn muốn chia sẻ (Facebook, YouTube, Instagram) để lấy đúng link tương ứng.
5. Sao chép và chia sẻ link, hoặc lưu lại để dùng sau — lịch sử link gần đây được lưu ngay trên trình duyệt của bạn.

Giá và mức giảm hiển thị chỉ mang tính chất tham khảo, mức giảm thực tế được xác nhận tại bước thanh toán trên Shopee.
TXT,
            ],
        ];

        foreach ($posts as $post) {
            // Tìm theo slug hiện tại HOẶC slug cũ (nếu có) — tránh vi phạm unique khi
            // bài đã được đổi slug ở lần seed trước, hoặc tạo trùng khi chưa từng đổi.
            $existing = BlogPost::where('slug', $post['slug'])
                ->when(isset($post['match_slug']), fn ($q) => $q->orWhere('slug', $post['match_slug']))
                ->first();

            BlogPost::updateOrCreate(
                $existing ? ['id' => $existing->id] : ['slug' => $post['slug']],
                [
                    'slug' => $post['slug'],
                    'title' => $post['title'],
                    'excerpt' => $post['excerpt'],
                    'body' => $post['body'],
                    'published_at' => now(),
                ]
            );
        }
    }
}
