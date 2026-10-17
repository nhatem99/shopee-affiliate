<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = $request->user()->notifications()
            ->paginate(20)
            ->through(fn (DatabaseNotification $n) => self::present($n));

        return Inertia::render('Notifications', [
            'notifications' => $notifications,
        ]);
    }

    /**
     * Bấm vào một thông báo: đánh dấu đã đọc rồi đưa tới trang nó trỏ đến.
     */
    public function read(Request $request, string $id): RedirectResponse|SymfonyResponse
    {
        /** @var DatabaseNotification $notification */
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = $notification->data['url'] ?? route('notifications');

        // Trỏ sang web khác (thông báo chuyển sang dealngon): chuông bấm bằng router.post của
        // Inertia, redirect 302 thường thì XHR đi theo sang tên miền khác và chết vì CORS —
        // Inertia::location bắt trình duyệt tự chuyển trang.
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== $request->getHost()) {
            return Inertia::location($url);
        }

        return redirect($url);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    /**
     * Hình dạng duy nhất mà chuông (HandleInertiaRequests) và trang danh sách cùng dùng.
     *
     * @return array<string, mixed>
     */
    public static function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'url' => $n->data['url'] ?? null,
            'icon' => $n->data['icon'] ?? '🔔',
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at->toDateTimeString(),
            'ago' => $n->created_at->locale('vi')->diffForHumans(),
        ];
    }
}
