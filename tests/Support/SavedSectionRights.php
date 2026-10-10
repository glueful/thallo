<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Authorization\PermissionRequirementAuthority;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Http\Controllers\SavedSectionController;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Content\Style\Classes\StyleClassReferenceGuard;
use Thallo\Core\Content\Validation\FieldValidator;

/**
 * The saved-section controller as an editor holding exactly `$granted` would reach it: the
 * controller decides by a section's scope (sections and templates design §5), so a test names the
 * permissions its caller has instead of standing up a role.
 */
final class SavedSectionRights
{
    /** @param list<string> $granted */
    public static function controller(ContainerInterface $container, array $granted): SavedSectionController
    {
        $authority = new class ($granted) implements PermissionRequirementAuthority {
            /** @param list<string> $granted */
            public function __construct(private readonly array $granted)
            {
            }

            public function allows(Request $request, array $requirements): bool
            {
                return array_intersect($requirements, $this->granted) !== [];
            }
        };
        return new SavedSectionController(
            $container->get(SavedSectionRepository::class),
            $container->get(PatternLibrary::class),
            $container->get(FieldValidator::class),
            $container->has(StyleClassReferenceGuard::class) ? $container->get(StyleClassReferenceGuard::class) : null,
            $authority,
            $container->get(LayoutSurfaceRegistry::class),
            $container->get(LayoutValidator::class),
            $container->get(\Thallo\Core\Content\Layouts\LayoutFieldLabels::class),
            $container->get(\Thallo\Core\Content\Palette\PaletteFence::class),
            $container->get(\Thallo\Core\Content\Palette\PaletteNormalizer::class),
        );
    }

    /** Every right a saved section can need. */
    public static function editor(ContainerInterface $container): SavedSectionController
    {
        return self::controller($container, ['content.manage', 'templates.manage']);
    }
}
