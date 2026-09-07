<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiConfig;
use App\Services\AccessTradeService;
use App\Services\FacebookPageService;
use App\Services\KieuShopeeService;
use App\Services\ShopeeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/ApiConfig', [
            'configs' => ApiConfig::all()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'endpoint' => $c->endpoint,
                'app_id' => $c->app_id,
                'is_active' => $c->is_active,
                'platform' => $c->platform,
                'meta' => $c->meta,
                'updated_at' => $c->updated_at?->toDateString(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // Không ép kiểu 'url' nữa — provider 'facebook' dùng field này cho Graph API base URL,
            // nhưng vẫn cần chấp nhận chuỗi thường để tương thích chung.
            'endpoint' => ['required', 'string', 'max:255'],
            'app_id' => ['nullable', 'string'],
            // Ô nhập token/secret ở form edit luôn để trống với nghĩa "giữ nguyên giá trị cũ" —
            // chỉ bắt buộc khi tạo mới (chưa có bản ghi nào cho platform này).
            'app_secret' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'platform' => ['required', 'in:shopee,lazada,tiktok,accesstrade,facebook,kieushopee'],
            'meta' => ['nullable', 'array'],
            'meta.target_post_id' => ['nullable', 'string', 'max:255'],
            // Tham số gọi kieushopee — next_action đổi mỗi lần site nguồn deploy lại, nên phải
            // sửa được từ trang admin thay vì phải sửa code rồi deploy. Xem KieuShopeeService.
            'meta.next_action' => ['nullable', 'string', 'max:255'],
            'meta.tool_id' => ['nullable', 'string', 'max:255'],
            'meta.action_payload' => ['nullable', 'string', 'max:255'],
            'meta.comment_redirect_enabled' => ['nullable', 'boolean'],
            // Thời salesoc đây là DANH SÁCH chọn loại mã (meta.auto_source) vì một sản phẩm có
            // nhiều mã. kieushopee chỉ trả một link nên không còn gì để chọn — thành công tắc.
            'meta.auto_redirect_enabled' => ['nullable', 'boolean'],
        ]);

        $existing = ApiConfig::where('platform', $validated['platform'])->first();

        if (empty($validated['app_secret'])) {
            if (! $existing) {
                return back()->withErrors(['app_secret' => 'Vui lòng nhập token/secret khi tạo cấu hình mới.']);
            }

            unset($validated['app_secret']);
        }

        ApiConfig::updateOrCreate(
            ['platform' => $validated['platform']],
            $validated
        );

        return back()->with('success', 'Cấu hình API đã được lưu.');
    }

    public function test(ApiConfig $config): JsonResponse
    {
        try {
            // kieushopee trả kèm lý do hỏng cụ thể (thường là next_action đã đổi) chứ không chỉ
            // ok/không — đây là nguồn hay chết nhất nên thông báo phải chỉ thẳng việc cần làm.
            if ($config->platform === KieuShopeeService::SOURCE) {
                $result = app(KieuShopeeService::class)->testConnection();

                return response()->json($result, $result['ok'] ? 200 : 422);
            }

            $ok = match ($config->platform) {
                'shopee' => app(ShopeeApiService::class)->testConnection($config),
                'accesstrade' => app(AccessTradeService::class)->testConnection($config),
                'facebook' => (new FacebookPageService($config->app_id, $config->app_secret))->testConnection(),
                default => throw new \Exception('Platform không được hỗ trợ.'),
            };

            return response()->json([
                'ok' => $ok,
                'message' => $ok ? 'Kết nối thành công!' : 'Kết nối thất bại.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function facebookPosts(ApiConfig $config): JsonResponse
    {
        if ($config->platform !== 'facebook') {
            return response()->json(['message' => 'Config này không phải Facebook.'], 422);
        }

        $posts = (new FacebookPageService($config->app_id, $config->app_secret))->listRecentPosts();

        return response()->json(['posts' => $posts]);
    }
}
