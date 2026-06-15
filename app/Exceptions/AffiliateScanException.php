<?php

namespace App\Exceptions;

use Exception;

class AffiliateScanException extends Exception
{
    public function __construct(string $message = 'Không thể quét link này. Vui lòng thử lại.')
    {
        parent::__construct($message);
    }
}
