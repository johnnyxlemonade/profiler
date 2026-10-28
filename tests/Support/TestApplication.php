<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests\Support;

use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Core\Context\ApplicationContext;
use Lemonade\Framework\Core\Context\DebugMode;
use Lemonade\Framework\Core\Context\Environment;
use Lemonade\Framework\Core\Context\Path;
use Lemonade\Framework\Core\Kernel;
use Lemonade\Framework\Core\KernelFactory;
use Lemonade\Framework\Filesystem\Manager\DirectoryManager;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\ProfilerServiceProvider;

final class TestApplication
{
    public readonly string $root;
    public readonly Kernel $kernel;
    public readonly Container $container;

    public function __construct(
        Environment $environment = Environment::Development,
        ?ProfilerConfig $config = null,
        bool $debug = false,
        bool $earlyObserver = false,
    ) {
        $this->root = sys_get_temp_dir() . '/lemonade-profiler-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/app/Config', 0700, true);
        mkdir($this->root . '/app/Views', 0700, true);
        mkdir($this->root . '/public', 0700, true);
        file_put_contents($this->root . '/app/Config/Config.yaml', "shared:\n  - Database.php\n  - View.php\n  - Providers\n");
        file_put_contents($this->root . '/app/Config/Database.php', <<<'PHP'
<?php
return \Lemonade\Framework\Database\Config\DatabaseConfigDefinition::create()->defaultConnection('default')->connection('default', ['driver'=>'pdo', 'dialect'=>'sqlite', 'dsn'=>'sqlite::memory:']);
PHP);
        file_put_contents($this->root . '/app/Config/View.php', <<<'PHP'
<?php
return \Lemonade\Framework\View\Config\ViewConfigDefinition::create()->basePath('app/Views');
PHP);
        file_put_contents($this->root . '/app/Config/Providers.yaml', <<<'YAML'
module: providers
config:
  providers:
    - Lemonade\Profiler\ProfilerServiceProvider
YAML);
        file_put_contents($this->root . '/app/Config/Routing.php', <<<'PHP'
<?php
return static function (\Lemonade\Framework\Routing\Router $router): void {
    $router->getNamed('fixture.page', '/page/{id}', \Lemonade\Framework\Routing\ControllerAction::for(\Lemonade\Profiler\Tests\Support\TestController::class, 'page'));
    $router->getNamed('fixture.error', '/error', \Lemonade\Framework\Routing\ControllerAction::for(\Lemonade\Profiler\Tests\Support\TestController::class, 'error'));
};
PHP);
        file_put_contents($this->root . '/app/Views/page.php', '<!doctype html><html><head><title>Test page</title></head><body><?php echo $this->partial("partial"); ?></body></html>');
        file_put_contents($this->root . '/app/Views/partial.php', '<p>Public content</p>');
        $this->container = new Container();
        $context = new ApplicationContext($environment, new Path($this->root), new DebugMode($debug));
        $this->kernel = (new KernelFactory(
            $this->container,
            diagnostics: $earlyObserver ? new ProfilerServiceProvider($context) : null,
        ))->create($context);
        if ($config !== null) {
            $this->container->singleton(ProfilerConfig::class, $config);
        }
    }

    public function close(): void
    {
        (new DirectoryManager())->delete($this->root);
    }
}
