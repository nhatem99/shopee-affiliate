<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FacebookReelSyncService;
use App\Services\SchedulerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SchedulerController extends Controller
{
    public function __construct(private SchedulerService $scheduler) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Scheduler', [
            'status' => $this->scheduler->status(),
            'tasks' => $this->scheduler->tasks(),
            'runs' => $this->scheduler->recentRuns(),
            'reelSlots' => $this->scheduler->reelSlots(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:100'],
        ]);

        try {
            $run = $this->scheduler->runNow($validated['command'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            $run->succeeded() ? 'success' : 'error',
            $run->succeeded()
                ? "Đã chạy {$run->command} xong."
                : "{$run->command} kết thúc với mã lỗi {$run->exit_code} — xem output bên dưới.",
        );
    }

    /**
     * Nút "Thử ghi" cạnh mỗi reel: ghi lại đúng caption đang có để biết đường đổi caption còn
     * sống hay không. Tách khỏi run() vì run() cố tình chỉ nhận lệnh có trong lịch cron.
     */
    public function probeReelCaption(Request $request, FacebookReelSyncService $reels): RedirectResponse
    {
        $validated = $request->validate([
            'reel_id' => ['required', 'string', 'max:64'],
            // Ghi caption KHÔNG link thì reel bị đổi nội dung thật — xem probeCaptionWrite().
            'with_link' => ['nullable', 'boolean'],
        ]);

        $result = $reels->probeCaptionWrite($validated['reel_id'], $validated['with_link'] ?? true);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            "Reel {$validated['reel_id']}: {$result['message']}",
        );
    }

    /** Trả caption reel về nội dung trước khi bấm thử không-link. */
    public function restoreReelCaption(Request $request, FacebookReelSyncService $reels): RedirectResponse
    {
        $validated = $request->validate([
            'reel_id' => ['required', 'string', 'max:64'],
        ]);

        $result = $reels->restoreCaption($validated['reel_id']);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            "Reel {$validated['reel_id']}: {$result['message']}",
        );
    }
}
