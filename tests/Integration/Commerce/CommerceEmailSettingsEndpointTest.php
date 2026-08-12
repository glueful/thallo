<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Settings\SettingsStore;
use App\Tests\Support\AppTestCase;
use Glueful\Http\Response;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\EmailSettingsController;

/**
 * Store-settings spec §4.2 follow-up: GET/PUT /v1/admin/commerce/emails — the per-template
 * order-email switches. Template CONTENT stays with the email-notification extension's own
 * /email/templates API; this endpoint carries only whether each template sends.
 */
final class CommerceEmailSettingsEndpointTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (EmailSettingsController::TEMPLATES as $name) {
            $this->connection()->table('settings')
                ->where(['key' => "thallo-commerce.email.{$name}.enabled"])->delete();
        }
        $this->container()->get(SettingsStore::class)->clearCache();
    }

    /**
     * The four ORDER templates default ON; `payment_request` (payment-links spec §2.4) is the one
     * that defaults OFF, from an EXPLICIT `false` in the pack config — the controller's generic
     * fallback here is `true`, so this asymmetry is exactly what proves the pack config sets that
     * key rather than omitting it.
     */
    public function testGetReportsEveryTemplateWithItsOwnConfiguredDefault(): void
    {
        $data = $this->data($this->controller()->show(Request::create('/x')));

        self::assertFalse($data['commerce_mailer_active']);
        self::assertSame(EmailSettingsController::TEMPLATES, array_column($data['templates'], 'template'));
        foreach ($data['templates'] as $row) {
            $expected = $row['template'] !== 'payment_request';
            self::assertSame($expected, $row['enabled']['value'], $row['template'] . ' value');
            self::assertSame($expected, $row['enabled']['default'], $row['template'] . ' default');
            self::assertFalse($row['enabled']['overridden']);
            self::assertStringStartsWith('commerce.', $row['key']);
        }
    }

    public function testToggleOffRoundTripsAndStoresARow(): void
    {
        $data = $this->data($this->put(['templates' => ['order_paid' => false]]));

        $byName = array_column($data['templates'], null, 'template');
        self::assertFalse($byName['order_paid']['enabled']['value']);
        self::assertTrue($byName['order_paid']['enabled']['overridden']);
        self::assertTrue($byName['order_confirmation']['enabled']['value']);

        $row = $this->connection()->table('settings')
            ->where(['key' => 'thallo-commerce.email.order_paid.enabled'])->first();
        self::assertIsArray($row);
        self::assertSame('0', $row['value']);
    }

    public function testClearDeletesTheRowAndTheDefaultShowsThrough(): void
    {
        $this->put(['templates' => ['order_canceled' => false]]);
        $data = $this->data($this->put(['templates' => ['order_canceled' => null]]));

        $byName = array_column($data['templates'], null, 'template');
        self::assertTrue($byName['order_canceled']['enabled']['value']);
        self::assertFalse($byName['order_canceled']['enabled']['overridden']);
        self::assertNull(
            $this->connection()->table('settings')
                ->where(['key' => 'thallo-commerce.email.order_canceled.enabled'])->first()
        );
    }

    public function testValidationRejectsUnknownTemplatesAndNonBooleans(): void
    {
        foreach (
            [
                ['templates' => ['rogue_template' => true]],
                ['templates' => ['order_paid' => 'yes']],
                ['templates' => 'order_paid'],
            ] as $body
        ) {
            try {
                $this->put($body);
                self::fail('Expected ValidationException for ' . json_encode($body));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @param array<string,mixed> $body */
    private function put(array $body): Response
    {
        return $this->controller()->update(Request::create(
            '/x',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        ));
    }

    private function controller(): EmailSettingsController
    {
        return $this->container()->get(EmailSettingsController::class);
    }

    /** @return array<string,mixed> */
    private function data(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }
}
