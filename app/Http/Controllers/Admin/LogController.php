<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LogViewerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    /** Mức lọc cho phép chọn ở giao diện — 'ALL' nghĩa là không lọc theo level. */
    private const LEVELS = ['ALL', 'DEBUG', 'INFO', 'WARNING', 'ERROR'];

    private const DEFAULT_LEVEL = 'WARNING';

    public function __construct(private LogViewerService $logs) {}

    public function index(Request $request): Response
    {
        $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $files = $this->logs->files();

        // Tham số đến từ query string (link chia sẻ, bookmark cũ) nên chuẩn hóa về giá trị hợp lệ
        // thay vì trả 422 — file không tồn tại thì rơi về file log mới nhất.
        $file = $request->input('file');
        $file = $file !== null && $this->logs->resolve($file) !== null
            ? basename($file)
            : ($files[0]['name'] ?? null);

        $level = strtoupper((string) $request->input('level', self::DEFAULT_LEVEL));
        $level = in_array($level, self::LEVELS, true) ? $level : self::DEFAULT_LEVEL;

        $search = $request->input('q');

        $result = $file === null
            ? ['entries' => [], 'counts' => [], 'truncated' => false, 'total' => 0]
            : $this->logs->read($file, $level === 'ALL' ? null : $level, $search);

        return Inertia::render('Admin/Logs', [
            'files' => $files,
            'levels' => self::LEVELS,
            'filters' => [
                'file' => $file,
                'level' => $level,
                'q' => $search,
            ],
            'entries' => $result['entries'],
            'counts' => $result['counts'],
            'truncated' => $result['truncated'],
            'total' => $result['total'],
        ]);
    }
}
