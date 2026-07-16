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
            self::Sandbox => 'https://gw.sandbox.gopay.com/gp-gw/api/4.0',
            self::Production => 'https://gate.gopay.com/gp-gw/api/4.0',
        };
    }
}
