<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AffiliateScanException;
use App\Http\Controllers\Controller;
use App\Models\FacebookGroup;
use App\Models\FacebookGroupDeal;
use App\Models\FacebookGroupPost;
use App\Models\FacebookPostTemplate;
use App\Models\ShortLink;
use App\Services\DirectAffiliateLinkService;
use App\Services\FacebookDealCaption;
use App\Services\FacebookDealLinkBuilder;
use App\Services\FacebookGroupPostScheduler;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\FacebookPostImages;
use App\Services\UrlValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /admin/fb-posts — dán link Shopee, hệ thống soạn sẵn bài, admin sửa rồi chọn nhóm; hoặc tự
 * soạn bài không link. Ảnh tự tải lên được cho cả hai kiểu. Bot trên máy nhà đăng dần theo nhịp
 * ở /admin/fb-groups.
 */
class FacebookGroupPostController extends Controller
{
    private const PRODUCT_KEYS = ['product_name', 'product_image', 'original_price', 'discounted_price', 'discount_percent'];

    public function __construct(
        private FacebookGroupPostScheduler $scheduler,
        private FacebookGroupRunnerSettings $settings,
        private FacebookPostImages $images,
    ) {}

    public function index(): Response
    {
        $deals = FacebookGroupDeal::with(['posts.group:id,name,url', 'posts.profile:id,name,is_primary'])->latest('id')->limit(30)->get();

        // Một link mua có thể dùng chung cho nhiều nhóm (ShortLinkService tái dùng link cùng
        // đích) — cộng lượt bấm theo link riêng biệt của từng bài deal, không theo từng nhóm.
        $clicks = ShortLink::whereIn('id', $deals->flatMap->posts->pluck('short_link_id')->filter()->unique())
            ->pluck('clicks', 'id');

        $runnerVersion = $this->settings->runnerStatus()['version'];
        $runnerSupportsUploads = FacebookGroupPostScheduler::runnerSupportsUploads($runnerVersion);

        $groups = FacebookGroup::enabled()->orderBy('name')->get(['id', 'name', 'url', 'last_posted_at', 'last_attempt_at']);
        $readiness = $this->scheduler->groupReadiness($groups);

        return Inertia::render('Admin/FacebookGroupPosts', [
            'groups' => $groups->map(fn (FacebookGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'url' => $group->url,
                'last_posted_at' => $group->last_posted_at,
            ] + $readiness[$group->id]),
            'cooldownHours' => $this->settings->cadence()['cooldown_hours'],
            'deals' => $deals->map(fn (FacebookGroupDeal $deal) => [
                'id' => $deal->id,
                'created_at' => $deal->created_at,
                'shopee_url' => $deal->shopee_url,
                'custom' => $deal->isCustom(),
                'excerpt' => $deal->isCustom() ? Str::limit(trim(strtok($deal->caption, "\n")), 120) : null,
                'images' => $this->images->adminUrlsFor($deal),
                // Nút "Dùng lại": nạp nội dung + ảnh của bài tự soạn này vào ô soạn bài.
                'reuse' => $deal->isCustom() ? ['caption' => $deal->caption, 'images' => $this->images->adminItems($deal->images)] : null,
                'product' => $deal->product,
                'clicks' => $deal->posts->pluck('short_link_id')->filter()->unique()->sum(fn ($id) => $clicks[$id] ?? 0),
                'posts' => $deal->posts->sortBy('id')->values()->map(fn (FacebookGroupPost $post) => [
                    'id' => $post->id,
                    'status' => $post->status,
                    'group_name' => $post->group?->name,
                    'group_url' => $post->group?->url,
                    'profile_name' => $post->profile?->label(),
                    'claimed_at' => $post->claimed_at,
                    'finished_at' => $post->finished_at,
                    'link_kind' => $post->link_kind,
                    'error' => $post->error,
                    'review_state' => $post->review_state,
                    'reviewed_at' => $post->reviewed_at,
                    'post_url' => $post->post_url,
                ]),
            ]),
            'blocking' => $this->scheduler->blockingReason(),
            'today' => [
                'used' => $this->scheduler->countToday(),
                'max' => $this->settings->cadence()['max_per_day'],
            ],
            // Công tắc dùng chung với trang Zalo nick nhóm — xem GroupLinksDirectToggle.vue.
            'groupLinksDirectAffiliate' => DirectAffiliateLinkService::enabled(),
            'maxImages' => FacebookPostImages::MAX_PER_POST,
            'templates' => FacebookPostTemplate::latest('updated_at')->latest('id')->get()->map(fn (FacebookPostTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'caption' => $template->caption,
                'images' => $this->images->adminItems($template->images),
            ]),
            'runner' => [
                'version' => $runnerVersion,
                'supports_uploads' => $runnerSupportsUploads,
                // Bot cũ không nhận bài có ảnh tự tải — có bài như vậy đang chờ thì phải báo.
                'uploads_waiting' => ! $runnerSupportsUploads && FacebookGroupPost::where('status', FacebookGroupPost::PENDING)
                    ->whereHas('deal', fn ($query) => $query->whereNotNull('images'))
                    ->exists(),
            ],
        ]);
    }

    /**
     * Tải một ảnh lên ngay lúc admin chọn — trình duyệt đã nén sẵn (FacebookGroupPosts.vue), mỗi
     * request một ảnh cho nhẹ. Trả tên file để form gửi kèm khi xếp bài.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.FacebookPostImages::MAX_KB],
        ], [
            'image.image' => 'File này không phải ảnh.',
            'image.mimes' => 'Chỉ nhận ảnh JPG, PNG hoặc WebP.',
            'image.max' => 'Ảnh quá lớn.',
        ]);

        $this->images->prune();
        $name = $this->images->store($request->file('image'));

        return response()->json(['name' => $name, 'url' => route('admin.fb-posts.image', $name)]);
    }

    public function image(string $name): BinaryFileResponse
    {
        $path = $this->images->path($name);
        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=604800']);
    }

    /**
     * Soạn bài từ link Shopee: lấy mã + thông tin sản phẩm như bot Zalo. Trả JSON vì có thể mất
     * tới ~45 giây (chế độ mã YTB) — trang hiện vòng chờ thay vì đứng hình cả trang.
     */
    public function compose(Request $request, UrlValidationService $urls, FacebookDealLinkBuilder $links, FacebookDealCaption $captions): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']]);
        $url = trim($data['url']);

        try {
            $urls->validateShopeeOnly($url);
            $link = $links->build($url);
        } catch (AffiliateScanException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product = $link->product ? array_intersect_key($link->product, array_flip(self::PRODUCT_KEYS)) : null;

        return response()->json([
            'shopee_url' => $url,
            'canonical_url' => $link->canonicalUrl,
            'source' => $link->source,
            'product' => $product,
            'caption' => $captions->defaultTemplate($product, $link->source !== DirectAffiliateLinkService::SOURCE),
            'link_block' => $link->captionBlock(),
            'fallback_buy_url' => $link->buyUrl,
            'fallback_ytb_url' => $link->ytbActivateUrl,
        ]);
    }

    public function store(Request $request, UrlValidationService $urls): RedirectResponse
    {
        // Không có link Shopee = bài tự soạn: không link mua, nên cũng không được có {link}.
        $custom = ! $request->filled('shopee_url');

        $data = $request->validate([
            'shopee_url' => ['nullable', 'string', 'max:2000'],
            'caption' => ['required', 'string', 'max:5000', function (string $attribute, mixed $value, \Closure $fail) use ($custom) {
                $hasLink = str_contains((string) $value, '{link}');
                if (! $custom && ! $hasLink) {
                    $fail('Nội dung phải có {link} — chỗ đặt link mua.');
                } elseif ($custom && $hasLink) {
                    $fail('Bài tự soạn không có link mua — bỏ {link} ra khỏi nội dung.');
                }
            }],
            'canonical_url' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:20'],
            'product' => ['nullable', 'array'],
            'product.product_name' => ['nullable', 'string', 'max:500'],
            'product.product_image' => ['nullable', 'url', 'max:2000'],
            'product.original_price' => ['nullable', 'numeric'],
            'product.discounted_price' => ['nullable', 'numeric'],
            'product.discount_percent' => ['nullable', 'numeric'],
            // Link dự phòng phải là link của chính site, hoặc link affiliate Shopee mang ID của
            // mình (công tắc "chỉ đổi sang link affiliate") — không cho trang admin chèn link lạ
            // vào bài mà nick cá nhân sẽ đăng.
            'fallback_buy_url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (! str_starts_with((string) $value, url('/go/')) && ! DirectAffiliateLinkService::isOwn((string) $value)) {
                    $fail('Link dự phòng không phải link của mình.');
                }
            }],
            'fallback_ytb_url' => ['nullable', 'string', 'max:500', 'starts_with:'.url('/ytb/')],
            'with_product_image' => ['boolean'],
            'images' => ['nullable', 'array', 'max:'.FacebookPostImages::MAX_PER_POST],
            'images.*' => ['string', 'distinct', $this->images->existsRule()],
            'group_ids' => ['required', 'array', 'min:1', 'max:500'],
            'group_ids.*' => ['integer', Rule::exists('facebook_groups', 'id')->where('enabled', true)],
        ], [
            'group_ids.required' => 'Chọn ít nhất một nhóm.',
            'group_ids.*.exists' => 'Có nhóm đã bị tắt hoặc không còn — tải lại trang rồi chọn lại.',
        ]);

        if (! $custom) {
            try {
                $urls->validateShopeeOnly($data['shopee_url']);
            } catch (AffiliateScanException $e) {
                return back()->withErrors(['shopee_url' => $e->getMessage()]);
            }
        }

        $product = ! $custom && isset($data['product']) ? array_intersect_key($data['product'], array_flip(self::PRODUCT_KEYS)) : null;
        $withProductImage = (bool) ($data['with_product_image'] ?? true);
        $uploads = array_values($data['images'] ?? []);

        $total = count($uploads) + ($withProductImage && ! empty($product['product_image']) ? 1 : 0);
        if ($total > FacebookPostImages::MAX_PER_POST) {
            return back()->withErrors(['images' => 'Mỗi bài tối đa '.FacebookPostImages::MAX_PER_POST.' ảnh, kể cả ảnh sản phẩm.']);
        }

        $groupIds = array_values(array_unique(array_map('intval', $data['group_ids'])));

        // Trang chọn nhóm đã khoá nhóm chưa tới giờ — chặn thêm ở đây phòng trang mở đã lâu.
        $readiness = $this->scheduler->groupReadiness(FacebookGroup::whereKey($groupIds)->get(['id', 'last_attempt_at']));
        if (collect($readiness)->contains('ready', false)) {
            return back()->withErrors(['group_ids' => 'Có nhóm chưa tới giờ đăng, đã có bài đang chờ hoặc chưa page nào vào — tải lại trang rồi chọn lại.']);
        }

        DB::transaction(function () use ($data, $custom, $product, $withProductImage, $uploads, $groupIds, $request) {
            $deal = FacebookGroupDeal::create([
                'shopee_url' => $custom ? null : trim($data['shopee_url']),
                'canonical_url' => $custom ? null : ($data['canonical_url'] ?? null),
                'source' => $custom ? null : ($data['source'] ?? null),
                'product' => $product,
                // null chứ không [] — bot cũ được nhận bài nhờ whereNull('images').
                'images' => $uploads ?: null,
                'with_product_image' => $withProductImage,
                'caption' => $data['caption'],
                'fallback_buy_url' => $custom ? null : ($data['fallback_buy_url'] ?? null),
                'fallback_ytb_url' => $custom ? null : ($data['fallback_ytb_url'] ?? null),
                'created_by' => $request->user()->id,
            ]);

            foreach ($groupIds as $groupId) {
                $deal->posts()->create([
                    'facebook_group_id' => $groupId,
                    'status' => FacebookGroupPost::PENDING,
                    'queued_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Đã xếp '.count($groupIds).' bài vào hàng đợi — bot đăng dần theo nhịp đã đặt.');
    }

    public function cancel(FacebookGroupPost $facebookGroupPost): RedirectResponse
    {
        if ($facebookGroupPost->status !== FacebookGroupPost::PENDING) {
            return back()->withErrors(['post' => 'Chỉ huỷ được bài đang chờ đăng.']);
        }

        $facebookGroupPost->forceFill(['status' => FacebookGroupPost::CANCELLED, 'finished_at' => now()])->save();

        return back()->with('success', 'Đã huỷ bài.');
    }

    public function retry(FacebookGroupPost $facebookGroupPost): RedirectResponse
    {
        if (! in_array($facebookGroupPost->status, FacebookGroupPost::RETRYABLE, true)) {
            return back()->withErrors(['post' => 'Bài này không đăng lại được.']);
        }

        $facebookGroupPost->forceFill([
            'status' => FacebookGroupPost::PENDING,
            'queued_at' => now(),
            // Page nhận lại lúc bot nhận bài — có thể khác page lần trước (vd page đó đang bị chặn).
            'facebook_profile_id' => null,
            'claim_key' => null,
            'claimed_at' => null,
            'finished_at' => null,
            'caption' => null,
            'buy_url' => null,
            'link_kind' => null,
            'short_link_id' => null,
            'error' => null,
        ])->save();

        return back()->with('success', 'Đã đưa bài vào hàng đợi lại.');
    }
}
