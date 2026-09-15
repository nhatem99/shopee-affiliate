<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Admin "nhảy vào" tài khoản khách để xem đúng những gì khách đang thấy (số dư, lịch sử,
 * thông báo, lỗi giao diện...) mà không cần hỏi mật khẩu hay đặt lại mật khẩu của họ.
 *
 * Cơ chế: id của admin được giữ trong session (KEY) rồi Auth::login() sang khách. Từ lúc đó
 * mọi middleware/controller đều thấy request->user() là khách — đúng ý, vì mục đích là tái
 * hiện trải nghiệm của khách. Chỉ HandleInertiaRequests đọc KEY để hiện thanh "Thoát" và
 * MaintenanceMode đọc KEY để không chặn admin đang mạo danh lúc bảo trì.
 */
class ImpersonationService
{
    public const KEY = 'impersonator_id';

    public function __construct(private TrackingService $tracking) {}

    public function start(Request $request, User $target): void
    {
        $admin = $request->user();

        // Mạo danh lồng nhau (đang là khách A lại nhảy sang khách B) làm mất dấu admin gốc.
        if ($this->isActive($request)) {
            throw ValidationException::withMessages([
                'impersonate' => 'Thoát tài khoản đang xem trước khi vào tài khoản khác.',
            ]);
        }

        if ($target->id === $admin->id) {
            throw ValidationException::withMessages([
                'impersonate' => 'Bạn đang ở trong tài khoản này rồi.',
            ]);
        }

        // Tài khoản admin khác: không có gì để "xem như khách", và một admin không nên mượn
        // quyền của admin khác để thao tác trong khu quản trị.
        if ($target->isAdmin()) {
            throw ValidationException::withMessages([
                'impersonate' => 'Không thể vào tài khoản quản trị khác.',
            ]);
        }

        // EnsureUserNotBanned sẽ đá phiên ngay ở request kế tiếp — vào rồi cũng bị văng ra.
        if ($target->isBanned()) {
            throw ValidationException::withMessages([
                'impersonate' => 'Mở khoá tài khoản trước khi vào xem.',
            ]);
        }

        $this->tracking->logSecurityEvent('impersonate_start', $request, [
            'metadata' => ['target_user_id' => $target->id, 'target_email' => $target->email],
        ]);

        $request->session()->put(self::KEY, $admin->id);
        // Không remember: cookie "remember me" của admin vẫn còn nguyên, còn phiên khách chỉ
        // sống trong session hiện tại — đóng trình duyệt là hết, không để lại phiên khách lơ lửng.
        Auth::login($target);
    }

    /**
     * Quay về tài khoản admin. Trả về admin, hoặc null nếu phiên không hề mạo danh (hoặc admin
     * gốc đã bị hạ quyền/xoá trong lúc đó — khi ấy đăng xuất hẳn cho an toàn, không trả lại
     * quyền cho một tài khoản không còn là admin).
     */
    public function stop(Request $request): ?User
    {
        $adminId = $request->session()->pull(self::KEY);

        if (! $adminId) {
            return null;
        }

        $admin = User::find($adminId);

        if (! $admin || ! $admin->isAdmin() || $admin->isBanned()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        $this->tracking->logSecurityEvent('impersonate_stop', $request, [
            'metadata' => ['target_user_id' => $request->user()?->id, 'admin_user_id' => $admin->id],
        ]);

        Auth::login($admin);

        return $admin;
    }

    public function isActive(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::KEY);
    }

    public function impersonator(Request $request): ?User
    {
        if (! $this->isActive($request)) {
            return null;
        }

        return User::find($request->session()->get(self::KEY));
    }
}
