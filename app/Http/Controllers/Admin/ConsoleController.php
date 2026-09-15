<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ArtisanConsoleService;
use App\Services\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ConsoleController extends Controller
{
    public function __construct(
        private ArtisanConsoleService $console,
        private TrackingService $tracking,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Console', [
            'commands' => $this->console->allowed(),
            'runs' => $this->console->recentRuns(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:500'],
        ]);

        // Ghi vào log bảo mật dù được phép hay không: đây là thao tác mạnh nhất trong admin,
        // ai chạy gì lúc nào phải tra lại được ở /admin/activities.
        $this->tracking->logSecurityEvent('artisan_run', $request, [
            'metadata' => ['command' => $validated['command']],
        ]);

        try {
            $run = $this->console->run($validated['command'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['command' => $e->getMessage()]);
        }

        return back()->with(
            $run->succeeded() ? 'success' : 'error',
            $run->succeeded()
                ? "Đã chạy xong: {$run->command}"
                : "{$run->command} kết thúc với mã lỗi {$run->exit_code} — xem output bên dưới.",
        );
    }
}
