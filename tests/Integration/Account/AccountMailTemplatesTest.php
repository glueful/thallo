<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Account;

use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;
use Glueful\Extensions\Users\Services\EmailVerification;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Services\NotificationDispatcher;
use Thallo\Contracts\Account\AccountMailTemplates;
use Thallo\Contracts\Account\StorefrontAccountRecovery;
use Thallo\Core\Account\AccountMailTemplateChooser;
use Thallo\Core\Signup\CustomerSignupService;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A site's customers get their own verification and reset mails: the account pack registers
 * them, and customer signup and recovery send through them. The admin's own mails keep the
 * built-in templates.
 */
final class AccountMailTemplatesTest extends AppTestCase
{
    use AccountHttpHelpers;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    private ?NotificationChannel $previous = null;

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $this->channels()->replaceChannel($this->previous);
        }
        $this->cleanupAccountArtifacts();
        parent::tearDown();
    }

    public function testThePackRegistersTheCustomersTemplates(): void
    {
        $registry = $this->container()->get(EmailTemplateRegistry::class);

        foreach ([AccountMailTemplates::VERIFICATION, AccountMailTemplates::PASSWORD_RESET] as $key) {
            $definition = $registry->find($key);
            self::assertNotNull($definition, "{$key} is not registered");
            self::assertSame('thallo-account', $definition->owner);
        }
        $chooser = $this->container()->get(AccountMailTemplateChooser::class);
        self::assertSame(AccountMailTemplates::VERIFICATION, $chooser->verification());
        self::assertSame(AccountMailTemplates::PASSWORD_RESET, $chooser->passwordReset());
    }

    public function testWithoutTheRegistryCustomersGetTheBuiltInTemplates(): void
    {
        $chooser = new AccountMailTemplateChooser(null);

        self::assertSame('verification', $chooser->verification());
        self::assertSame('password-reset', $chooser->passwordReset());
    }

    public function testACustomersVerificationCodeGoesOutThroughTheirTemplate(): void
    {
        $this->recordMail();
        $tenant = $this->seedTenant();
        $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class)
            ->put('tenancy.default_tenant_uuid', $tenant);
        $this->createdEmails[] = 'template-signup@example.test';

        $this->container()->get(CustomerSignupService::class)->begin([
            'email' => 'template-signup@example.test',
            'password' => 'sufficiently-long-secret',
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
        ], '203.0.113.10');

        self::assertSame(AccountMailTemplates::VERIFICATION, $this->lastTemplate());
    }

    public function testACustomersResetCodeGoesOutThroughTheirTemplate(): void
    {
        $reset = new \ReflectionMethod(EmailVerification::class, 'sendPasswordResetEmail');
        if ($reset->getNumberOfParameters() < 3) {
            self::markTestSkipped('Needs glueful/users 2.5, whose reset mail takes a template name.');
        }
        $this->recordMail();
        $this->seedUser('template-reset@example.test');

        $this->container()->get(StorefrontAccountRecovery::class)->begin('template-reset@example.test', '203.0.113.10');

        self::assertSame(AccountMailTemplates::PASSWORD_RESET, $this->lastTemplate());
    }

    private function recordMail(): void
    {
        $channels = $this->channels();
        $this->previous = $channels->hasChannel('email') ? $channels->getChannel('email') : null;
        $sent = &$this->sent;
        $channels->replaceChannel(new class ($sent) implements NotificationChannel {
            /** @param list<array<string,mixed>> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function getChannelName(): string
            {
                return 'email';
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
                $this->sent[] = $data;
                return true;
            }

            public function format(array $data, Notifiable $notifiable): array
            {
                return $data;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getConfig(): array
            {
                return [];
            }
        });
    }

    private function channels(): \Glueful\Notifications\Services\ChannelManager
    {
        return $this->container()->get(NotificationDispatcher::class)->getChannelManager();
    }

    private function lastTemplate(): ?string
    {
        self::assertNotSame([], $this->sent, 'no mail reached the email channel');
        $data = $this->sent[count($this->sent) - 1];

        return $data['template_name'] ?? ($data['data']['template_name'] ?? null);
    }
}
