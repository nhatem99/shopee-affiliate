<?php

namespace App\Http\Controllers;

use App\Services\FlashSaleService;
use App\Services\TrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trang Flash Sale (/flashsale) — tổng hợp sản phẩm đang giảm sâu theo khung giờ trong
 * ngày, khách không cần dán link. Cùng cấu trúc với VoucherCatalogController: trang đầu
 * trả kèm luôn sản phẩm (không đợi thêm một lượt XHR mới có gì hiện), từ trang 2 mới gọi
 * `feed` khi khách cuộn.
 */
class FlashSaleController extends Controller
{
    public function __construct(private FlashSaleService $flashSale) {}

    public function index(Request $request, TrackingService $tracking): Response
    {
        $tracking->log('page_view', $request, ['url' => $request->fullUrl()]);

        $filters = $this->filters($request);

        return Inertia::render('FlashSale', [
            'initial' => $this->flashSale->list(
                $filters['slot'],
                $filters['keyword'],
                1,
                FlashSaleService::PAGE_SIZE,
                $request->user(),
            ),
            'filters' => $filters,
            'slots' => $this->flashSale->activeSlots(),
            'lastSyncedAt' => $this->flashSale->lastSyncedAt(),
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->flashSale->list(
            $filters['slot'],
            $filters['keyword'],
            (int) $request->integer('page', 1),
            (int) $request->integer('page_size', FlashSaleService::PAGE_SIZE),
            $request->user(),
        ));
    }

    /**
     * Khung giờ chỉ nhận đúng định dạng "HH:MM" hoặc 'all' — đi thẳng vào câu WHERE, để
     * ngỏ thì mỗi URL lạ thành một truy vấn riêng, mở đường đúc URL rác trỏ vào trang.
     *
     * @return array{slot: string, keyword: string}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'slot' => ['nullable', 'string', Rule::in([...$this->flashSale->activeSlots(), 'all'])],
            'keyword' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'slot' => $validated['slot'] ?? 'all',
            'keyword' => trim($validated['keyword'] ?? ''),
        ];
    }
}
