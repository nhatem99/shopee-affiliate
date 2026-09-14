<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
}
