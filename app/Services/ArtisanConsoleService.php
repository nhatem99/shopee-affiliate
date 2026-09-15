<?php

namespace App\Services;

use App\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;

/**
 * Cho admin dán một lệnh artisan vào /admin/console và chạy ngay trên web, khỏi SSH lên VPS
 * mỗi khi có lệnh mới (gửi bù thông báo, xoá cache sau deploy, migrate...).
 *
 * KHÔNG phải shell: chỉ nhận lệnh artisan, và chỉ những lệnh trong allowed(). Ai chiếm được
 * tài khoản admin cũng không chạy được `tinker` (= thực thi PHP tuỳ ý), `migrate:fresh` /
 * `db:wipe` (xoá sạch dữ liệu) hay `down` (khoá luôn web). Lệnh của app (namespace
 * App\Console\Commands) được cho phép tự động vì chính là thứ trang này sinh ra để chạy.
 */
class ArtisanConsoleService
{
    /**
     * Lệnh khung Laravel được phép, kèm mô tả hiện trên trang. Chọn theo tiêu chí "cần sau mỗi
     * lần deploy hoặc để chẩn đoán", không có lệnh nào xoá dữ liệu hay đổi được code.
     */
    private const FRAMEWORK = [
        'optimize:clear' => 'Xoá toàn bộ cache (config, route, view, app) — chạy sau mỗi lần deploy',
        'cache:clear' => 'Xoá cache ứng dụng',
        'config:clear' => 'Xoá cache config',
        'config:cache' => 'Tạo cache config',
        'route:clear' => 'Xoá cache route',
        'route:cache' => 'Tạo cache route',
        'view:clear' => 'Xoá view đã biên dịch',
        'view:cache' => 'Biên dịch sẵn view',
        'migrate' => 'Chạy migration mới (tự thêm --force)',
        'migrate:status' => 'Xem migration nào đã chạy',
        'queue:restart' => 'Khởi động lại worker queue sau khi deploy',
        'schedule:run' => 'Chạy các job tới hạn trong lịch',
        'schedule:list' => 'Liệt kê lịch chạy',
        'storage:link' => 'Tạo symlink public/storage',
        'about' => 'Thông tin môi trường (PHP, Laravel, driver...)',
        'route:list' => 'Liệt kê route',
    ];

    /**
     * Danh sách lệnh được phép, để hiện gợi ý trên trang.
     *
     * @return list<array{name: string, description: string, usage: string, app: bool}>
     */
    public function allowed(): array
    {
        $list = [];

        foreach (Artisan::all() as $name => $command) {
            $app = $this->isAppCommand($command);

            if (! $app && ! isset(self::FRAMEWORK[$name])) {
                continue;
            }

            $list[] = [
                'name' => $name,
                'description' => $app ? $command->getDescription() : self::FRAMEWORK[$name],
                'usage' => $app ? trim($name.' '.$command->getSynopsis(true)) : $name,
                'app' => $app,
            ];
        }

        usort($list, fn ($a, $b) => [$b['app'], $a['name']] <=> [$a['app'], $b['name']]);

        return $list;
    }

    /**
     * Chạy một dòng lệnh admin dán vào. Nhận cả "php artisan x", "artisan x" hay "x".
     */
    public function run(string $line, int $adminId): ScheduledTaskRun
    {
        $line = $this->normalize($line);
        $name = strtok($line, " \t");

        if ($name === false || $name === '') {
            throw new InvalidArgumentException('Chưa nhập lệnh.');
        }

        $commands = Artisan::all();

        if (! isset($commands[$name])) {
            throw new InvalidArgumentException("Không có lệnh artisan nào tên {$name}.");
        }

        if (! $this->isAppCommand($commands[$name]) && ! isset(self::FRAMEWORK[$name])) {
            throw new InvalidArgumentException("Lệnh {$name} không được phép chạy từ web. Chỉ chạy được các lệnh trong danh sách bên dưới.");
        }

        // migrate hỏi xác nhận khi APP_ENV=production; web không có ai trả lời → treo rồi lỗi.
        if ($name === 'migrate' && ! str_contains($line, '--force')) {
            $line .= ' --force';
        }

        $run = ScheduledTaskRun::create([
            'command' => mb_substr($line, 0, 191),
            'started_at' => now(),
            'triggered_by' => "admin:{$adminId}",
        ]);

        // Web request mặc định chết sau 30s (max_execution_time); lệnh gửi bù hàng trăm thông
        // báo hay migrate có thể lâu hơn.
        set_time_limit(300);

        try {
            $exitCode = Artisan::call($line);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            $exitCode = 1;
            $output = get_class($e).': '.$e->getMessage();
        }

        $run->update([
            'finished_at' => now(),
            'exit_code' => $exitCode,
            'output' => ScheduledTaskRun::trimOutput($output),
        ]);

        return $run;
    }

    /**
     * Lịch sử các lệnh admin chạy tay (cả từ trang này lẫn nút "Chạy ngay" ở Lịch chạy).
     *
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit = 30): array
    {
        return ScheduledTaskRun::where('triggered_by', 'like', 'admin:%')
            ->latest('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (ScheduledTaskRun $run) => [
                'id' => $run->id,
                'command' => $run->command,
                'started_at' => $run->started_at->toDateTimeString(),
                'started_human' => $run->started_at->locale('vi')->diffForHumans(),
                'duration_ms' => $run->finished_at ? (int) $run->started_at->diffInMilliseconds($run->finished_at) : null,
                'exit_code' => $run->exit_code,
                'status' => $run->isRunning() ? 'running' : ($run->succeeded() ? 'ok' : 'failed'),
                'output' => $run->output,
                'triggered_by' => $run->triggered_by,
            ])
            ->all();
    }

    private function normalize(string $line): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        // Bỏ tiền tố người ta hay copy nguyên từ hướng dẫn: "$ php artisan x", "php artisan x", "artisan x".
        return trim(preg_replace('/^\$?\s*(php\s+)?artisan\s+/i', '', $line) ?? '');
    }

    private function isAppCommand(Command $command): bool
    {
        return str_starts_with($command::class, 'App\\Console\\Commands\\');
    }
}
