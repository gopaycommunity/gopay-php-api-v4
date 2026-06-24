<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;

trait ValidatesInput
{
    private function requireNonEmpty(string $value, string $paramName): string
    {
        if (trim($value) === '') {
            throw new GoPaySdkException("[GoPaySDK] {$paramName} must not be empty.", ErrorCode::InvalidArgument);
        }

        return $value;
    }
}
