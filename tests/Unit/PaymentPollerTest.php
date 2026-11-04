<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit;

use GoPay\Payments\Generated\Model\PaymentState;
use GoPay\Payments\PaymentPoller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentPollerTest extends TestCase
{
    /** @return array<string, array{string, bool, bool, bool, bool, bool}> */
    public static function stateProvider(): array
    {
        return [
            // state                                                isCreated isPending isTerminal isSuccessful isFailed
            'CREATED'               => [PaymentState::CREATED,               true,  false, false, false, false],
            'PAYMENT_METHOD_CHOSEN' => [PaymentState::PAYMENT_METHOD_CHOSEN, false, true,  false, false, false],
            'PAID'                  => [PaymentState::PAID,                  false, false, true,  true,  false],
            'AUTHORIZED'            => [PaymentState::AUTHORIZED,            false, false, true,  true,  false],
            'CANCELED'              => [PaymentState::CANCELED,              false, false, true,  false, true],
            'TIMEOUTED'             => [PaymentState::TIMEOUTED,             false, false, true,  false, true],
            'REFUNDED'              => [PaymentState::REFUNDED,              false, false, true,  false, false],
            'PARTIALLY_REFUNDED'    => [PaymentState::PARTIALLY_REFUNDED,    false, false, true,  false, false],
            // A state the API adds later: in no group, so a loop on isPending() stops and a
            // loop on !isTerminal() keeps waiting rather than reporting an outcome.
            'unknown'               => ['ADDED_LATER',                       false, false, false, false, false],
        ];
    }

    #[DataProvider('stateProvider')]
    public function testStateBucketing(
        string $state,
        bool $isCreated,
        bool $isPending,
        bool $isTerminal,
        bool $isSuccessful,
        bool $isFailed,
    ): void {
        $this->assertSame($isCreated, PaymentPoller::isCreated($state), "isCreated($state)");
        $this->assertSame($isPending, PaymentPoller::isPending($state), "isPending($state)");
        $this->assertSame($isTerminal, PaymentPoller::isTerminal($state), "isTerminal($state)");
        $this->assertSame($isSuccessful, PaymentPoller::isSuccessful($state), "isSuccessful($state)");
        $this->assertSame($isFailed, PaymentPoller::isFailed($state), "isFailed($state)");
    }

    /**
     * Each state the model lists is in exactly one group, so a new one fails here until it is
     * placed deliberately.
     */
    #[Test]
    public function every_known_state_is_in_exactly_one_group(): void
    {
        foreach (PaymentState::getAllowableEnumValues() as $state) {
            $groups = array_filter([
                PaymentPoller::isCreated($state),
                PaymentPoller::isPending($state),
                PaymentPoller::isTerminal($state),
            ]);
            $this->assertCount(1, $groups, "PaymentState {$state} is new: decide which PaymentPoller group it belongs to.");
        }
    }
}
