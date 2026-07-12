<?php

declare(strict_types=1);

namespace App\Signup;

use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Thallo\Tenancy\Tenant\SingleStoreTenant;
use Thallo\Tenancy\System\SystemFlags;

final class MemberSignupService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly SingleStoreTenant $singleStore,
        private readonly SystemFlags $flags,
        private readonly SignupConfig $config,
        private readonly SignupRolePolicy $roles,
        private readonly SignupIntentRepository $intents,
        private readonly SignupVerifier $verifier,
        private readonly ContinuationTokens $continuations,
        private readonly SignupThrottle $throttle,
        private readonly SignupMailSender $mail,
        private readonly UserRepository $users,
        private readonly TenantContextRunner $tenants,
        private readonly TenantAdministration $administration,
        private readonly TenancyLifecycleAudit $audit,
    ) {
    }

    /** @param array<string,mixed> $input @return array{accepted:true,intent_uuid:string} */
    public function begin(array $input, string $ip): array
    {
        $data = SignupInput::anonymous($input);
        if (!$this->throttle->allowIntent('member', $ip, $data['email'])) {
            throw new SignupException('Signup request limit reached.', 429);
        }
        if (!$this->flags->tenancyEnabled() && $this->singleStore->defaultUuidOrNull() === null) {
            return ['accepted' => true, 'intent_uuid' => $this->opaqueRequestId()];
        }
        $tenantUuid = $this->singleStore->resolve();
        if (!$this->config->memberSignupEnabled($tenantUuid) || !$this->config->emailChannelAvailable()) {
            return ['accepted' => true, 'intent_uuid' => $this->opaqueRequestId()];
        }
        $intentUuid = $this->intents->create([
            'kind' => 'member',
            'origin' => 'anonymous',
            'email' => $data['email'],
            'username' => $data['username'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'password_hash' => (new PasswordHasher())->hash($data['password']),
            'tenant_uuid' => $tenantUuid,
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => $this->throttle->hashIdentifier($ip),
            'expires_at' => $this->expiresAt(),
        ]);

        if ($this->users->emailExists($data['email'])) {
            try {
                $this->mail->sendExistingAccountNotice($intentUuid, $data['email']);
            } finally {
                $this->intents->consume($intentUuid, 'existing_account_handoff');
            }
            return ['accepted' => true, 'intent_uuid' => $intentUuid];
        }

        try {
            $this->verifier->issue($intentUuid, $data['email']);
        } catch (\Throwable $exception) {
            $this->intents->hardDelete($intentUuid);
            throw $exception;
        }
        return ['accepted' => true, 'intent_uuid' => $intentUuid];
    }

    /** @return array<string,mixed> */
    public function activate(string $intentUuid, string $continuationToken): array
    {
        $intent = $this->intents->find($intentUuid);
        if ($intent === null || ($intent['kind'] ?? null) !== 'member') {
            throw new SignupException('Signup intent is unavailable.', 404);
        }
        if (($intent['status'] ?? null) === 'consumed') {
            return ['status' => 'consumed', 'outcome' => $intent['completion_outcome']];
        }
        $tenantUuid = (string) ($intent['tenant_uuid'] ?? '');
        try {
            return $this->tenants->runAsTenant($tenantUuid, function () use ($intentUuid, $tenantUuid): array {
                return $this->connection->transaction(function () use ($intentUuid, $tenantUuid): array {
                    $intent = $this->intents->lockForUpdate($intentUuid);
                    if ($intent === null || ($intent['status'] ?? null) !== 'email_verified') {
                        throw new SignupException('Signup intent cannot be activated.', 409);
                    }
                    $tenant = $this->administration->getTenant($this->context, $tenantUuid);
                    $role = $this->config->memberSignupRole($tenantUuid);
                    if (
                        ($tenant['status'] ?? null) !== 'active'
                        || !$this->config->memberSignupEnabled($tenantUuid)
                        || !$this->roles->isEligible($tenantUuid, $role)
                    ) {
                        throw new SignupException('Workspace signup policy changed before activation.', 409);
                    }
                    $email = (string) $intent['email'];
                    if ($this->users->emailExists($email)) {
                        $this->intents->consume($intentUuid, 'existing_account_handoff');
                        return ['status' => 'consumed', 'outcome' => 'existing_account_handoff'];
                    }
                    $username = (string) $intent['username'];
                    if ($this->users->usernameExists($username)) {
                        throw new SignupException('Username is no longer available.', 409, [
                            'username' => 'Choose another username.',
                        ], 'USERNAME_CONFLICT');
                    }
                    $userUuid = $this->users->create([
                        'username' => $username,
                        'email' => $email,
                        'password' => (string) $intent['password_hash'],
                        'status' => 'active',
                        'email_verified_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $this->users->updateProfile($userUuid, [
                        'first_name' => (string) $intent['first_name'],
                        'last_name' => (string) $intent['last_name'],
                    ]);
                    $this->administration->addMember($this->context, $tenantUuid, $userUuid, $role);
                    $this->intents->setResults($intentUuid, $userUuid, $tenantUuid);
                    $this->intents->consume($intentUuid, 'activated');
                    $this->connection->afterCommit(fn () => $this->audit->record(
                        'signup.member_activated',
                        $userUuid,
                        $tenantUuid,
                        ['intent_uuid' => $intentUuid, 'role' => $role],
                    ));
                    return ['status' => 'active', 'user_uuid' => $userUuid, 'tenant_uuid' => $tenantUuid];
                });
            });
        } catch (SignupException $exception) {
            if ($exception->errorCode === 'USERNAME_CONFLICT') {
                return [
                    'status' => 'conflict',
                    'code' => 'USERNAME_CONFLICT',
                    'continuation_token' => $continuationToken,
                    'errors' => $exception->errors,
                ];
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->users->emailExists((string) $intent['email'])) {
                $this->intents->consume($intentUuid, 'existing_account_handoff');
                return ['status' => 'consumed', 'outcome' => 'existing_account_handoff'];
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function changeUsername(
        string $intentUuid,
        string $token,
        string $operationId,
        string $username,
    ): array {
        try {
            $username = \Glueful\DTOs\UsernameDTO::from(['username' => $username])->username;
        } catch (\Glueful\Validation\ValidationException) {
            throw new SignupException('Username is invalid.', 422, ['username' => 'Choose a valid username.']);
        }
        $payloadHash = hash('sha256', json_encode(['username' => $username], JSON_THROW_ON_ERROR));
        try {
            return $this->connection->transaction(function () use (
                $intentUuid,
                $token,
                $operationId,
                $username,
                $payloadHash,
            ): array {
                $grant = $this->continuations->authorizeInTransaction(
                    $intentUuid,
                    $token,
                    $operationId,
                    $payloadHash,
                );
                if ($grant->replay) {
                    return ($grant->result ?? []) + ['continuation_token' => $grant->token];
                }
                if ($this->users->usernameExists($username)) {
                    throw new SignupException('Username is no longer available.', 409, [
                        'username' => 'Choose another username.',
                    ]);
                }
                $this->intents->updateUsername($intentUuid, $username);
                $result = ['status' => 'updated'];
                $this->continuations->completeInTransaction($intentUuid, $operationId, $result);
                return $result + ['continuation_token' => $grant->token];
            });
        } catch (SignupException $exception) {
            if ($exception->errorCode === 'CONTINUATION_REJECTED') {
                $this->continuations->invalidate($intentUuid);
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function continue(
        string $intentUuid,
        string $token,
        string $operationId,
        string $operation,
        array $payload,
    ): array {
        if ($operation === 'change_username') {
            return $this->changeUsername(
                $intentUuid,
                $token,
                $operationId,
                is_string($payload['username'] ?? null) ? $payload['username'] : '',
            );
        }
        if ($operation !== 'resume') {
            throw new SignupException('Unknown continuation operation.', 422);
        }
        $payloadHash = hash('sha256', json_encode(
            ['operation' => 'resume', 'payload' => []],
            JSON_THROW_ON_ERROR,
        ));
        try {
            $grant = $this->connection->transaction(fn (): ContinuationGrant =>
                $this->continuations->authorizeInTransaction(
                    $intentUuid,
                    $token,
                    $operationId,
                    $payloadHash,
                ));
        } catch (SignupException $exception) {
            if ($exception->errorCode === 'CONTINUATION_REJECTED') {
                $this->continuations->invalidate($intentUuid);
            }
            throw $exception;
        }
        return $this->activate($intentUuid, $grant->token);
    }

    /** @return array<string,mixed> */
    public function joinAuthenticated(string $tenantUuid, string $userUuid): array
    {
        return $this->tenants->runAsTenant($tenantUuid, function () use ($tenantUuid, $userUuid): array {
            $user = $this->users->findByUuid($userUuid);
            if (($user['email_verified_at'] ?? null) === null) {
                throw new SignupException('Verify your email before joining a workspace.', 403);
            }
            $tenant = $this->administration->getTenant($this->context, $tenantUuid);
            $role = $this->config->memberSignupRole($tenantUuid);
            if (
                ($tenant['status'] ?? null) !== 'active'
                || !$this->config->memberSignupEnabled($tenantUuid)
                || !$this->roles->isEligible($tenantUuid, $role)
            ) {
                throw new SignupException('Member signup is unavailable for this workspace.', 403);
            }
            foreach ($this->administration->listMembers($this->context, $tenantUuid) as $member) {
                if (($member['user_uuid'] ?? null) === $userUuid && ($member['status'] ?? null) === 'active') {
                    return ['status' => 'active', 'tenant_uuid' => $tenantUuid];
                }
            }
            $this->administration->addMember($this->context, $tenantUuid, $userUuid, $role);
            $this->audit->record('signup.member_joined', $userUuid, $tenantUuid, ['role' => $role]);
            return ['status' => 'active', 'tenant_uuid' => $tenantUuid];
        });
    }

    private function expiresAt(): string
    {
        $ttl = max(300, (int) config($this->context, 'signup.intent_ttl_seconds', 86400));
        return gmdate('Y-m-d H:i:s', time() + $ttl);
    }

    private function opaqueRequestId(): string
    {
        return \Glueful\Helpers\Utils::generateNanoID(12);
    }
}
