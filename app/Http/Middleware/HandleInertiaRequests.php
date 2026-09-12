<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ProfileController;
use App\Models\Setting;
use App\Services\CashbackService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'settings' => [
                'customerAuthEnabled' => Setting::getBool('customer_auth_enabled', true),
                // Link nhóm/cộng đồng săn sale hiện ở banner "Khung giờ back mã"
                // (RestockSchedule.vue). Chưa đặt thì banner tự ẩn dòng link đi.
                'communityUrl' => Setting::get('community_url') ?: null,
                // Chỉ admin cần cờ này: admin duyệt trang khách y như bình thường khi đang bảo
                // trì (xem MaintenanceMode) nên rất dễ quên là khách vẫn đang bị chặn — AppLayout
                // dựa vào đây để hiện thanh nhắc.
                'maintenanceMode' => ($request->user()?->isAdmin() ?? false)
                    && Setting::getBool('maintenance_mode', false),
                // Tỉ lệ hoàn tiền — chia sẻ toàn cục vì nội dung quảng bá hoàn tiền nằm rải khắp
                // các trang khách (trang chủ, tài khoản, thanh điều hướng), không riêng trang nào.
                //
                // Đây cũng là CÔNG TẮC của toàn bộ nội dung đó: mặc định setting này bằng 0, và
                // khi bằng 0 thì CashbackService::sync() không tạo đồng hoa hồng nào (xem
                // CashbackService::sync). Nói chuyện hoàn tiền trong lúc hệ thống trả 0đ cho tất
                // cả mọi người là hứa suông — nên frontend bọc mọi khối hoàn tiền bằng
                // `cashbackRate > 0` (xem composable useCashback).
                'cashbackRate' => (float) Setting::get(CashbackService::RATE_KEY, 0),
                // Mức rút tối thiểu đi kèm luôn: nội dung nào nhắc tới con số này (trang tài
                // khoản, khối giải thích ở trang chủ) cũng phải lấy động, tránh cảnh sửa hằng số
                // trong PHP rồi quên mất mấy chỗ đã gõ cứng "50.000đ" trong .vue.
                'minWithdrawal' => ProfileController::MIN_WITHDRAWAL,
            ],
        ]);
    }
}
