<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2019 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

namespace VanillaTests;

use Garden\Container\Container;
use Garden\Container\Reference;
use Garden\Web\RequestInterface;
use Gdn;
use Nette\Loaders\RobotLoader;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use TagModule;
use Vanilla\Addon;
use Vanilla\AddonManager;
use Vanilla\Authenticator\PasswordAuthenticator;
use Vanilla\Cache\CacheCacheAdapter;
use Vanilla\Community\CallToActionModule;
use Vanilla\Community\CategoriesModule;
use Vanilla\Contracts\Addons\EventListenerConfigInterface;
use Vanilla\Contracts\ConfigurationInterface;
use Vanilla\Contracts\LocaleInterface;
use Vanilla\Contracts\Site\AbstractSiteProvider;
use Vanilla\Contracts\Site\SiteSectionProviderInterface;
use Vanilla\Contracts\Web\UASnifferInterface;
use Vanilla\Dashboard\Controllers\API\ConfigApiController;
use Vanilla\Formatting\FormatService;
use Vanilla\Forum\Navigation\ForumBreadcrumbProvider;
use Vanilla\HttpCacheMiddleware;
use Vanilla\InjectableInterface;
use Vanilla\Models\AuthenticatorModel;
use Vanilla\Models\SSOModel;
use Vanilla\Navigation\BreadcrumbModel;
use Vanilla\OpenAPIBuilder;
use Vanilla\Permissions;
use Vanilla\SchemaFactory;
use Vanilla\Search\GlobalSearchType;
use Vanilla\Search\SearchService;
use Vanilla\Search\SearchTypeCollectorInterface;
use Vanilla\Site\OwnSiteProvider;
use Vanilla\Site\SiteSectionModel;
use Vanilla\Theme\FsThemeProvider;
use Vanilla\Web\Middleware\LogTransactionMiddleware;
use Vanilla\Web\SystemTokenUtils;
use Vanilla\Web\TwigEnhancer;
use Vanilla\Web\TwigRenderer;
use Vanilla\Web\UASniffer;
use Vanilla\Theme\ThemeFeatures;
use Vanilla\Widgets\WidgetService;
use VanillaTests\APIv0\TestDispatcher;
use VanillaTests\Fixtures\Authenticator\MockAuthenticator;
use VanillaTests\Fixtures\Authenticator\MockSSOAuthenticator;
use VanillaTests\Fixtures\MockEmail;
use VanillaTests\Fixtures\MockWidgets\MockWidget1;
use VanillaTests\Fixtures\MockWidgets\MockWidget2;
use VanillaTests\Fixtures\MockWidgets\MockWidget3;
use VanillaTests\Fixtures\NullCache;
use Vanilla\Utility\ContainerUtils;
use VanillaTests\Fixtures\MockSiteSectionProvider;
use VanillaTests\Fixtures\SpyingEventManager;

/**
 * Run bootstrap code for Vanilla tests.
 *
 * This class is meant to be re-used. Calling {@link Bootstrap::run()} on a polluted environment should reset it.
 */
class Bootstrap {
    /** @deprecated */
    public const ROLE_ADMIN = VanillaTestCase::ROLE_ADMIN;
    /** @deprecated */
    public const ROLE_MOD = VanillaTestCase::ROLE_MOD;
    /** @deprecated */
    public const ROLE_MEMBER = VanillaTestCase::ROLE_MEMBER;

    private $baseUrl;

    /**
     * @var TestDispatcher
     */
    protected $dispatcher;

    /**
     * Bootstrap constructor.
     *
     * A different base URL affects
     *
     * @param string $baseUrl The base URL of the installation.
     */
    public function __construct($baseUrl) {
        $this->baseUrl = str_replace('\\', '/', $baseUrl);
        if (!defined('CLIENT_NAME')) {
            define('CLIENT_NAME', 'vanilla');
        }
    }

    /**
     * Run the bootstrap and set the global environment.
     *
     * @param Container $container The container to bootstrap.
     * @param bool $addons
     */
    public function run(Container $container, $addons = false) {
        $this->initialize($container);

        $this->dispatcher = $container->get(TestDispatcher::class);

        if ($addons) {
            $this->initializeAddons($container);
        }
        $this->setGlobals($container);
        \Logger::setLogger(null);
    }

    /**
     * Initialize the container with Vanilla's environment.
     *
     * @param Container $container The container to initialize.
     */
    public function initialize(Container $container) {
        // Set up the dependency injection container.
        Gdn::setContainer($container);

        touchFolder(PATH_ROOT.'/tests/cache/bootstrap');

        \Vanilla\Bootstrap::configureContainer($container);

        $container
            ->setInstance(Container::class, $container)
            ->setInstance('@baseUrl', $this->getBaseUrl())

            ->rule(\Vanilla\Web\Asset\DeploymentCacheBuster::class)
            ->setShared(true)
            ->setConstructorArgs([
                'deploymentTime' => ContainerUtils::config('Garden.Deployed')
            ])

            // Cache
            ->setInstance(NullCache::class, new NullCache())

            ->rule(\Gdn_Cache::class)
            ->setAliasOf(NullCache::class)
            ->addAlias('Cache')

            // Configuration
            ->rule(ConfigurationInterface::class)
            ->setClass(\Gdn_Configuration::class)
            ->setShared(true)
            ->addCall('defaultPath', [$this->getConfigPath()])
            ->addCall('autoSave', [false])
            ->addCall('load', [PATH_ROOT.'/conf/config-defaults.php'])
            ->addAlias('Config')
            ->addAlias(\Gdn_Configuration::class)

            ->rule(AbstractSiteProvider::class)
            ->setClass(OwnSiteProvider::class)

            ->rule(SiteSectionProviderInterface::class)
            ->setFactory(function () {
                return MockSiteSectionProvider::fromLocales();
            })
            ->setShared(true)

            // Site sections
            ->rule(SiteSectionModel::class)
            ->addCall('addProvider', [new Reference(SiteSectionProviderInterface::class)])
            ->setShared(true)

            // Translation model
            ->rule(\Vanilla\Site\TranslationModel::class)
            ->addCall('addProvider', [new Reference(\Vanilla\Site\TranslationProvider::class)])
            ->setShared(true)

            // Site applications
            ->rule(\Vanilla\Contracts\Site\ApplicationProviderInterface::class)
            ->setClass(\Vanilla\Site\ApplicationProvider::class)
            ->addCall('add', [new Reference(
                \Vanilla\Site\Application::class,
                ['garden', ['api', 'entry', 'sso', 'utility', 'robots.txt', 'robots']]
            )])
            ->setShared(true)

            // AddonManager
            ->rule(AddonManager::class)
            ->setShared(true)
            ->setConstructorArgs([
                [
                    Addon::TYPE_ADDON => ['/addons/addons', '/applications', '/plugins'],
                    Addon::TYPE_THEME => ['/addons/themes', '/themes'],
                    Addon::TYPE_LOCALE => '/locales'
                ],
                PATH_ROOT.'/tests/cache/bootstrap'
            ])
            ->addAlias('AddonManager')
            ->addCall('registerAutoloader')

            ->rule(ThemeFeatures::class)
            ->setConstructorArgs(['theme' => ContainerUtils::currentTheme()])

            // ApplicationManager
            ->rule(\Gdn_ApplicationManager::class)
            ->setShared(true)
            ->addAlias('ApplicationManager')

            // PluginManager
            ->rule(\Gdn_PluginManager::class)
            ->setShared(true)
            ->addAlias('PluginManager')

            // ThemeManager
            ->rule(\Gdn_ThemeManager::class)
            ->setShared(true)
            ->addAlias('ThemeManager')

            // Logger
            ->rule(\Vanilla\Logger::class)
            ->setShared(true)
            ->addAlias(LoggerInterface::class)
            ->addCall('addLogger', [new Reference(TestLogger::class)])

            ->rule(TestLogger::class)
            ->setShared(true)

            ->rule(LoggerAwareInterface::class)
            ->addCall('setLogger')

            ->rule(HttpCacheMiddleware::class)
            ->setShared(true)

            // EventManager
            ->rule(\Garden\EventManager::class)
            ->setClass(SpyingEventManager::class)
            ->addAlias(EventListenerConfigInterface::class)
            ->addAlias(EventDispatcherInterface::class)
            ->addAlias(ListenerProviderInterface::class)
            ->addCall("addListenerMethod", [\Vanilla\Logging\ResourceEventLogger::class, "logResourceEvent"])
            ->setShared(true)

            ->rule(\Vanilla\Logging\ResourceEventLogger::class)
            ->addCall("includeAction", [
                \Vanilla\Dashboard\Events\UserEvent::class,
                '*',
            ])
            ->setShared(true)

            ->rule(\Gdn_Request::class)
            ->setShared(true)
            ->addAlias('Request')
            ->addAlias(RequestInterface::class)

            ->rule(UASnifferInterface::class)
            ->setClass(UASniffer::class)

            // Database.
            ->rule('Gdn_Database')
            ->setShared(true)
            ->setConstructorArgs([self::testDbConfig()])
            ->addAlias('Database')

            ->rule(\Gdn_DatabaseStructure::class)
            ->setClass(\Gdn_MySQLStructure::class)
            ->setShared(true)
            ->addAlias(Gdn::AliasDatabaseStructure)
            ->addAlias('MySQLStructure')

            ->rule(\Gdn_SQLDriver::class)
            ->setClass(\Gdn_MySQLDriver::class)
            ->setShared(true)
            ->addAlias('Gdn_MySQLDriver')
            ->addAlias('MySQLDriver')
            ->addAlias(Gdn::AliasSqlDriver)

            ->rule(\Vanilla\Contracts\Models\UserProviderInterface::class)
            ->setClass(\UserModel::class)

            // Locale
            ->rule(\Gdn_Locale::class)
            ->setShared(true)
            ->setConstructorArgs([new Reference(['Gdn_Configuration', 'Garden.Locale'])])
            ->addAlias(Gdn::AliasLocale)
            ->addAlias(LocaleInterface::class)

            ->rule(\Garden\Web\Cookie::class)
            ->setShared(true)
            ->addCall('setPrefix', [ContainerUtils::config('Garden.Cookie.Name', 'Vanilla')])
            ->addAlias('Cookie')

            ->rule('Identity')
            ->setClass('Gdn_CookieIdentity')
            ->setShared(true)

            ->rule(\Gdn_Session::class)
            ->setShared(true)
            ->addAlias('Session')

            ->rule(Gdn::AliasAuthenticator)
            ->setClass(\Gdn_Auth::class)
            ->setShared(true)

            ->rule(\Gdn_Router::class)
            ->addAlias(Gdn::AliasRouter)
            ->setShared(true)

            ->rule(\Gdn_Dispatcher::class)
            ->setShared(true)
            ->addAlias(Gdn::AliasDispatcher)

            ->rule(\Gdn_Validation::class)
            ->addCall('addRule', ['BodyFormat', new Reference(\Vanilla\BodyFormatValidator::class)])

            ->rule(AuthenticatorModel::class)
            ->setShared(true)
            ->addCall('registerAuthenticatorClass', [PasswordAuthenticator::class])
            ->addCall('registerAuthenticatorClass', [MockAuthenticator::class])
            ->addCall('registerAuthenticatorClass', [MockSSOAuthenticator::class])
            ->rule(SearchService::class)
            ->addCall('registerActiveDriver', [new Reference(\Vanilla\Search\MysqlSearchDriver::class)])
            ->rule(MockEmail::class)
            ->addAlias(\Gdn_Email::class)

            ->rule(SearchTypeCollectorInterface::class)
            ->addCall('registerSearchType', [new Reference(GlobalSearchType::class)])

            // File base theme api provider
            ->rule(\Vanilla\Theme\ThemeService::class)
            ->addCall("addThemeProvider", [new Reference(FsThemeProvider::class)])

            ->rule(SSOModel::class)
            ->setShared(true)

            ->rule(\Garden\Web\Dispatcher::class)
            ->setShared(true)
            ->addCall('addRoute', ['route' => new \Garden\Container\Reference('@api-v2-route'), 'api-v2'])
            ->addCall('addMiddleware', [new Reference(\Vanilla\Web\PrivateCommunityMiddleware::class)])
            ->addCall('addMiddleware', [new Reference(LogTransactionMiddleware::class)])
            ->addCall('addMiddleware', [new Reference('@smart-id-middleware')])
            ->addCall('addMiddleware', [new Reference(\Vanilla\Web\APIExpandMiddleware::class)])

            ->rule(LogTransactionMiddleware::class)
            ->setShared(true)

            ->rule('@smart-id-middleware')
            ->setClass(\Vanilla\Web\SmartIDMiddleware::class)
            ->setShared(true)
            ->setConstructorArgs(['/api/v2/'])
            ->addCall('addSmartID', ['CategoryID', 'categories', ['name', 'urlcode'], 'Category'])
            ->addCall('addSmartID', ['RoleID', 'roles', ['name'], 'Role'])
            ->addCall('addSmartID', ['UserID', 'users', '*', new Reference('@user-smart-id-resolver')])

            ->rule('@user-smart-id-resolver')
            ->setFactory(function (Container $dic) {
                /* @var \Vanilla\Web\UserSmartIDResolver $uid */
                $uid = $dic->get(\Vanilla\Web\UserSmartIDResolver::class);
                $uid->setEmailEnabled(!$dic->get(\Gdn_Configuration::class)->get('Garden.Registration.NoEmail'))
                    ->setViewEmail($dic->get(\Gdn_Session::class)->checkPermission('Garden.PersonalInfo.View'));

                return $uid;
            })

            ->rule(\Vanilla\Web\HttpStrictTransportSecurityModel::class)
            ->addAlias('HstsModel')

            ->rule('@api-v2-route')
            ->setClass(\Garden\Web\ResourceRoute::class)
            ->setConstructorArgs(['/api/v2/', '*\\%sApiController'])
            ->addCall('setConstraint', ['locale', ['position' => 0]])
            ->addCall('setMeta', ['CONTENT_TYPE', 'application/json; charset=utf-8'])
            ->addCall('addMiddleware', [new Reference(\Vanilla\Web\ApiFilterMiddleware::class)])

            ->rule(\Vanilla\Web\PrivateCommunityMiddleware::class)
            ->setShared(true)
            ->setConstructorArgs([ContainerUtils::config('Garden.PrivateCommunity')])

            ->rule(\Vanilla\Web\APIExpandMiddleware::class)
            ->setConstructorArgs([
                "/api/v2/",
                ContainerUtils::config("Garden.api.ssoIDPermission", Permissions::RANK_COMMUNITY_MANAGER)
            ])
            ->setShared(true)

            ->rule('@view-application/json')
            ->setClass(\Vanilla\Web\JsonView::class)
            ->setShared(true)

            ->rule(\Garden\ClassLocator::class)
            ->setClass(\Vanilla\VanillaClassLocator::class)

            ->rule(\Gdn_Plugin::class)
            ->setShared(true)
            ->addCall('setAddonFromManager')

            ->rule(\Vanilla\FileUtils::class)
            ->setAliasOf(\VanillaTests\Fixtures\FileUtils::class)
            ->addAlias('FileUtils')

            ->rule('WebLinking')
            ->setClass(\Vanilla\Web\Pagination\WebLinking::class)
            ->setShared(true)

            ->rule('ViewHandler.tpl')
            ->setClass('Gdn_Smarty')
            ->setShared(true)

            ->rule('ViewHandler.php')
            ->setShared(true)

            ->rule('ViewHandler.twig')
            ->setClass(\Vanilla\Web\LegacyTwigViewHandler::class)
            ->setShared(true)

            ->rule(TwigRenderer::class)
            ->setShared(true)

            ->rule(TwigEnhancer::class)
            ->addCall('setCompileCacheDirectory', [PATH_CACHE . '/twig'])
            ->setShared(true)

            ->rule(\Vanilla\EmbeddedContent\EmbedService::class)
            ->setShared(true)

            ->rule(\Vanilla\PageScraper::class)
            ->addCall('registerMetadataParser', [new Reference(\Vanilla\Metadata\Parser\OpenGraphParser::class)])
            ->addCall('registerMetadataParser', [new Reference(\Vanilla\Metadata\Parser\JsonLDParser::class)])
            ->setShared(true)

            ->rule(BreadcrumbModel::class)
            ->addCall('addProvider', [new Reference(ForumBreadcrumbProvider::class)])

            ->rule(\Vanilla\Formatting\Quill\Parser::class)
            ->addCall('addCoreBlotsAndFormats')
            ->setShared(true)

            ->rule(\Vanilla\Formatting\Quill\Renderer::class)
            ->setShared(true)

            ->rule('BBCodeFormatter')
            ->setClass(\BBCode::class)
            ->setShared(true)

            ->rule('HtmlFormatter')
            ->setClass(\VanillaHtmlFormatter::class)
            ->setShared(true)

            ->rule(FormatService::class)
            ->addCall('registerBuiltInFormats')
            ->setShared(true)

            ->rule('HtmlFormatter')
            ->setClass(\VanillaHtmlFormatter::class)
            ->setShared(true)

            ->rule(\Vanilla\Scheduler\SchedulerInterface::class)
            ->setClass(\VanillaTests\Fixtures\Scheduler\InstantScheduler::class)
            ->addCall('addDriver', [\Vanilla\Scheduler\Driver\LocalDriver::class])
            ->addCall('setDispatchEventName', ['SchedulerDispatch'])
            ->addCall('setDispatchedEventName', ['SchedulerDispatched'])
            ->setShared(true)

            ->rule(\Vanilla\Web\APIExpandMiddleware::class)
            ->setConstructorArgs([
                "/api/v2/",
                ContainerUtils::config("Garden.api.ssoIDPermission", Permissions::RANK_COMMUNITY_MANAGER)
            ])

            ->rule(\Gdn_Form::class)
            ->addAlias('Form')

            ->rule(WidgetService::class)
            ->addCall('registerWidget', [MockWidget1::class])
            ->addCall('registerWidget', [MockWidget2::class])
            ->addCall('registerWidget', [MockWidget3::class])
            ->addCall('registerWidget', [CategoriesModule::class])
            ->addCall('registerWidget', [TagModule::class])
            ->addCall('registerWidget', [CallToActionModule::class])

            ->rule(SystemTokenUtils::class)
            ->setConstructorArgs([
                ContainerUtils::config("Context.Secret", "secret")
            ])
        ;

        $container
            ->rule(OpenAPIBuilder::class)
            ->setConstructorArgs(['cachePath' => PATH_ROOT.'/tests/cache/openapi.php'])
            ->rule(ConfigApiController::class)
            ->setConstructorArgs(['cachePath' => PATH_ROOT.'/tests/cache/config-schema.php'])
        ;
    }

    /**
     * Get the default database connection arguments.
     *
     * @return array
     */
    public static function testDbConfig(): array {
        $r = [
            'Host' => getenv('TEST_DB_HOST') ?: 'localhost',
            'Dbname' => getenv('TEST_DB_NAME'),
            'User' => getenv('TEST_DB_USER'),
            'Password' => getenv('TEST_DB_PASSWORD'),
        ];
        return $r;
    }

    private function initializeAddons(Container $dic) {
        // Run through the bootstrap with dependencies.
        $dic->call(function (
            Container $dic,
            \Gdn_Configuration $config,
            AddonManager $addonManager,
            \Garden\EventManager $eventManager
        ) {

            // Load installation-specific configuration so that we know what apps are enabled.
            $config->load($config->defaultPath(), 'Configuration', true);


            /**
             * Extension Managers
             *
             * Now load the Addon, Application, Theme and Plugin managers into the Factory, and
             * process the application-specific configuration defaults.
             */

            // Start the addons, plugins, and applications.
            $addonManager->startAddonsByKey($config->get('EnabledPlugins'), Addon::TYPE_ADDON);
            $addonManager->startAddonsByKey($config->get('EnabledApplications'), Addon::TYPE_ADDON);
            $addonManager->startAddonsByKey(array_keys($config->get('EnabledLocales', [])), Addon::TYPE_LOCALE);

//            $currentTheme = c('Garden.Theme', Gdn_ThemeManager::DEFAULT_DESKTOP_THEME);
//            if (isMobile()) {
//                $currentTheme = c('Garden.MobileTheme', Gdn_ThemeManager::DEFAULT_MOBILE_THEME);
//            }
//            $addonManager->startAddonsByKey([$currentTheme], Addon::TYPE_THEME);

            // Load the configurations for enabled addons.
            foreach ($addonManager->getEnabled() as $addon) {
                /* @var Addon $addon */
                if ($configPath = $addon->getSpecial('config')) {
                    $config->load($addon->path($configPath));
                }
            }

            // Re-apply loaded user settings.
            $config->overlayDynamic();

            /**
             * Extension Startup
             *
             * Allow installed addons to execute startup and bootstrap procedures that they may have, here.
             */

            // Bootstrapping.
            foreach ($addonManager->getEnabled() as $addon) {
                /* @var Addon $addon */
                if ($bootstrapPath = $addon->getSpecial('bootstrap')) {
                    $bootstrapPath = $addon->path($bootstrapPath);
                    include $bootstrapPath;
                }
            }

            // Plugins startup
            $addonManager->bindAllEvents($eventManager);

            if ($eventManager->hasHandler('gdn_pluginManager_afterStart')) {
                $eventManager->fire('gdn_pluginManager_afterStart', $dic->get(\Gdn_PluginManager::class));
            }

            // Now that all of the events have been bound, fire an event that allows plugins to modify the container.
            $eventManager->fire('container_init', $dic);

            // Start Authenticators
            $dic->get('Authenticator')->startAuthenticator();
        });
    }

    /**
     * Set the global variables that have dependencies.
     *
     * @param Container $container The container with dependencies.
     */
    public function setGlobals(Container $container) {
        // Set some server globals.
        $baseUrl = $this->getBaseUrl();

        $this->setServerGlobal('X_REWRITE', true);
        $this->setServerGlobal('REMOTE_ADDR', '1.2.3.4'); // Simulate a test IP address.
        $this->setServerGlobal('HTTP_HOST', parse_url($baseUrl, PHP_URL_HOST));
        $this->setServerGlobal('SERVER_NAME', parse_url($baseUrl, PHP_URL_HOST));
        $this->setServerGlobal('SERVER_PORT', parse_url($baseUrl, PHP_URL_PORT) ?: null);
        $this->setServerGlobal('SCRIPT_NAME', parse_url($baseUrl, PHP_URL_PATH));
        $this->setServerGlobal('PATH_INFO', '');
        $this->setServerGlobal('REQUEST_URI', '');
        $this->setServerGlobal('HTTPS', parse_url($baseUrl, PHP_URL_SCHEME) === 'https');

        $GLOBALS['dic'] = $container;
        Gdn::setContainer($container);
    }

    /**
     * Set a `$_SERVER` global variable and backup its previous value.
     *
     * @param string $key The key to set.
     * @param mixed $value The new value.
     * @return mixed Returns the previous value.
     */
    private function setServerGlobal(string $key, $value) {
        if (empty($_SERVER['__BAK'][$key]) && array_key_exists($key, $_SERVER)) {
            if (!array_key_exists('__BAK', $_SERVER)) {
                $_SERVER['__BAK'] = [];
            }

            $_SERVER['__BAK'][$key] = $_SERVER[$key];
        }
        $r = $_SERVER[$key] = $value;
        return $r;
    }

    /**
     * Clean up a container and remove its global references.
     *
     * @param Container $container The container to clean up.
     *
     * @throws \Garden\Container\ContainerException
     * @throws \Garden\Container\NotFoundException
     */
    public static function cleanup(Container $container) {
        self::cleanUpContainer($container);
        self::cleanUpGlobals();

        if (!empty($_SERVER['__BAK']) && is_array($_SERVER['__BAK'])) {
            foreach ($_SERVER['__BAK'] as $key => $value) {
                $_SERVER[$key] = $value;
            }
            unset($_SERVER['__BAK']);
        }
    }

    /**
     * Clean up container.
     *
     * @param \Garden\Container\Container $container
     *
     * @throws \Garden\Container\ContainerException
     * @throws \Garden\Container\NotFoundException
     */
    public static function cleanUpContainer(Container $container) {
       if ($container->hasInstance(AddonManager::class)) {
            /* @var AddonManager $addonManager */

            $addonManager = $container->get(AddonManager::class);
            $addonManager->unregisterAutoloader();
        }

        $container->clearInstances();
    }

    /**
     * Clean up global variables.
     */
    public static function cleanUpGlobals() {
        if (class_exists(\CategoryModel::class)) {
            \CategoryModel::$Categories = null;
        }

        SchemaFactory::setContainer(null);
        SchemaFactory::setEventManager(null);

        unset($GLOBALS['dic']);
        Gdn::setContainer(new NullContainer());
    }

    /**
     * Get the baseUrl.
     *
     * @return mixed Returns the baseUrl.
     */
    public function getBaseUrl() {
        return $this->baseUrl;
    }

    /**
     * Get the bath of the site's configuration file.
     *
     * @return string Returns a path.
     */
    public function getConfigPath() {
        $host = parse_url($this->getBaseUrl(), PHP_URL_HOST);
        $path = parse_url($this->getBaseUrl(), PHP_URL_PATH);
        if ($path) {
            $path = '-'.ltrim(str_replace('/', '-', $path), '-');
        }

        return PATH_ROOT."/conf/{$host}{$path}.php";
    }

    /**
     * Register an autoloader that loads all classes.
     */
    public static function registerAutoloader(): void {
        $loader = new RobotLoader();
        $loader->addDirectory(PATH_APPLICATIONS, PATH_PLUGINS);
        $loader->ignoreDirs[] = "vendor"; // Avoid loading any lurking Composer auto-loaders.

        $excluded = [
            'Mustache',
            'mustache',
            'sitehub/modules',
            'lithecompiler',
            'lithestyleguide',
            'NBBC',
            'Warnings',
            'NBBC',
            'CustomCSS',
            'Online',
            'infstub',
            'hosted-job/vendor',
            'cloudmonkey/vendor',
        ];
        foreach ($excluded as $subdir) {
            $loader->excludeDirectory(PATH_PLUGINS.'/'.$subdir);
        }


        // And set caching to the 'temp' directory
        $loader->setAutoRefresh(true);
        $loader->setTempDirectory(PATH_CACHE.'/tests/autoloader');
        $loader->register();
    }
}

