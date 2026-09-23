<?php

namespace App\Exceptions;

use Exception;

/**
 * Điểm danh không thành — lý do luôn là thứ nói thẳng được với khách (đã điểm danh rồi, chương
 * trình đang tắt), nên message của nó được đem hiện nguyên văn ở CheckInController.
 */
class CheckInException extends Exception
{
    public function __construct(string $message = 'Không điểm danh được lúc này. Vui lòng thử lại.')
    {
        parent::__construct($message);
    }
}
