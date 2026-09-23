<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckInException;
use App\Services\DailyCheckInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    /**
     * Điểm danh cho hôm nay rồi quay về đúng trang khách đang đứng — thẻ điểm danh nằm trên
     * trang chủ nên back() sẽ dựng lại trang đó với state mới (đã đánh dấu hôm nay, chuỗi +1,
     * kho quà vơi đi một phần).
     *
     * Phần quà bốc được đi theo flash 'checkin' chứ không nằm trong state: state chỉ nói "hôm
     * nay đã nhận bao nhiêu", còn màn lật quà chỉ được chạy đúng một lần ngay sau cú bấm — F5
     * lại mà quà lại bay ra lần nữa thì thành trò đùa.
     */
    public function store(Request $request, DailyCheckInService $checkIn): RedirectResponse
    {
        try {
            $reward = $checkIn->reward($checkIn->claim($request->user()));
        } catch (CheckInException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()
            ->with('checkin', $reward)
            ->with('success', 'Điểm danh thành công — '.number_format($reward['amount'], 0, ',', '.').' đ đã vào ví.');
    }
}
