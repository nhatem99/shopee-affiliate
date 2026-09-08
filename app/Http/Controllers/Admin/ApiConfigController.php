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
use Illuminate\Support\Str;
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
            // Nhóm bài viết nhận comment. Nhiều bài để comment của các sản phẩm khác nhau rải
            // ra thay vì dồn một chỗ khi nhiều khách bấm cùng lúc — xem ApiConfig::facebookTargetPostIds.
            'meta.target_post_ids' => ['nullable', 'array', 'max:20'],
            'meta.target_post_ids.*' => ['string', 'max:255'],
            // Chế độ đổi caption reel — xem FacebookReelSlotService.
            'meta.reel_caption_enabled' => ['nullable', 'boolean'],
            'meta.target_reel_ids' => ['nullable', 'array', 'max:20'],
            'meta.target_reel_ids.*' => ['string', 'max:255'],
            'meta.reel_lease_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
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

    /**
     * Danh sách bài để admin tick ở form sửa cấu hình Facebook, tách sẵn thành hai nhóm vì hai
     * nhóm dùng vào hai việc khác nhau: bài thường để đăng comment, reel để đổi caption.
     *
     * Phải gọi hai edge vì Graph API không trả reels ở /posts (xem FacebookPageService::listReels).
     */
    public function facebookPosts(ApiConfig $config): JsonResponse
    {
        if ($config->platform !== 'facebook') {
            return response()->json(['message' => 'Config này không phải Facebook.'], 422);
        }

        $service = new FacebookPageService($config->app_id, $config->app_secret);

        $reels = $service->listReels();
        $reelIds = array_flip(array_column($reels, 'id'));

        // Reel đăng thẳng lên feed thì lọt vào cả /posts. Loại nó khỏi nhóm bài thường để một
        // reel không hiện hai lần ở hai chỗ với hai ý nghĩa khác nhau.
        $posts = array_values(array_filter(
            $service->listRecentPosts(),
            fn (array $post) => ! isset($reelIds[Str::afterLast($post['id'] ?? '', '_')]),
        ));

        return response()->json([
            'posts' => $posts,
            // Reel dùng field `description` cho caption — đổi tên thành `message` để phía Vue
            // hiển thị hai nhóm bằng cùng một khuôn.
            'reels' => array_map(fn (array $reel) => [
                'id' => $reel['id'] ?? '',
                'message' => $reel['description'] ?? null,
                'created_time' => $reel['created_time'] ?? null,
                'permalink_url' => $reel['permalink_url'] ?? null,
            ], $reels),
        ]);
    }
}
