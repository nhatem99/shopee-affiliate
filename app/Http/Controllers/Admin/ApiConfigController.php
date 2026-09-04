<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiConfig;
use App\Services\AccessTradeService;
use App\Services\FacebookPageService;
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
            'platform' => ['required', 'in:shopee,lazada,tiktok,accesstrade,facebook'],
            'meta' => ['nullable', 'array'],
            'meta.target_post_id' => ['nullable', 'string', 'max:255'],
            'meta.comment_redirect_enabled' => ['nullable', 'boolean'],
            'meta.auto_source' => ['nullable', 'string', 'in:facebook,instagram,zalo,youtube'],
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
