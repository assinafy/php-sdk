<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\UserResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class UserResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private UserResource $users;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->users = new UserResource($this->http, new Configuration('key', 'account'));
    }

    public function testGetReturnsAuthenticatedUser(): void
    {
        $this->http->queueJson(200, ['id' => 'user-1', 'email' => 'user@example.com']);

        $user = $this->users->get();

        $this->assertSame('users/self', $this->http->lastCall()['uri']);
        $this->assertSame('user-1', $user['id']);
    }

    public function testGetNormalizesSandboxNestedUserShape(): void
    {
        $this->http->queueJson(200, [
            'user' => ['id' => 'user-1', 'email' => 'user@example.com'],
            'accounts' => [['id' => 'account-1']],
        ]);

        $user = $this->users->get();

        $this->assertSame('user-1', $user['id']);
        $this->assertArrayNotHasKey('accounts', $user);
    }

    public function testGetSupportsBearerOnPublicClient(): void
    {
        $users = new UserResource($this->http, Configuration::forPublic());
        $this->http->queueJson(200, ['id' => 'user-1']);

        $users->get('token');

        $this->assertSame(
            ['Authorization' => 'Bearer token'],
            $this->http->lastCall()['headers']
        );
    }

    public function testMonthlyAndDailyStatsQueries(): void
    {
        $this->http->queueJson(200, [['period' => '2026-08', 'documents_uploaded' => 1]]);
        $monthly = $this->users->stats();
        $this->assertSame(['granularity' => 'monthly'], $this->http->lastCall()['query']);
        $this->assertSame('2026-08', $monthly[0]['period']);

        $this->http->queueJson(200, []);
        $this->users->stats(UserResource::GRANULARITY_DAILY, '2026-08');
        $this->assertSame(
            ['granularity' => 'daily', 'month' => '2026-08'],
            $this->http->lastCall()['query']
        );
    }

    public function testDailyStatsRequireValidMonth(): void
    {
        $this->expectException(ValidationException::class);
        $this->users->stats(UserResource::GRANULARITY_DAILY, '2026-13');
    }

    public function testMonthlyStatsRejectMalformedOptionalMonth(): void
    {
        $this->expectException(ValidationException::class);
        $this->users->stats(UserResource::GRANULARITY_MONTHLY, 'abcde12junk');
    }

    public function testGetsAndUpdatesNotificationPreferences(): void
    {
        $all = array_fill_keys(UserResource::NOTIFICATION_PREFERENCE_CODES, true);
        $this->http->queueJson(200, $all);
        $this->assertSame($all, $this->users->notificationPreferences());
        $this->assertSame('users/self/notification-preferences', $this->http->lastCall()['uri']);

        $this->http->queueJson(200, [...$all, 'SignerDeclined' => false]);
        $updated = $this->users->updateNotificationPreferences(['SignerDeclined' => false]);
        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame(['SignerDeclined' => false], $call['body']);
        $this->assertFalse($updated['SignerDeclined']);
    }

    public function testNotificationPreferenceUpdateRejectsInvalidPayloads(): void
    {
        foreach ([[], ['Unknown' => true], ['DocumentCompleted' => 1]] as $payload) {
            try {
                $this->users->updateNotificationPreferences($payload);
                $this->fail('Expected invalid notification preferences to be rejected');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMfaMethodsUseDocumentedRoutesAndBodies(): void
    {
        $this->http->queueJson(200, ['methods' => [], 'recovery_codes_remaining' => 0])
            ->queueJson(200, ['id' => 'm1', 'secret' => 'S', 'provisioning_uri' => 'otpauth://totp/x'])
            ->queueJson(200, ['recovery_codes' => ['ABCD-EFGH-JKMN']])
            ->queueJson(200, ['recovery_codes' => ['PQRS-TUVW-XYZ2']])
            ->queueJson(200, ['is_mfa_enabled' => false]);

        $this->users->mfaMethods();
        $this->users->startTotpEnrollment('My phone');
        $this->users->confirmTotpEnrollment('m1', '123456', reauthCode: '654321');
        $this->users->regenerateRecoveryCodes(code: 'ABCD-EFGH-JKMN');
        $this->assertSame(['is_mfa_enabled' => false], $this->users->removeMfaMethod('m1', 'current-password'));

        $calls = $this->http->calls;
        $this->assertSame([
            'GET users/self/mfa',
            'POST users/self/mfa/totp',
            'PUT users/self/mfa/totp/confirm',
            'POST users/self/mfa/recovery-codes',
            'DELETE users/self/mfa/m1',
        ], array_map(static fn (array $c): string => $c['method'] . ' ' . $c['uri'], $calls));
        $this->assertSame(['label' => 'My phone'], $calls[1]['body']);
        $this->assertSame(['id' => 'm1', 'code' => '123456', 'reauth_code' => '654321'], $calls[2]['body']);
        $this->assertSame(['code' => 'ABCD-EFGH-JKMN'], $calls[3]['body']);
        $this->assertSame(['password' => 'current-password'], $calls[4]['body']);
    }

    public function testFirstEnrollmentNeedsNoReauthentication(): void
    {
        $this->http->queueJson(200, [])->queueJson(200, []);

        $this->users->startTotpEnrollment();
        $this->assertSame([], $this->http->lastCall()['body']);
        $this->users->confirmTotpEnrollment('m1', '123456');
        $this->assertSame(['id' => 'm1', 'code' => '123456'], $this->http->lastCall()['body']);
    }

    public function testRemovingOrRegeneratingRequiresAProof(): void
    {
        foreach (
            [
                fn () => $this->users->removeMfaMethod('m1'),
                fn () => $this->users->regenerateRecoveryCodes(' ', null),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Expected ValidationException');
            } catch (ValidationException) {
                $this->assertSame([], $this->http->calls);
            }
        }
    }
}
