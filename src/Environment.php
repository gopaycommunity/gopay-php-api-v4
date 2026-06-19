<?php

declare(strict_types=1);

namespace GoPay\Payments;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://api.sandbox.gopay.com/api/merchant/payments/4.0',
            self::Production => 'https://api.gopay.com/api/merchant/payments/4.0',
        };
    }
}
