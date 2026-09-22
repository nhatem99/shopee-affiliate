<?php

namespace App\Http\Controllers;

use App\Services\GuideVideoService;
use App\Services\TrackingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trang hướng dẫn lấy mã (/huong-dan) — có URL riêng để dán thẳng vào bài đăng Facebook/Zalo và
 * để khách đang bí ở bước kích hoạt mã YTB có một chỗ xem lại từ đầu, thay vì đọc mấy dòng chữ
 * nhỏ kẹp giữa các nút bấm ở trang kết quả.
 *
 * Chưa đặt video thì trang vẫn còn nguyên phần hướng dẫn bằng chữ — phần chữ mới là nội dung
 * chính, video là thứ đi kèm. Không 404 như /hoan-tien: trang kia không có tỉ lệ hoàn tiền thì
 * thật sự không còn gì để nói, còn trang này thì có.
 */
class GuideController extends Controller
{
    public function __construct(private GuideVideoService $guideVideo) {}

    public function index(Request $request, TrackingService $tracking): Response
    {
        $tracking->log('page_view', $request, ['url' => $request->fullUrl()]);

        return Inertia::render('Guide', [
            'video' => $this->guideVideo->current(),
        ]);
    }
}
