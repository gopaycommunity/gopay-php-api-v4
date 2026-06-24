<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Module\AuthApi;
use PHPUnit\Framework\Attributes\Test;

final class AuthApiTest extends ModuleTestCase
{
    private AuthApi $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthApi($this->http);
    }

    #[Test]
    public function authenticateThrowsWhenClientIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('clientId must not be empty');
        $this->auth->authenticate('', 'secret', 'payment:read');
    }

    #[Test]
    public function authenticateThrowsWhenClientSecretIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('clientSecret must not be empty');
        $this->auth->authenticate('client1', '', 'payment:read');
    }

    #[Test]
    public function authenticateThrowsWhenScopeIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('scope must not be empty');
        $this->auth->authenticate('client1', 'secret', '');
    }

    #[Test]
    public function authenticateThrowsWithInvalidArgumentCode(): void
    {
        try {
            $this->auth->authenticate('', 'secret', 'payment:read');
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::InvalidArgument, $e->errorCode);
        }
    }

    #[Test]
    public function authenticateStoresTokenOnSuccess(): void
    {
        // Queue the token exchange response
        $this->queueTokenRefreshResponse();

        $this->auth->authenticate('client1', 'secret', 'payment:read');

        $this->assertTrue($this->auth->isAuthenticated());
    }

    #[Test]
    public function isAuthenticatedReturnsFalseWhenNoToken(): void
    {
        // Clear the token set in setUp
        $this->http->logout();
        $this->assertFalse($this->auth->isAuthenticated());
    }

    #[Test]
    public function isAuthenticatedReturnsTrueWhenTokenIsSet(): void
    {
        // Token was set in setUp
        $this->assertTrue($this->auth->isAuthenticated());
    }

    #[Test]
    public function logoutClearsToken(): void
    {
        $this->assertTrue($this->auth->isAuthenticated());
        $this->auth->logout();
        $this->assertFalse($this->auth->isAuthenticated());
    }

    #[Test]
    public function setShareableKeyStoresKey(): void
    {
        $this->auth->setShareableKey('sk_test_abc');
        $this->assertSame('sk_test_abc', $this->http->getShareableKey());
    }

    #[Test]
    public function getBrowserKeysThrowsWhenShareableKeyIsNull(): void
    {
        // No shareableKey set
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('getBrowserKeys()');
        $this->auth->getBrowserKeys();
    }

    #[Test]
    public function getBrowserKeysThrowsWithAuthCredentialsMissingCode(): void
    {
        try {
            $this->auth->getBrowserKeys();
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::AuthCredentialsMissing, $e->errorCode);
        }
    }

    #[Test]
    public function getBrowserKeysReturnsBothKeysWhenAuthenticated(): void
    {
        // Authenticate to store clientId
        $this->queueTokenRefreshResponse();
        $this->auth->authenticate('my-client', 'my-secret', 'payment:read');

        // Set shareable key
        $this->auth->setShareableKey('sk_shareable_key');

        $keys = $this->auth->getBrowserKeys();

        $this->assertSame('sk_shareable_key', $keys['shareable_key']);
        $this->assertSame('my-client', $keys['client_id']);
    }

    #[Test]
    public function getBrowserKeysThrowsWhenClientIdIsNullEvenIfShareableKeySet(): void
    {
        // Set shareableKey but no clientId (no authenticate() called)
        $this->auth->setShareableKey('sk_test');
        // Clear token so clientId is also null
        $this->http->logout();

        $this->expectException(GoPaySdkException::class);
        $this->auth->getBrowserKeys();
    }
}
