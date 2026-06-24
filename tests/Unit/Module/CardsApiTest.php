<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PermanentCardTokenDetails;
use GoPay\Payments\Module\CardsApi;
use PHPUnit\Framework\Attributes\Test;

final class CardsApiTest extends ModuleTestCase
{
    private CardsApi $cards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cards = new CardsApi($this->http);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function cardDetailsJson(array $overrides = []): array
    {
        return array_merge([
            'card_id' => 'card-001',
            'masked_pan' => '411111******1111',
            'expiration_month' => '12',
            'expiration_year' => '27',
            'scheme' => 'VISA',
            'token' => 'tok_xyz',
            'status' => 'ACTIVE',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // getCardDetails
    // -------------------------------------------------------------------------

    #[Test]
    public function getCardDetailsThrowsWhenCardIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('cardId must not be empty');
        $this->cards->getCardDetails('');
    }

    #[Test]
    public function getCardDetailsReturnsPermanentCardTokenDetails(): void
    {
        $this->queueJson($this->cardDetailsJson());

        $result = $this->cards->getCardDetails('card-001');

        $this->assertInstanceOf(PermanentCardTokenDetails::class, $result);
        $this->assertSame('card-001', $result->getCardId());

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/cards/tokens/card-001', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // deleteCard
    // -------------------------------------------------------------------------

    #[Test]
    public function deleteCardThrowsWhenCardIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('cardId must not be empty');
        $this->cards->deleteCard('');
    }

    #[Test]
    public function deleteCardSendsDeleteRequest(): void
    {
        $this->queue204();

        $this->cards->deleteCard('card-001');

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('DELETE', $requests[0]->getMethod());
        $this->assertStringEndsWith('/cards/tokens/card-001', (string) $requests[0]->getUri());
    }

    #[Test]
    public function deleteCardCompletesWithoutThrowingOnSuccess(): void
    {
        $this->queue204();
        $this->cards->deleteCard('card-abc');
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // tokenizeEncryptedCard
    // -------------------------------------------------------------------------

    #[Test]
    public function tokenizeEncryptedCardThrowsWhenPayloadIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('payload must not be empty');
        $this->cards->tokenizeEncryptedCard('');
    }

    #[Test]
    public function tokenizeEncryptedCardReturnsPermanentCardTokenDetails(): void
    {
        $this->queueJson($this->cardDetailsJson(['card_id' => 'card-002']));

        $result = $this->cards->tokenizeEncryptedCard('eyJhbGciOiJSU0EtT0FFUCJ9.encrypted-payload');

        $this->assertInstanceOf(PermanentCardTokenDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/cards/tokens', (string) $requests[0]->getUri());
    }

    #[Test]
    public function tokenizeEncryptedCardSendsPayloadInBody(): void
    {
        $this->queueJson($this->cardDetailsJson());

        $this->cards->tokenizeEncryptedCard('jwe-payload-string');

        $requests = $this->mockClient->getRequests();
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $requests[0]->getBody(), true);
        $this->assertSame('jwe-payload-string', $body['payload']);
    }
}
