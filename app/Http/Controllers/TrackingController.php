<?php

namespace App\Http\Controllers;

use App\Services\TrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrackingController extends Controller
{
    /** Sự kiện được phép ghi nhận từ frontend — tránh nhận log tuỳ ý từ client. */
    private const ALLOWED_EVENTS = ['voucher_copy'];

    public function store(Request $request, TrackingService $tracking): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', Rule::in(self::ALLOWED_EVENTS)],
            'voucher_code' => ['nullable', 'string', 'max:50'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'max:30'],
        ]);

        $tracking->log($validated['event_type'], $request, $validated);

        return response()->json(['ok' => true]);
    }
}
