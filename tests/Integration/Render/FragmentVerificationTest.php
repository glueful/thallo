<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Contracts\Delivery\PublicRouteResolver;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Content\Preview\PreviewMinter;
use Thallo\Core\Content\Preview\PreviewWorkingCopyStore;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Fragments\DocumentIndex;
use Thallo\Render\Fragments\FragmentRenderer;
use Thallo\Render\Fragments\FragmentVerification;
use Thallo\Render\Fragments\PreviewFragments;
use Thallo\Render\Fragments\RenderScopeResolver;
use Thallo\Render\Fragments\TemplateDependencies;
use Thallo\Render\Http\Controllers\RenderController;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * Fragment verification (visual builder spec §3.5): every fixture page is rendered whole and
 * block-by-block, and every root the resolver allows as a fragment is byte-identical to the
 * whole page's markup for it — nested tabs, a pricing table and a page with several
 * image-bearing blocks included — through `entry.twig` and again through the shipped
 * `entry/post.twig`, so a post's stage patches as a page's does. The record carries the participating
 * templates' hashes; a change to any of them invalidates the record until this test
 * re-records it (`THALLO_RECORD_FRAGMENT_VERIFICATION=1`).
 */
final class FragmentVerificationTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private string $type = '';
    private string $postType = '';

    /** @var list<string> */
    private array $blobs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        // The default theme's own post template (entry/post.twig) wraps the same body.
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        foreach ([0, 1] as $i) {
            $uuid = Utils::generateNanoID();
            $this->connection()->table('blobs')->insert([
                'uuid' => $uuid, 'name' => "pic{$i}.png", 'mime_type' => 'image/png',
                'size' => 1, 'url' => "uploads/pic{$i}.png", 'visibility' => 'public',
                'status' => 'active', 'created_by' => 'user00000001',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->blobs[] = $uuid;
        }
    }

    protected function tearDown(): void
    {
        $this->container()->get(RenderContextExtension::class)->setAnnotationScope('none');
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private static function text(string $id, string $body): array
    {
        return ['id' => $id, 'type' => 'rich_text', 'data' => ['body' => "<p>{$body}</p>"]];
    }

    private static function heading(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'heading', 'data' => ['text' => $text, 'level' => 'h2']];
    }

    /** @return array<string, list<array<string,mixed>>> fixture name => body */
    private function fixtures(): array
    {
        return [
            // Tabs hold their panels' blocks (the authored cap is three levels: tabs, tab, content).
            'nested tabs' => [
                ['id' => 'tabs00000001', 'type' => 'tabs', 'data' => ['items' => [
                    ['id' => 'tab000000001', 'type' => 'tab', 'data' => [
                        'label' => 'First', 'content' => [self::text('text00000001', 'Inside the first tab')],
                    ]],
                    ['id' => 'tab000000002', 'type' => 'tab', 'data' => [
                        'label' => 'Second', 'content' => [self::heading('head00000001', 'Second panel')],
                    ]],
                ]]],
                ['id' => 'outer0000001', 'type' => 'container', 'data' => ['width' => 'narrow', 'content' => [
                    self::text('text00000005', 'Boxed'),
                ]]],
                self::text('text00000002', 'After the container'),
            ],
            'pricing table' => [
                ['id' => 'table0000001', 'type' => 'pricing_table', 'data' => [
                    'tiers' => [
                        ['id' => 'tier00000001', 'type' => 'pricing_tier', 'data' => [
                            'title' => 'Starter', 'price' => '$9', 'button_label' => 'Go', 'button_url' => '/go',
                        ]],
                        ['id' => 'tier00000002', 'type' => 'pricing_tier', 'data' => [
                            'title' => 'Team', 'price' => '$29', 'highlight' => true,
                        ]],
                    ],
                    'features' => [
                        ['id' => 'feat00000001', 'type' => 'pricing_feature', 'data' => [
                            'is_section' => true, 'title' => 'Basics',
                        ]],
                        ['id' => 'feat00000002', 'type' => 'pricing_feature', 'data' => [
                            'title' => 'Seats', 'value_1' => '1', 'value_2' => '10',
                        ]],
                    ],
                ]],
                self::heading('head00000002', 'Compare'),
            ],
            // Depth five (spec §5.2): a heading at the bottom of section > columns > card >
            // container is its own root; its setting lands on it inside the whole page.
            'five deep' => json_decode(
                (string) file_get_contents(__DIR__ . '/../../fixtures/composition/five-deep.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            )['body'],
            'images' => [
                ['id' => 'image0000001', 'type' => 'image', 'data' => ['image' => $this->blobs[0], 'alt' => 'lead']],
                ['id' => 'box000000001', 'type' => 'container', 'data' => ['content' => [
                    ['id' => 'image0000002', 'type' => 'image', 'data' => [
                        'image' => $this->blobs[1], 'alt' => 'inner',
                    ]],
                    self::text('text00000003', 'Beside the inner image'),
                ]]],
                self::text('text00000004', 'Last'),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $body @return array{entry: string, token: string} */
    private function page(string $title, array $body, ?string $type = null): array
    {
        $entries = new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
        $entry = $entries->createEntry($type ?? $this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => $title, 'body' => $body], 1, 0, 'user00000001');
        return ['entry' => $entry, 'token' => $this->container()->get(PreviewMinter::class)->mint($entry, 'en')];
    }

    private function canvas(string $token): string
    {
        $response = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}?canvas=1", 'GET'),
            $token,
        );
        self::assertSame(200, $response->getStatusCode());
        return (string) $response->getContent();
    }

    /** @param array<string,mixed> $fields */
    private function index(array $fields): DocumentIndex
    {
        $registry = $this->container()->get(BlockStyleRegistry::class);
        return DocumentIndex::of($fields, ['body'], static fn (string $t): array => $registry->regionsFor($t));
    }

    private function dependencies(): TemplateDependencies
    {
        return new TemplateDependencies($this->container()->get(TwigFactory::class)->environment());
    }

    /** @return list<string> every block template of the default theme, as `blocks/{type}.twig` */
    private static function shippedBlockTypes(): array
    {
        $dir = dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/templates/blocks';
        $types = [];
        foreach (glob($dir . '/*.twig') ?: [] as $file) {
            $types[] = basename($file, '.twig');
        }
        sort($types);
        return $types;
    }

    public function testEveryFragmentTheResolverAllowsIsByteIdenticalToTheWholePage(): void
    {
        $dependencies = $this->dependencies();
        $resolver = new RenderScopeResolver($this->container()->get(BlockStyleRegistry::class), $dependencies);
        $renderer = $this->container()->get(FragmentRenderer::class);
        $routes = $this->container()->get(PublicRouteResolver::class);
        $verified = [];
        $escalated = [];
        $runs = [];
        foreach ($this->fixtures() as $name => $body) {
            $runs[] = [$name, $body, $this->type];
            $runs[] = ["post/{$name}", $body, $this->postType];
        }
        foreach ($runs as [$name, $body, $type]) {
            $page = $this->page($name, $body, $type);
            $whole = $this->canvas($page['token']);
            $result = $routes->resolvePreview($page['token']);
            self::assertSame('content', $result['kind']);
            $index = $this->index(['body' => $body]);
            $shaped = $this->index($result['content']['fields']);
            foreach ($index->ids() as $id) {
                $roots = $resolver->resolve([$id], $index, $index);
                if ($roots === null) {
                    $escalated[] = "{$name}:{$id}";
                    continue;
                }
                $fragments = $renderer->render($result['content'], 'en', $shaped, $roots);
                foreach ($fragments as $root => $html) {
                    $wrapper = '<div class="thallo-preview-block" data-thallo-block="' . $root . '">';
                    self::assertStringStartsWith($wrapper, $html);
                    self::assertStringContainsString($html, $whole, "{$name}: fragment {$root} (for {$id}) differs");
                    $verified[] = "{$name}:{$id}->{$root}";
                }
            }
        }
        // The table rows: nested tabs lift to the tabs block, the table's items to the table,
        // a container is its own root, and image-bearing roots escalate.
        self::assertContains('nested tabs:tab000000001->tabs00000001', $verified);
        self::assertContains('nested tabs:text00000001->text00000001', $verified);
        self::assertContains('nested tabs:outer0000001->outer0000001', $verified);
        self::assertContains('nested tabs:text00000005->text00000005', $verified, 'a root two levels deep');
        self::assertContains('pricing table:tier00000002->table0000001', $verified);
        self::assertContains('pricing table:feat00000002->table0000001', $verified);
        self::assertContains('images:text00000004->text00000004', $verified);
        self::assertContains('five deep:head00000001->head00000001', $verified, 'a root five levels deep');
        self::assertContains('five deep:cont00000002->cont00000002', $verified);
        self::assertContains('images:text00000003->text00000003', $verified);
        self::assertContains('images:image0000001', $escalated, 'claims the priority image');
        self::assertContains('images:image0000002', $escalated);
        self::assertContains('images:box000000001', $escalated, 'holds an image that would claim');
        self::assertGreaterThanOrEqual(12, count($verified));
        // The same roots verify through the post template.
        self::assertContains('post/nested tabs:tab000000001->tabs00000001', $verified);
        self::assertContains('post/five deep:head00000001->head00000001', $verified);
        self::assertContains('post/images:image0000001', $escalated);
    }

    public function testTheRecordCoversTheDefaultThemeAndAChangedTemplateInvalidatesIt(): void
    {
        $dependencies = $this->dependencies();
        $templates = [];
        foreach (self::shippedBlockTypes() as $type) {
            foreach ($dependencies->templatesFor($type) as $name) {
                $templates[] = $name;
            }
        }
        $templates = array_values(array_unique($templates));
        $verification = new FragmentVerification();
        $current = $verification->build($dependencies, 'default', ['entry.twig', 'entry/post.twig'], $templates);
        if (getenv('THALLO_RECORD_FRAGMENT_VERIFICATION') === '1') {
            $verification->write($current);
        }
        self::assertSame(
            $current,
            $verification->record(),
            'a verified template changed: run this test with THALLO_RECORD_FRAGMENT_VERIFICATION=1 to re-record',
        );
        self::assertTrue($verification->verified($dependencies, 'default', 'entry.twig', $templates));
        self::assertTrue($verification->verified($dependencies, 'default', 'entry.twig', ['blocks/rich_text.twig']));
        $text = ['blocks/rich_text.twig'];
        self::assertTrue($verification->verified($dependencies, 'default', 'entry/post.twig', $text));
        self::assertFalse($verification->verified($dependencies, 'default', 'entry/page.twig', $text));
        self::assertFalse($verification->verified($dependencies, 'other', 'entry.twig', ['blocks/rich_text.twig']));
        self::assertFalse($verification->verified($dependencies, 'default', 'entry.twig', ['blocks/nope.twig']));

        // A template whose source changed: its hash no longer matches the record.
        $stale = $current;
        $stale['templates']['blocks/rich_text.twig'] = str_repeat('0', 64);
        $path = sys_get_temp_dir() . '/thallo-fragments-' . Utils::generateNanoID() . '.json';
        $temp = new FragmentVerification($path);
        $temp->write($stale);
        try {
            self::assertFalse($temp->verified($dependencies, 'default', 'entry.twig', ['blocks/rich_text.twig']));
            self::assertTrue($temp->verified($dependencies, 'default', 'entry.twig', ['blocks/heading.twig']));
        } finally {
            @unlink($path);
        }
    }

    public function testAnAcceptedApplyAnswersFragmentsForTheAffectedRootsOnlyWhenEnabled(): void
    {
        $body = $this->fixtures()['nested tabs'];
        $page = $this->page('apply', $body);
        $store = $this->container()->get(PreviewWorkingCopyStore::class);
        $before = ['title' => 'apply', 'body' => $body];
        $after = $before;
        $after['body'][0]['data']['items'][0]['data']['label'] = 'Renamed';
        $ops = [['type' => 'SetField', 'block' => 'tab000000001', 'field' => 'label']];
        self::assertTrue($store->accept($page['entry'], 'en', null, null, $before, [], 300)['accepted']);
        $epoch = $store->current($page['entry'], 'en')['epoch'];
        $accepted = $store->accept($page['entry'], 'en', $epoch, 1, $after, $ops, 300);
        self::assertTrue($accepted['accepted']);

        $fragments = fn (bool $enabled): PreviewFragments => new PreviewFragments(
            $this->container()->get(PublicRouteResolver::class),
            $this->container()->get(PreviewSessionVerifier::class),
            $this->container()->get(BlockStyleRegistry::class),
            $this->container()->get(TwigFactory::class),
            $this->container()->get(FragmentRenderer::class),
            new FragmentVerification(),
            'default',
            $enabled,
        );
        self::assertNull($fragments(false)->render($page['token'], $ops, $before, $after, ['body']));

        $out = $fragments(true)->render($page['token'], $ops, $before, $after, ['body']);
        self::assertNotNull($out, 'the tab label edit lifts to the tabs block, which renders in isolation');
        self::assertSame(['tabs00000001'], array_keys($out));
        self::assertStringContainsString('Renamed', $out['tabs00000001']);
        $whole = $this->canvas($page['token']);
        self::assertStringContainsString($out['tabs00000001'], $whole, 'identical to the whole-page render');

        // Whole-page answers: no operations, a page-settings change, a themed session is not verified.
        self::assertNull($fragments(true)->render($page['token'], [], $before, $after, ['body']));
        $title = [['type' => 'SetPageSettings', 'field' => 'title']];
        self::assertNull($fragments(true)->render($page['token'], $title, $before, $after, ['body']));
        self::assertNull($fragments(true)->render($page['token'], $ops, null, $after, ['body']), 'no accepted-before');
    }
}
