<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiConfig;
use App\Services\AccessTradeService;
use App\Services\FacebookCommentProbeService;
use App\Services\FacebookPageService;
use App\Services\GanmaService;
use App\Services\KieuShopeeService;
use App\Services\RestockScheduleService;
use App\Services\ShopeeApiService;
use App\Services\VoucherSourceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApiConfigController extends Controller
{
    public function index(VoucherSourceResolver $sources, RestockScheduleService $restock): Response
    {
        return Inertia::render('Admin/ApiConfig', [
            // Nguồn nào ĐANG thật sự phục vụ khách. Không suy ra từ is_active của từng thẻ được
            // nữa: trong khung giờ back mã FB-IG, activeSource() ghi đè lựa chọn ganma của admin
            // (xem VoucherSourceResolver::activeSource) mà không sửa gì trong DB — nhìn công tắc
            // thì thấy ganma, nhưng mọi lượt quét lại đi qua kieushopee.
            'voucherSource' => [
                'configured' => $sources->configuredSource(),
                'active' => $sources->activeSource(),
                'fbIgWindowEndsAt' => $restock->fbIgWindowEndsAt()?->format('H:i'),
            ],
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
            // BẮT BUỘC: quy tắc 'boolean' không chạy khi thiếu key, nên gửi thiếu is_active thì
            // updateOrCreate bỏ qua cột và DB lấy default true — hai nguồn mã cùng bật, mà
            // makeExclusive() cũng không chạy vì $config->is_active còn null trong bộ nhớ.
            'is_active' => ['required', 'boolean'],
            'platform' => ['required', 'in:shopee,lazada,tiktok,accesstrade,facebook,kieushopee,ganma'],
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
            // Link dùng cho nút "Kiểm tra kết nối" của cả kieushopee lẫn ganma. Với ganma bắt
            // buộc là link ngắn từ app Shopee — xem GanmaService::testConnection().
            'meta.test_url' => ['nullable', 'string', 'max:2000'],
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

        $config = ApiConfig::updateOrCreate(
            ['platform' => $validated['platform']],
            $validated
        );

        // Hai nguồn lấy mã loại trừ nhau: bật cái này thì tắt cái kia. Cho phép bật cả hai là
        // sinh ra câu hỏi "vậy khách đang dùng nguồn nào" mà nhìn giao diện không trả lời được.
        app(VoucherSourceResolver::class)->makeExclusive($config);

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

            // Ganma cũng trả kèm lý do hỏng cụ thể. Lưu ý test này chạy trọn một job thật nên
            // mất ~20 giây — không có health-check nào rẻ hơn, mà chỉ tạo job rồi bỏ thì không
            // phát hiện được ca hay gặp nhất: job chạy xong nhưng sản phẩm không có mã.
            if ($config->platform === GanmaService::SOURCE) {
                $result = app(GanmaService::class)->testConnection();

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
     * Nút "Thử comment" cạnh mỗi bài viết: đăng một comment thật và GIỮ LẠI, trả về đúng URL
     * khách sẽ nhận để admin mở trên điện thoại. Tách khỏi testConnection() vì cái đó chỉ đọc
     * thông tin page, không chứng minh được quyền ĐĂNG — mà quyền đăng mới là thứ cả chế độ
     * này phụ thuộc vào, và cũng không trả lời được câu đắt hơn: khách mở link ra có tới đúng
     * bình luận không.
     */
    public function probeComment(Request $request, ApiConfig $config, FacebookCommentProbeService $probe): JsonResponse
    {
        if ($config->platform !== 'facebook') {
            return response()->json(['ok' => false, 'message' => 'Config này không phải Facebook.'], 422);
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string', 'max:128'],
            // Thử cùng một bài có link và không link để tách "Meta chặn theo loại bài" khỏi
            // "Meta chặn nội dung có link" — xem FacebookCommentProbeService::post().
            'with_link' => ['nullable', 'boolean'],
        ]);

        $result = $probe->post($config, $validated['post_id'], $validated['with_link'] ?? true);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** Xoá comment thử mà nút trên vừa để lại. Chỉ xoá được đúng cái đó — xem FacebookCommentProbeService. */
    public function deleteProbeComment(ApiConfig $config, FacebookCommentProbeService $probe): JsonResponse
    {
        if ($config->platform !== 'facebook') {
            return response()->json(['ok' => false, 'message' => 'Config này không phải Facebook.'], 422);
        }

        $result = $probe->delete($config);

        return response()->json($result, $result['ok'] ? 200 : 422);
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

        // Reel đăng thẳng lên feed thì lọt vào cả /posts. Loại nó khỏi nhóm bài thường vì hai lý
        // do: một reel không được hiện hai lần ở hai chỗ với hai ý nghĩa khác nhau, và quan
        // trọng hơn — comment dưới reel có link BẤM KHÔNG ĐƯỢC, tick nhầm là hỏng âm thầm.
        //
        // HAI lớp lọc, vì mỗi lớp bắt được một dạng mà lớp kia bỏ sót:
        //  • media_type: dạng post id {page_id}_{story_id} với story_id KHÁC video id. Đo trên
        //    page thật 20-09-2026: story 122115295773371579 ứng với video 1560688535789753 —
        //    lọc theo id không bắt nổi, và đây là dạng phổ biến.
        //  • id: dạng {page_id}_{video_id}, bắt được cả khi Graph không trả attachments (token
        //    thiếu quyền, hoặc Meta đổi shape) nên media_type rơi về mặc định 'status'.
        $posts = array_values(array_filter(
            $service->listRecentPosts(),
            fn (array $post) => ($post['media_type'] ?? 'status') !== 'video'
                && ! isset($reelIds[Str::afterLast($post['id'] ?? '', '_')]),
        ));

        return response()->json([
            'posts' => $posts,
            // Comment thử còn sót lại từ lần bấm trước, để trang cảnh báo kể cả sau khi tải lại.
            'pendingProbe' => app(FacebookCommentProbeService::class)->pending($config),
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
