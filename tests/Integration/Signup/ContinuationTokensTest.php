<?php

declare(strict_types=1);

namespace App\Tests\Integration\Signup;

use App\Signup\ContinuationTokens;
use App\Signup\SignupException;
use App\Signup\SignupIntentRepository;
use App\Tests\Support\AppTestCase;

final class ContinuationTokensTest extends AppTestCase
{
    public function testCommittedOperationRotatesAndCanReplayOnceWithPreviousToken(): void
    {
        $intent = $this->intent();
        $tokens = $this->container()->get(ContinuationTokens::class);
        $initial = $tokens->issue($intent);
        $hash = hash('sha256', 'payload');

        $grant = $this->connection()->transaction(function () use ($tokens, $intent, $initial, $hash) {
            $grant = $tokens->authorizeInTransaction($intent, $initial, 'operation-1', $hash);
            $tokens->completeInTransaction($intent, 'operation-1', ['status' => 'updated']);
            return $grant;
        });
        self::assertNotSame($initial, $grant->token);

        $replay = $this->connection()->transaction(fn () => $tokens->authorizeInTransaction(
            $intent,
            $initial,
            'operation-1',
            $hash,
        ));
        self::assertTrue($replay->replay);
        self::assertSame(['status' => 'updated'], $replay->result);
        self::assertNotSame($grant->token, $replay->token);

        $this->expectException(SignupException::class);
        $this->connection()->transaction(fn () => $tokens->authorizeInTransaction(
            $intent,
            $initial,
            'operation-1',
            $hash,
        ));
    }

    public function testRolledBackAuthorizationLeavesInitialTokenCurrent(): void
    {
        $intent = $this->intent();
        $tokens = $this->container()->get(ContinuationTokens::class);
        $initial = $tokens->issue($intent);
        $hash = hash('sha256', 'rollback');
        try {
            $this->connection()->transaction(function () use ($tokens, $intent, $initial, $hash): void {
                $tokens->authorizeInTransaction($intent, $initial, 'operation-2', $hash);
                throw new \RuntimeException('failpoint');
            });
        } catch (\RuntimeException) {
        }

        $grant = $this->connection()->transaction(fn () => $tokens->authorizeInTransaction(
            $intent,
            $initial,
            'operation-2',
            $hash,
        ));
        self::assertFalse($grant->replay);
    }

    private function intent(): string
    {
        return $this->container()->get(SignupIntentRepository::class)->create([
            'kind' => 'member',
            'origin' => 'anonymous',
            'email' => 'tokens@example.test',
            'username' => 'tokens',
            'first_name' => 'Token',
            'last_name' => 'Test',
            'password_hash' => 'hash',
            'tenant_uuid' => 'tenant000001',
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => hash('sha256', 'ip'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
    }
}
