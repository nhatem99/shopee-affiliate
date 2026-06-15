<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiConfig;
use App\Services\AccessTradeService;
use App\Services\ShopeeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/ApiConfig', [
            'configs' => ApiConfig::all()->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'endpoint' => $c->endpoint,
                'app_id' => $c->app_id,
                'is_active' => $c->is_active,
                'platform' => $c->platform,
                'updated_at' => $c->updated_at?->toDateString(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'endpoint' => ['required', 'url'],
            'app_id' => ['nullable', 'string'],
            'app_secret' => ['required', 'string'],
            'is_active' => ['boolean'],
            'platform' => ['required', 'in:shopee,lazada,tiktok,accesstrade'],
        ]);

        ApiConfig::updateOrCreate(
            ['platform' => $validated['platform']],
            $validated
        );

        return back()->with('success', 'Cấu hình API đã được lưu.');
    }

    public function test(ApiConfig $config): JsonResponse
    {
        try {
            $ok = match ($config->platform) {
                'shopee' => app(ShopeeApiService::class)->testConnection($config),
                'accesstrade' => app(AccessTradeService::class)->testConnection($config),
                default => throw new \Exception('Platform không được hỗ trợ.'),
            };

            return response()->json([
                'ok' => $ok,
                'message' => $ok ? 'Kết nối thành công!' : 'Kết nối thất bại.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
