<?php

namespace App\Console\Commands;

use App\Jobs\ReplyZaloGroupLink;
use App\Services\ZaloGroupLinkReplyService;
use App\Services\ZaloPersonalBridge;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Nghe tin Zalo của nick cá nhân qua luồng SSE của cầu nối hermes-zalo-plugin; tin nào có link
 * Shopee thì xếp một ReplyZaloGroupLink. Chạy liên tục (supervisor giữ trên VPS), tự nối lại
 * khi cầu nối khởi động lại hoặc mạng chập chờn.
 *
 * Cầu nối KHÔNG lọc gì cả — mọi tin nick thấy đều tới đây (chế độ mention/all và danh sách
 * nhóm của nó nằm ở adapter Hermes, mình không dùng). Lọc nhóm, chống trùng, giới hạn tốc độ
 * đều làm ở đây.
 */
class ZaloGroupListen extends Command
{
    protected $signature = 'zalo:group-listen
        {--once : Dừng khi luồng đóng thay vì nối lại (dùng cho test)}';

    protected $description = 'Nghe tin nhóm Zalo qua nick cá nhân, khách dán link Shopee thì trả link có mã';

    // Nhớ id sự kiện cuối qua các lần khởi động lại: nối lại với Last-Event-ID thì cầu nối phát
    // lại tối đa 200 tin bị lỡ; tin đã xử lý bị chặn ở bước chống trùng theo messageId.
    private const LAST_EVENT_KEY = 'zalo_personal:last_event_id';

    /** @var array<string, true> nhóm đã báo "chưa trong danh sách" — mỗi nhóm báo một lần */
    private array $reportedGroups = [];

    /** consume() đã mở được luồng SSE trong lượt nối này chưa */
    private bool $connected = false;

    public function handle(ZaloPersonalBridge $bridge, ZaloGroupLinkReplyService $replies): int
    {
        // Chỉ báo, KHÔNG thoát: cầu nối lên chậm sau khi reboot hay nick đang chờ admin quét QR ở
        // /admin/zalo-nick đều là chuyện bình thường — vòng dưới tự nối lại, đăng nhập xong là
        // tin tự về. Thoát ở đây thì supervisor khởi động lại vài lần rồi bỏ cuộc hẳn.
        try {
            $health = $bridge->health();
            if (empty($health['loggedIn']) || ! empty($health['sessionDead'])) {
                $this->warn('Cầu nối chưa đăng nhập Zalo — quét QR ở /admin/zalo-nick. Vẫn chờ tin.');
            } else {
                $this->info('Nick Zalo đang đăng nhập: '.($health['ownId'] ?? '?').'.');
            }
        } catch (Throwable $e) {
            $this->warn('Chưa gọi được cầu nối '.config('services.zalo_personal.bridge_url').': '.$e->getMessage());
        }

        $groups = config('services.zalo_personal.group_ids');
        $this->info('Nhóm được trả lời: '.($groups ? implode(', ', $groups) : 'mọi nhóm').'.');

        // Số lần liền nhau KHÔNG nối được. Chờ giãn dần 6, 12, 24, 48 rồi 60 giây: cầu nối tắt
        // cả buổi thì log của supervisor không bị ngập mỗi 3 giây một dòng.
        $failures = 0;
        while (true) {
            $this->connected = false;
            try {
                $this->consume($bridge, $replies, $failures);
            } catch (Throwable $e) {
                $this->warn('Mất kết nối cầu nối: '.$e->getMessage());
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            $failures = $this->connected ? 0 : $failures + 1;
            sleep(min(60, 3 * 2 ** min($failures, 5)));
        }
    }

    private function consume(ZaloPersonalBridge $bridge, ZaloGroupLinkReplyService $replies, int $failures): void
    {
        $body = $bridge->openEvents((int) Cache::get(self::LAST_EVENT_KEY, 0))->toPsrResponse()->getBody();
        $this->connected = true;
        if ($failures > 0) {
            $this->info('Đã nối lại cầu nối.');
        }
        $frame = [];

        // Đọc TỪNG DÒNG (Utils::readLine đọc 1 byte/lần), không đọc khúc lớn: cầu nối trả
        // chunked nên PHP gắn bộ lọc dechunk vào stream, và fread(8192) qua bộ lọc thì chờ đủ
        // 8192 byte mới trả về — một tin ~2 KB cộng ping 8 byte/15 giây là treo mãi không ra.
        // Quá read_timeout mà không có byte nào thì Guzzle ném "Unable to read from stream",
        // vòng ngoài bắt và nối lại.
        while (! $body->eof()) {
            $line = rtrim(Utils::readLine($body), "\r\n");

            if ($line !== '') {
                $frame[] = $line;
            } elseif ($frame) {
                $this->handleFrame($frame, $replies);
                $frame = [];
            }
        }
    }

    /**
     * @param  list<string>  $lines  các dòng của một sự kiện SSE (đã bỏ dòng trống phân cách)
     */
    private function handleFrame(array $lines, ZaloGroupLinkReplyService $replies): void
    {
        $id = null;
        $event = 'message';
        $data = [];

        foreach ($lines as $line) {
            // Dòng bắt đầu bằng ":" là ping giữ kết nối.
            if (str_starts_with($line, ':')) {
                continue;
            }
            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

            match ($field) {
                'id' => $id = $value,
                'event' => $event = $value,
                'data' => $data[] = $value,
                default => null,
            };
        }

        if ($id !== null) {
            Cache::forever(self::LAST_EVENT_KEY, (int) $id);
        }

        $payload = $data ? json_decode(implode("\n", $data), true) : null;
        if (! is_array($payload)) {
            return;
        }

        if ($event === 'message') {
            $this->onMessage($payload, $replies);
        } elseif ($event === 'session_dead') {
            // Zalo đá phiên (đăng nhập nơi khác, đổi mật khẩu...) — phải quét QR lại.
            $this->error('Phiên Zalo đã chết, cần quét QR lại: '.json_encode($payload, JSON_UNESCAPED_UNICODE));
            Log::error('Zalo nick cá nhân: phiên đã chết, cần quét QR lại', $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $message  một tin đã chuẩn hoá của cầu nối (zaloClient.js::_normaliseMessage)
     */
    private function onMessage(array $message, ZaloGroupLinkReplyService $replies): void
    {
        $threadId = (string) ($message['threadId'] ?? '');
        $threadType = ($message['threadType'] ?? '') === 'group' ? 'group' : 'user';
        $messageId = (string) ($message['messageId'] ?? '');

        if (! empty($message['isSelf']) || $threadId === '' || $messageId === '') {
            return;
        }

        $url = $replies->shopeeUrlIn($message);
        if ($url === null) {
            return;
        }

        $groups = config('services.zalo_personal.group_ids');
        if ($threadType === 'group' && $groups && ! in_array($threadId, $groups, true)) {
            if (! isset($this->reportedGroups[$threadId])) {
                $this->reportedGroups[$threadId] = true;
                $this->line("Bỏ qua nhóm {$threadId} — chưa có trong ZALO_PERSONAL_GROUP_IDS.");
            }

            return;
        }

        // Cầu nối phát lại tin khi nối lại — mỗi tin chỉ trả lời một lần.
        if (! Cache::add("zalo_personal:msg:{$messageId}", true, now()->addDay())) {
            return;
        }

        $sender = $message['senderName'] ?? $message['senderId'] ?? '?';
        $queued = RateLimiter::attempt(
            "zalo_personal_reply:{$threadId}",
            (int) config('services.zalo_personal.replies_per_10_minutes'),
            function () use ($threadId, $threadType, $url, $message) {
                ReplyZaloGroupLink::dispatch($threadId, $threadType, $url, $message['quote'] ?? null);
            },
            600,
        );

        if ($queued) {
            $this->line("• [{$threadType} {$threadId}] {$sender}: {$url} → đang lấy mã");
        } else {
            $this->warn("• [{$threadType} {$threadId}] {$sender}: bỏ qua, nhóm đã đủ lượt trả lời trong 10 phút");
        }
    }
}
