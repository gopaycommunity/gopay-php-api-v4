<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit;

use GoPay\Payments\Generated\Model\PaymentState;
use GoPay\Payments\PaymentPoller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentPollerTest extends TestCase
{
    /** @return array<string, array{string, bool, bool, bool, bool}> */
    public static function stateProvider(): array
    {
        return [
            // state                          isPending isTerminal isSuccessful isFailed
            'CREATED'            => [PaymentState::CREATED,             true,  false, false, false],
            'PAYMENT_METHOD_CHOSEN' => [PaymentState::PAYMENT_METHOD_CHOSEN, true, false, false, false],
            'PAID'               => [PaymentState::PAID,                false, true,  true,  false],
            'AUTHORIZED'         => [PaymentState::AUTHORIZED,          false, true,  true,  false],
            'CANCELED'           => [PaymentState::CANCELED,            false, true,  false, true],
            'TIMEOUTED'          => [PaymentState::TIMEOUTED,           false, true,  false, true],
            'REFUNDED'           => [PaymentState::REFUNDED,            false, true,  false, false],
            'PARTIALLY_REFUNDED' => [PaymentState::PARTIALLY_REFUNDED,  false, true,  false, false],
        ];
    }

    #[DataProvider('stateProvider')]
    public function testStateBucketing(
        string $state,
        bool $isPending,
        bool $isTerminal,
        bool $isSuccessful,
        bool $isFailed,
    ): void {
        $this->assertSame($isPending, PaymentPoller::isPending($state), "isPending($state)");
        $this->assertSame($isTerminal, PaymentPoller::isTerminal($state), "isTerminal($state)");
        $this->assertSame($isSuccessful, PaymentPoller::isSuccessful($state), "isSuccessful($state)");
        $this->assertSame($isFailed, PaymentPoller::isFailed($state), "isFailed($state)");
    }
}
