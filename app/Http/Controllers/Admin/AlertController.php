<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAlertService;
use Illuminate\Http\JsonResponse;

class AlertController extends Controller
{
    /**
     * Chỉ mấy con số cho chuông và badge — xem lý do dùng JSON thay vì partial reload ở routes/web.php.
     */
    public function index(AdminAlertService $alerts): JsonResponse
    {
        return response()->json($alerts->counts());
    }
}
