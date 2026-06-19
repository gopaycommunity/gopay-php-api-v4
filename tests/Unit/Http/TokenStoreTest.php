<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Http;

use GoPay\Payments\Http\TokenStore;
use PHPUnit\Framework\TestCase;

final class TokenStoreTest extends TestCase
{
    public function testIsEmptyByDefault(): void
    {
        $store = new TokenStore();
        $this->assertFalse($store->hasAccessToken());
        $this->assertNull($store->getAccessToken());
        $this->assertNull($store->getClientId());
        $this->assertNull($store->getClientSecret());
        $this->assertNull($store->getScope());
        $this->assertFalse($store->hasClientCredentials());
    }

    public function testSetTokenMakesItAvailable(): void
    {
        $store = new TokenStore();
        $store->setToken('tok_abc', 3600);
        $this->assertTrue($store->hasAccessToken());
        $this->assertSame('tok_abc', $store->getAccessToken());
    }

    public function testSetClientCredentialsClearsExistingToken(): void
    {
        $store = new TokenStore();
        $store->setToken('tok_abc', 3600);
        $store->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->assertFalse($store->hasAccessToken());
        $this->assertTrue($store->hasClientCredentials());
        $this->assertSame('client1', $store->getClientId());
        $this->assertSame('secret1', $store->getClientSecret());
        $this->assertSame('payment:read', $store->getScope());
    }

    public function testClearRemovesEverything(): void
    {
        $store = new TokenStore();
        $store->setClientCredentials('client1', 'secret1', 'payment:read');
        $store->setToken('tok_abc', 3600);
        $store->clear();
        $this->assertFalse($store->hasAccessToken());
        $this->assertFalse($store->hasClientCredentials());
    }

    public function testIsExpiringSoonReturnsFalseWhenNoToken(): void
    {
        $store = new TokenStore();
        $this->assertFalse($store->isExpiringSoon());
    }

    public function testIsExpiringSoonReturnsFalseForFreshToken(): void
    {
        $store = new TokenStore();
        $store->setToken('tok_abc', 3600);
        $this->assertFalse($store->isExpiringSoon(30));
    }

    public function testIsExpiringSoonReturnsTrueForExpiredToken(): void
    {
        $store = new TokenStore();
        // Token that already expired 10 seconds ago.
        $store->setToken('tok_abc', 0);
        $this->assertTrue($store->isExpiringSoon(30));
    }
}
