<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AffiliateScanException;
use App\Http\Controllers\Controller;
use App\Jobs\PrepareFacebookVideo;
use App\Jobs\PublishFacebookVideo;
use App\Models\ApiConfig;
use App\Models\FacebookVideo;
use App\Services\DirectAffiliateLinkService;
use App\Services\FacebookDealCaption;
use App\Services\FacebookDealLinkBuilder;
use App\Services\FacebookGroupRunnerSettings;
use App\Services\FacebookVideoEditor;
use App\Services\FacebookVideoQueue;
use App\Services\UrlValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /admin/fb-videos — dán link Shopee, bot điện thoại lấy video sản phẩm, server cắt + dựng khung
 * 9:16 có chữ, admin xem lại rồi bấm đăng Reel lên fanpage. Bot không lấy được thì tải video lên tay.
 */
class FacebookVideoController extends Controller
{
    private const PRODUCT_KEYS = ['product_name', 'product_image', 'original_price', 'discounted_price', 'discount_percent'];

    /** File tải tay tối đa (KB) — nginx/PHP trên VPS cũng phải cho request lớn chừng này. */
    public const MAX_UPLOAD_KB = 200 * 1024;

    public function index(FacebookVideoQueue $queue, FacebookGroupRunnerSettings $settings, FacebookVideoEditor $editor): Response
    {
        $queue->failSilentClaims();
        $runner = $settings->runnerStatus();

        return Inertia::render('Admin/FacebookVideos', [
            'videos' => FacebookVideo::latest('id')->limit(30)->get()->map(fn (FacebookVideo $video) => [
                'id' => $video->id,
                'status' => $video->status,
                'shopee_url' => $video->shopee_url,
                'product' => $video->product,
                'buy_url' => $video->buy_url,
                'caption' => $video->caption,
                'trim_start' => $video->trim_start,
                'trim_end' => $video->trim_end,
                'overlay_top' => $video->overlay_top,
                'overlay_bottom' => $video->overlay_bottom,
                'source_duration' => $video->source_duration,
                'output_duration' => $video->output_duration,
                'has_source' => $video->has_source,
                // Đổi số sau ?v= mỗi lần dựng lại để trình duyệt không chiếu bản cũ đã lưu đệm.
                'output_url' => $video->has_output ? "/admin/fb-videos/{$video->id}/file/output?v={$video->updated_at?->timestamp}" : null,
                'permalink' => $video->permalink,
                'error' => $video->error,
                'created_at' => $video->created_at,
                'posted_at' => $video->posted_at,
            ]),
            'runner' => [
                'version' => $runner['version'],
                'supported' => FacebookVideoQueue::runnerSupports($runner['version']),
                'min_version' => FacebookVideoQueue::MIN_VERSION,
                'last_seen_at' => $runner['last_seen_at'],
            ],
            // Closure: lượt tự tải lại (only: videos, runner) không chạy ffmpeg kiểm tra mỗi 5 giây.
            'editorProblem' => fn () => $editor->problem(),
            'facebookReady' => ApiConfig::where('platform', 'facebook')->where('is_active', true)->whereNotNull('app_secret')->exists(),
            'maxUploadMb' => intdiv(self::MAX_UPLOAD_KB, 1024),
        ]);
    }

    public function store(Request $request, UrlValidationService $urls, FacebookDealLinkBuilder $links, FacebookDealCaption $captions): RedirectResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']]);
        $url = trim($data['url']);

        try {
            $urls->validateShopeeOnly($url);
            $link = $links->build($url);
        } catch (AffiliateScanException $e) {
            return back()->withErrors(['url' => $e->getMessage()]);
        }

        $product = $link->product ? array_intersect_key($link->product, array_flip(self::PRODUCT_KEYS)) : null;
        $overlay = $captions->videoOverlay($product);
        $withVoucher = $link->source !== DirectAffiliateLinkService::SOURCE;

        FacebookVideo::create([
            // Link sản phẩm đầy đủ cho bot mở — link rút gọn s.shopee.vn phải đi qua thêm redirect.
            'shopee_url' => $link->canonicalUrl ?: $url,
            'status' => FacebookVideo::WAITING_BOT,
            'product' => $product,
            'short_link_id' => $link->shortLinkId,
            'buy_url' => $link->buyUrl,
            'caption' => $captions->render($captions->reelTemplate($product, $withVoucher), $link->captionBlock()),
            'overlay_top' => $overlay['top'],
            'overlay_bottom' => $overlay['bottom'],
        ]);

        return back()->with('success', 'Đã nhận link — bot trên điện thoại sẽ mở Shopee lấy video trong vài phút.');
    }

    /** Bot không lấy được video: admin tự tải video về rồi đưa lên đây. */
    public function upload(Request $request, FacebookVideo $facebookVideo): RedirectResponse
    {
        abort_if($facebookVideo->isBusy() && $facebookVideo->status !== FacebookVideo::WAITING_BOT, 409, 'Video đang được xử lý.');

        $request->validate([
            'video' => ['required', 'file', 'mimetypes:video/mp4,application/mp4,video/quicktime,video/x-m4v,video/webm', 'max:'.self::MAX_UPLOAD_KB],
        ], [
            'video.mimetypes' => 'Chỉ nhận video MP4, MOV hoặc WebM.',
            'video.max' => 'Video lớn hơn '.intdiv(self::MAX_UPLOAD_KB, 1024).' MB.',
        ]);

        $path = $facebookVideo->sourcePath();
        File::ensureDirectoryExists(dirname($path));
        $request->file('video')->move(dirname($path), basename($path));

        $facebookVideo->update([
            'status' => FacebookVideo::PROCESSING,
            'has_source' => true,
            'source_url' => null,
            'error' => null,
        ]);
        PrepareFacebookVideo::dispatch($facebookVideo->id);

        return back()->with('success', 'Đã tải lên — đang dựng video.');
    }

    /** Sửa caption / chữ / số giây cắt. Đổi chữ hoặc số giây cắt thì dựng lại video. */
    public function update(Request $request, FacebookVideo $facebookVideo): RedirectResponse
    {
        abort_if($facebookVideo->isBusy() || $facebookVideo->status === FacebookVideo::POSTED, 409, 'Video đang được xử lý hoặc đã đăng.');

        $data = $request->validate([
            'caption' => ['required', 'string', 'max:2200'],
            'overlay_top' => ['nullable', 'string', 'max:200'],
            'overlay_bottom' => ['nullable', 'string', 'max:200'],
            'trim_start' => ['required', 'numeric', 'min:0', 'max:10'],
            'trim_end' => ['required', 'numeric', 'min:0', 'max:10'],
        ]);

        $facebookVideo->fill($data);
        $rebuild = $facebookVideo->has_source && $facebookVideo->isDirty(['overlay_top', 'overlay_bottom', 'trim_start', 'trim_end']);
        if ($rebuild) {
            $facebookVideo->status = FacebookVideo::PROCESSING;
            $facebookVideo->error = null;
        }
        $facebookVideo->save();

        if ($rebuild) {
            PrepareFacebookVideo::dispatch($facebookVideo->id);

            return back()->with('success', 'Đã lưu — đang dựng lại video.');
        }

        return back()->with('success', 'Đã lưu.');
    }

    public function publish(FacebookVideo $facebookVideo): RedirectResponse
    {
        abort_unless($facebookVideo->status === FacebookVideo::READY && $facebookVideo->has_output, 409, 'Video chưa sẵn sàng để đăng.');

        $facebookVideo->update(['status' => FacebookVideo::PUBLISHING, 'error' => null]);
        PublishFacebookVideo::dispatch($facebookVideo->id);

        return back()->with('success', 'Đang đăng Reel lên page...');
    }

    /** Nhờ bot lấy lại video (bot hỏng, hoặc admin đã đăng nhập/xác minh Shopee trên điện thoại). */
    public function refetch(FacebookVideo $facebookVideo): RedirectResponse
    {
        abort_unless(in_array($facebookVideo->status, [FacebookVideo::FAILED, FacebookVideo::NO_VIDEO], true), 409, 'Video không ở trạng thái lấy lại được.');

        $facebookVideo->update(['status' => FacebookVideo::WAITING_BOT, 'error' => null, 'claimed_at' => null]);

        return back()->with('success', 'Đã giao lại cho bot.');
    }

    public function destroy(FacebookVideo $facebookVideo): RedirectResponse
    {
        abort_if(in_array($facebookVideo->status, [FacebookVideo::PROCESSING, FacebookVideo::PUBLISHING], true), 409, 'Video đang được xử lý — đợi xong rồi xoá.');

        $facebookVideo->delete();

        return back()->with('success', 'Đã xoá.');
    }

    /** Xem lại video đã dựng ngay trên trang admin (BinaryFileResponse hỗ trợ tua — header Range). */
    public function file(FacebookVideo $facebookVideo, string $kind): BinaryFileResponse
    {
        $path = $kind === 'source' ? $facebookVideo->sourcePath() : $facebookVideo->outputPath();
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, max-age=86400']);
    }
}
