<?php

namespace App\Services;

use App\Models\ApiConfig;
use Illuminate\Support\Facades\Cache;

/**
 * Nút "Thử comment" ở /admin/api-config: đăng một comment thật lên bài viết và GIỮ LẠI, trả về
 * đúng URL mà khách thật sẽ nhận, để admin mở trên điện thoại xem có rơi đúng vào bình luận và
 * link trong đó có bấm được không. Xoá bằng nút riêng sau khi xem xong.
 *
 * VÌ SAO PHẢI GIỮ LẠI, KHÔNG XOÁ NGAY: đăng-rồi-xoá chỉ trả lời được "Graph còn cho đăng không".
 * Nhưng câu hỏi đắt hơn nhiều là "khách mở link ra có tới đúng chỗ không" — và câu đó chỉ máy
 * thật mới trả lời được. Đo trên máy thật từng cho kết quả trái ngược với suy đoán: link trong
 * bình luận của REEL hiện thành text thường, bấm không được, dù Graph báo đăng thành công
 * (xem FacebookPostTarget). Nên "đăng được" không đồng nghĩa "dùng được".
 *
 * Comment thử được NHỚ trong cache thay vì trả id về cho trình duyệt rồi nhận lại lúc xoá: nhận
 * id từ client nghĩa là có một đường xoá được BẤT KỲ comment nào trên page, mở từ web. Nhớ ở
 * server thì nút xoá chỉ xoá được đúng cái mình vừa đăng, và còn sống qua lần tải lại trang —
 * admin đóng tab rồi quay lại vẫn thấy "còn một comment thử chưa dọn".
 */
class FacebookCommentProbeService
{
    /** Đủ dài để admin cầm điện thoại thử thong thả, đủ ngắn để không quên mất là còn rác. */
    private const REMEMBER_HOURS = 24;

    /** @return array{ok: bool, message: string, url?: string, post_id?: string} */
    public function post(ApiConfig $config, string $postId): array
    {
        $service = new FacebookPageService($config->app_id, $config->app_secret);

        // Nói rõ trong chính nội dung comment rằng đây là comment kỹ thuật: nó nằm công khai
        // trên page thật, khách hàng thấy được cho tới khi admin bấm xoá.
        $message = implode("\n", [
            '🔧 Kiểm tra kỹ thuật — '.now()->format('H:i d/m/Y'),
            'Bình luận này do quản trị viên đăng để kiểm tra hệ thống và sẽ được xoá.',
        ]);

        $posted = $service->postComment($postId, $message);

        if ($posted === null) {
            return [
                'ok' => false,
                'message' => 'Không đăng được comment lên bài '.$postId.'. Chi tiết ở /admin/logs (tìm "FacebookPageService").',
            ];
        }

        $url = FacebookPostTarget::urlForComment($postId, $posted);

        Cache::put($this->key($config), [
            'full_comment_id' => $posted['full_comment_id'] ?? null,
            'post_id' => $postId,
            'url' => $url,
        ], now()->addHours(self::REMEMBER_HOURS));

        return [
            'ok' => true,
            'message' => 'Đã đăng comment thử và GIỮ LẠI. Mở link bên dưới trên điện thoại (thử cả iPhone lẫn Android), xem có rơi đúng vào bình luận không và link trong đó bấm có ăn không. Xem xong nhớ bấm "Xoá comment thử".',
            'url' => $url,
            'post_id' => $postId,
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function delete(ApiConfig $config): array
    {
        $pending = $this->pending($config);

        if (! $pending || ! ($pending['full_comment_id'] ?? null)) {
            return ['ok' => false, 'message' => 'Không có comment thử nào đang chờ xoá.'];
        }

        $service = new FacebookPageService($config->app_id, $config->app_secret);

        if (! $service->deleteComment($pending['full_comment_id'])) {
            // Không quên cache: còn nhớ thì admin còn bấm xoá lại được, và trang còn hiện cảnh
            // báo. Quên đi là comment nằm lại trên page mà không còn chỗ nào nhắc.
            return [
                'ok' => false,
                'message' => 'Xoá không được, vào page xoá tay: '.($pending['url'] ?? '').' — Graph trả: '.(string) $service->lastError,
            ];
        }

        Cache::forget($this->key($config));

        return ['ok' => true, 'message' => 'Đã xoá comment thử.'];
    }

    /**
     * Comment thử đang nằm trên page, nếu có — để trang admin hiện cảnh báo kể cả sau khi tải
     * lại, và để nút xoá biết mình cần xoá cái gì.
     *
     * @return array{full_comment_id: ?string, post_id: string, url: string}|null
     */
    public function pending(ApiConfig $config): ?array
    {
        return Cache::get($this->key($config));
    }

    private function key(ApiConfig $config): string
    {
        return "fb_probe_comment:{$config->id}";
    }
}
