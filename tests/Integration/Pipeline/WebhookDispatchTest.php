<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Services\PublishService;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingWebhookDispatcher;
use Glueful\Api\Webhooks\WebhookDispatcher;

/**
 * Proves the webhook-dispatch listener (V1_DESIGN §5) wired in
 * ThalloServiceProvider::boot() forwards content events to the core WebhookDispatcher
 * with the FROZEN event name + the identity-only payload.
 *
 * Lemma builds no webhook infra: it calls the core
 * WebhookDispatcher::dispatch(string $event, array $data): array (signing / retries /
 * delivery tracking are the core's). The whole security model is that the payload carries
 * identity ONLY — never a `fields` key — so receivers re-fetch through the delivery API
 * with their own scoped key. This test asserts the payload has NO `fields`.
 *
 * The real WebhookDispatcher (which auto-migrates tables and queues delivery jobs) is
 * swapped for a RecordingWebhookDispatcher at the container boundary; the listener
 * resolves WebhookDispatcher::class per-invocation, so it picks up the spy even though it
 * was wired (and possibly already resolved) at boot.
 */
final class WebhookDispatchTest extends AppTestCase
{
    private string $type;
    private string $entry;
    private RecordingWebhookDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = new RecordingWebhookDispatcher();
        $this->setSingleton(WebhookDispatcher::class, $this->dispatcher);

        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $this->entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');
    }

    protected function tearDown(): void
    {
        $this->restoreSingletons();
        $this->appContext()->clearConfigCache();
        parent::tearDown();
    }

    public function testPublishDispatchesEntryPublishedWebhookWithIdentityOnlyPayload(): void
    {
        $this->container()->get(PublishService::class)->publish($this->entry, 'en', 'user00000001');

        $published = array_values(array_filter(
            $this->dispatcher->calls,
            static fn(array $c): bool => $c['event'] === 'entry.published'
        ));

        self::assertCount(1, $published, 'publishing must dispatch exactly one entry.published webhook');

        $call = $published[0];
        self::assertSame('entry.published', $call['event']);

        $payload = $call['data'];
        self::assertSame($this->entry, $payload['entry']);
        self::assertSame($this->type, $payload['type']);
        self::assertSame('en', $payload['locale']);
        self::assertArrayHasKey('version', $payload);
        self::assertSame('user00000001', $payload['actor']);
        self::assertArrayHasKey('timestamp', $payload);

        // The whole security model: identity only, NEVER the entry's field values.
        self::assertArrayNotHasKey('fields', $payload, 'webhook payload must NOT carry fields');
    }

    public function testWebhooksDisabledGateSuppressesDispatch(): void
    {
        // Flip lemma.pipeline.webhooks_enabled off via the context's config cache (getConfig
        // checks the cache first). Restored in tearDown via clearConfigCache().
        $this->setConfig('thallo.pipeline.webhooks_enabled', false);

        // setUp's createEntry/saveDraft already emitted (enabled) events; only the publish
        // below is exercised under the disabled gate.
        $this->dispatcher->calls = [];

        $this->container()->get(PublishService::class)->publish($this->entry, 'en', 'user00000001');

        self::assertSame(
            [],
            $this->dispatcher->calls,
            'with webhooks disabled, publishing must not call the dispatcher'
        );
    }

    // ---- config surgery -------------------------------------------------------------

    private function setConfig(string $key, mixed $value): void
    {
        $context = $this->appContext();
        $prop = (new \ReflectionClass($context))->getProperty('configCache');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $cache */
        $cache = $prop->getValue($context);
        $cache[$key] = $value;
        $prop->setValue($context, $cache);
    }

    // ---- container surgery (the compiled container exposes no setter) ----------------

    /** @var array<string, array{0: bool, 1: mixed}> id => [existed, priorValue] */
    private array $priorSingletons = [];

    private function restoreSingletons(): void
    {
        foreach (array_reverse($this->priorSingletons, true) as $id => [$existed, $value]) {
            $this->writeSingleton($id, $existed, $value);
        }
        $this->priorSingletons = [];
    }

    private function setSingleton(string $id, mixed $value): void
    {
        $this->stashSingleton($id);
        $this->writeSingleton($id, true, $value);
    }

    private function stashSingleton(string $id): void
    {
        if (array_key_exists($id, $this->priorSingletons)) {
            return;
        }
        $singletons = $this->singletons();
        $this->priorSingletons[$id] = [
            array_key_exists($id, $singletons),
            $singletons[$id] ?? null,
        ];
    }

    private function writeSingleton(string $id, bool $present, mixed $value): void
    {
        $container = $this->container();
        $prop = (new \ReflectionClass($container))->getProperty('singletons');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $singletons */
        $singletons = $prop->getValue($container);
        if ($present) {
            $singletons[$id] = $value;
        } else {
            unset($singletons[$id]);
        }
        $prop->setValue($container, $singletons);
    }

    /** @return array<string, mixed> */
    private function singletons(): array
    {
        $container = $this->container();
        $prop = (new \ReflectionClass($container))->getProperty('singletons');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $singletons */
        $singletons = $prop->getValue($container);
        return $singletons;
    }
}
