<?php
namespace App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
    public function registerBundles(): iterable { yield new FrameworkBundle(); yield new TwigBundle(); }
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $test = $this->environment === 'test';
        $container->parameters()->set('env(TRUSTED_PROXIES)','');
        $container->parameters()->set('env(APP_DATA_DIR)', $this->getProjectDir().'/var/data');
        $container->extension('framework', [
            'secret' => getenv('APP_SECRET') ?: 'local-development-only', 'test' => $test,
            'router' => ['utf8' => true], 'csrf_protection' => true, 'form' => true,
            'validation' => ['enable_attributes' => true], 'handle_all_throwables' => true,
            'trusted_proxies'=>'%env(TRUSTED_PROXIES)%', 'trusted_headers'=>['x-forwarded-for','x-forwarded-proto','x-forwarded-port'],
            'session' => ['storage_factory_id' => $test ? 'session.storage.factory.mock_file' : 'session.storage.factory.native',
                'cookie_httponly' => true, 'cookie_samesite' => 'lax', 'cookie_secure' => $this->environment === 'prod' ? true : 'auto', 'cookie_lifetime' => 86400,
                'save_path' => '%env(APP_DATA_DIR)%/sessions'],
        ]);
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates', 'form_themes' => ['form_div_layout.html.twig']]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', '../src/')->exclude('../src/{Kernel.php,Video/VideoInput.php}');
        $services->set(\App\Workspace\WorkspaceStore::class)->arg('$dataDir', '%env(APP_DATA_DIR)%');
        $services->set(\App\Video\AssetService::class)->arg('$samplePath', $this->getProjectDir().'/var/sample/welcome.mp4');
    }
    protected function configureRoutes(RoutingConfigurator $routes): void { $routes->import('../src/Controller/', 'attribute'); }
}
