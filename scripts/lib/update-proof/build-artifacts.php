<?php

declare(strict_types=1);

// Builds the update proof's Composer artifacts (plan Task 17): versioned zips of core/ and every
// packages/* — the `from` set (1.0.0-beta.900), the `target` set (901), a `newer` set (902) — and
// target variants that fail in controlled ways. Each mutation is a str_replace on a named anchor;
// a missing anchor stops the build and names it, so a refactor breaks the proof loudly.
//
// Usage: php scripts/lib/update-proof/build-artifacts.php <out-dir>

$root = dirname(__DIR__, 3);
$out = $argv[1] ?? '';
if ($out === '') {
    fwrite(STDERR, "usage: build-artifacts.php <out-dir>\n");
    exit(2);
}
@mkdir($out, 0777, true);
require __DIR__ . '/artifacts-lib.php';

$FROM = '1.0.0-beta.900';
$TARGET = '1.0.0-beta.901';
$NEWER = '1.0.0-beta.902';

$tmp = $out . '/tmp';
@mkdir($tmp, 0777, true);
if (!is_file($root . '/core/resources/admin/index.html')) {
    fwrite(STDERR, "update-proof: core/resources/admin holds no build (cd admin && pnpm run -s build)\n");
    exit(1);
}

// The packages, at each version: identical code, only the version differs (they require self.version).
foreach ([$FROM => 'pkgs-900', $TARGET => 'pkgs-901', $NEWER => 'pkgs-902'] as $version => $set) {
    @mkdir("{$out}/{$set}", 0777, true);
    foreach (glob($root . '/packages/*', GLOB_ONLYDIR) ?: [] as $pkg) {
        zipTree(versioned($pkg, $version, $tmp), "{$out}/{$set}/" . basename($pkg) . '.zip');
    }
}

// The target's changes: a migration, an admin-bundle change, and a provision step that writes stored
// data no migration records (a system flag, written just before the style compile).
$migration = <<<'PHP'
<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/** The update proof's target migration (scripts/lib/update-proof/build-artifacts.php). */
final class CreateUpdateProofTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('update_proof')) {
            return;
        }
        $schema->createTable('update_proof', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('update_proof');
    }

    public function getDescription(): string
    {
        return 'Update proof target table';
    }
}
PHP;
$styleAnchor = '        if ($this->container->has(StyleArtifactCompiler::class)) {';
$flagWrite = "        \$this->getService(\\Thallo\\Contracts\\Settings\\SystemChannel::class)"
    . "->put('update_proof.provisioned', '901');\n";
$fail = "        \$this->error('update-proof: provision fails after writing data');\n        return self::FAILURE;\n";

$provision = '/src/Setup/Console/ProvisionCommand.php';
$target = static function (
    string $tmp,
    string $version,
    bool $withMigration = true,
) use (
    $root,
    $migration,
    $styleAnchor,
    $flagWrite,
    $provision
): string {
    $dir = versioned($root . '/core', $version, $tmp);
    if ($withMigration) {
        file_put_contents($dir . '/database/migrations/900_CreateUpdateProofTable.php', $migration);
    }
    $marker = "\n<!-- update-proof " . substr($version, -3) . " -->\n";
    file_put_contents($dir . '/resources/admin/index.html', $marker, FILE_APPEND);
    mutate($dir . $provision, $styleAnchor, $flagWrite . $styleAnchor, 'provision style compile');
    return $dir;
};

@mkdir("{$out}/core", 0777, true);
zipTree(versioned($root . '/core', $FROM, $tmp), "{$out}/core/from.zip");
zipTree($target($tmp, $TARGET), "{$out}/core/target.zip");
zipTree($target($tmp, $NEWER), "{$out}/core/newer.zip");

$failmig = $target($tmp, $TARGET);
mutate($failmig . $provision, $flagWrite . $styleAnchor, $flagWrite . $fail . $styleAnchor, 'failmig');
zipTree($failmig, "{$out}/core/failmig.zip");

$faildata = $target($tmp, $TARGET, false);
mutate($faildata . $provision, $flagWrite . $styleAnchor, $flagWrite . $fail . $styleAnchor, 'faildata');
zipTree($faildata, "{$out}/core/faildata.zip");

$failclear = $target($tmp, $TARGET);
mutate(
    $failclear . '/src/Updates/Console/UpdateClearCachesCommand.php',
    "    protected function execute(InputInterface \$input, OutputInterface \$output): int\n    {\n",
    "    protected function execute(InputInterface \$input, OutputInterface \$output): int\n    {\n"
        . "        return self::FAILURE; // update-proof\n",
    'clear-caches execute',
);
zipTree($failclear, "{$out}/core/failclear.zip");

// A clear-caches that ignores SIGTERM and hangs: the runner's child timeout must still end it.
$slowclear = $target($tmp, $TARGET);
mutate(
    $slowclear . '/src/Updates/Console/UpdateClearCachesCommand.php',
    "    protected function execute(InputInterface \$input, OutputInterface \$output): int\n    {\n",
    "    protected function execute(InputInterface \$input, OutputInterface \$output): int\n    {\n"
        . "        pcntl_signal(SIGTERM, SIG_IGN); // update-proof\n        sleep(600);\n",
    'clear-caches execute (slow)',
);
zipTree($slowclear, "{$out}/core/slowclear.zip");

$missing = $target($tmp, $TARGET);
$requires = static fn (array $extra): Closure => static fn (array $j): array
    => array_replace_recursive($j, ['require' => $extra]);
editJson($missing . '/composer.json', $requires(['ext-proofmissing' => '*']));
zipTree($missing, "{$out}/core/missingext.zip");

// A plugin under the allowlisted name that rewrites the project's composer.json when it activates.
$plugin = $tmp . '/fake-discovery';
@mkdir($plugin . '/src', 0777, true);
file_put_contents($plugin . '/composer.json', json_encode([
    'name' => 'php-http/discovery',
    'version' => '1.99.0',
    'type' => 'composer-plugin',
    'require' => ['composer-plugin-api' => '^2.0'],
    'autoload' => ['psr-4' => ['UpdateProof\\Discovery\\' => 'src/']],
    'extra' => ['class' => 'UpdateProof\\Discovery\\Rewriter'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($plugin . '/src/Rewriter.php', <<<'PHP'
<?php

namespace UpdateProof\Discovery;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

final class Rewriter implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $file = getcwd() . '/composer.json';
        $json = json_decode((string) file_get_contents($file), true);
        $json['extra']['rewritten-by-update-proof'] = true;
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }
}
PHP);
@mkdir("{$out}/plugin", 0777, true);
zipTree($plugin, "{$out}/plugin/php-http-discovery.zip");
$rewrite = $target($tmp, $TARGET);
editJson($rewrite . '/composer.json', $requires(['php-http/discovery' => '1.99.0']));
zipTree($rewrite, "{$out}/core/pluginrewrite.zip");

exec('rm -rf ' . escapeshellarg($tmp));
foreach (glob($out . '/core/*.zip') ?: [] as $zip) {
    echo basename($zip), "\n";
}
echo count(glob($out . '/pkgs-901/*.zip') ?: []), " packages per set\n";
