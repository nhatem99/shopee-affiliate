<?php

namespace App\Jobs;

use App\Models\UserActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tra cứu quốc gia/thành phố từ IP qua ip-api.com (free tier) và cập nhật
 * lại bản ghi UserActivity. Chạy nền qua queue để không làm chậm request
 * gốc của người dùng. Kết quả cache theo IP 24h để hạn chế gọi API lặp lại.
 */
class ResolveActivityLocation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $activityId,
        private readonly string $ip,
    ) {}

    public function handle(): void
    {
        if ($this->isPrivateOrReservedIp($this->ip)) {
            return;
        }

        $location = Cache::remember(
            "geoip:{$this->ip}",
            now()->addDay(),
            fn () => $this->lookup($this->ip),
        );

        if (! $location) {
            return;
        }

        UserActivity::whereKey($this->activityId)->update([
            'country' => $location['country'] ?? null,
            'city' => $location['city'] ?? null,
        ]);
    }

    private function lookup(string $ip): ?array
    {
        try {
            $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,country,city',
            ]);

            if (! $response->ok() || $response->json('status') !== 'success') {
                return null;
            }

            return [
                'country' => $response->json('country'),
                'city' => $response->json('city'),
            ];
        } catch (\Exception $e) {
            Log::warning('ResolveActivityLocation: lookup thất bại', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
