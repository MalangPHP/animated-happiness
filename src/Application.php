<?php


namespace Singo;

use Dflydev\Provider\DoctrineOrm\DoctrineOrmServiceProvider;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Cache\Cache;
use Monolog\Logger;
use Saxulum\DoctrineMongoDb\Provider\DoctrineMongoDbProvider;
use Saxulum\DoctrineMongoDbOdm\Provider\DoctrineMongoDbOdmProvider;
use Silex\Provider\FractalServiceProvider;
use Silex\Provider\TacticianServiceProvider;
use Singo\Bus\Middleware\CommandLoggerMiddleware;
use Singo\Bus\Middleware\CommandValidationMiddleware;
use Singo\Event\Listener\ExceptionHandler;
use Silex\Application as SilexApplication;
use Silex\Provider\SecurityJWTServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\MonologServiceProvider;
use Silex\Provider\ServiceControllerServiceProvider;
use Silex\Provider\SwiftmailerServiceProvider;
use Silex\Provider\ValidatorServiceProvider;
use Silex\Provider\ConfigServiceProvider;
use Silex\Provider\PimpleAwareEventDispatcherServiceProvider;
use Symfony\Component\Validator\Mapping\Cache\DoctrineCache;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AnnotationLoader;

/**
 * Class Application
 * @package Singo
 */
class Application extends SilexApplication
{
    /**
     * @var Application
     */
    public static $container;

    /**
     * @param array $values
     */
    public function __construct(array $values = [])
    {
        parent::__construct($values);

        if (! defined("APP_PATH")) {
            define("APP_PATH", $this["app.path"]);
        }

        if (! defined("PUBLIC_PATH")) {
            define("PUBLIC_PATH", $this["app.public.path"]);
        }
    }

    /**
     * initialize our application
     */
    public function init()
    {
        $this->initPimpleAwareEventDispatcher();
        $this->initConfig();
        $this->initLogger();
        $this->initDatabase();
        $this->initValidator();
        $this->initMailer();
        $this->initFirewall();
        $this->initCommandBus();
        $this->initFractal();
        $this->initDefaultSubscribers();
        $this->initControllerService();

        /**
         * Silex config
         */
        $this["debug"] = $this["config"]->get("common/debug");

        /**
         * Save container in static variable
         */
        self::$container = $this;
    }

    /**
     * return void
     */
    public function initPimpleAwareEventDispatcher()
    {
        $this->register(new PimpleAwareEventDispatcherServiceProvider());
    }

    /**
     * initialize application configuration
     * return void
     */
    public function initConfig()
    {
        $this->register(new ConfigServiceProvider($this["config.path"]));
    }

    /**
     * initialize doctrine orm and dbal
     * return void
     */
    public function initDatabase()
    {
        if (extension_loaded("mongo")
            && ! empty($this["config"]->get("database/connection/odm"))
            && PHP_VERSION_ID < 70000
            && ! defined("HHVM_VERSION")
        ) {
            $this->register(
                new DoctrineMongoDbProvider,
                [
                    "mongodb.options" => $this["config"]->get("database/connection/odm")
                ]
            );

            $this->register(
                new DoctrineMongoDbOdmProvider,
                [
                    "mongodbodm.proxies_dir" => APP_PATH. $this["config"]->get("database/odm/proxies_dir"),
                    "mongodbodm.proxies_namespace" => $this["config"]->get("database/odm/proxies_namespace"),
                    "mongodbodm.auto_generate_proxies" => $this["config"]->get("database/odm/auto_generate_proxies"),
                    "mongodbodm.dms.options" => $this["config"]->get("database/connection/odm")
                ]
            );
        }

        $this->register(
            new DoctrineServiceProvider,
            [
                "dbs.options" => $this["config"]->get("database/connection/orm")
            ]
        );
        $this->register(
            new DoctrineOrmServiceProvider(),
            [
                "orm.proxies_dir" => APP_PATH. $this["config"]->get("database/orm/proxies_dir"),
                "orm.proxies_namespace" => $this["config"]->get("database/orm/proxies_namespace"),
                "orm.ems.options" => $this["config"]->get("database/ems")
            ]
        );
    }

    /**
     * initialize logger
     * return void
     */
    public function initLogger()
    {
        $date = new \DateTime();
        $log_file = APP_PATH . $this["config"]->get("common/log/dir") . "/{$date->format("Y-m-d")}.log";
        $this->register(
            new MonologServiceProvider(),
            [
                "monolog.logfile" => $log_file,
                "monolog.name" => $this["config"]->get("common/log/name"),
                "monolog.level" => Logger::INFO
            ]
        );
    }

    /**
     * initialize validator
     * return void
     */
    public function initValidator()
    {
        $this->register(new ValidatorServiceProvider());
        $this["validator.mapping.class_metadata_factory"] = function () {
            $reader = new AnnotationReader();
            $loader = new AnnotationLoader($reader);

            $cache = $this->offsetExists("cache.factory") && $this["cache.factory"] instanceof Cache
                ? new DoctrineCache($this["cache.factory"]) : null;

            return new LazyLoadingMetadataFactory($loader, $cache);
        };
    }

    /**
     * initialize mailer
     * return void
     */
    public function initMailer()
    {
        $this["swiftmailer.options"] = $this["config"]->get("mailer");
        $this->register(new SwiftmailerServiceProvider());
    }

    /**
     * initialize command bus
     * return void
     */
    public function initCommandBus()
    {
        $this->register(new TacticianServiceProvider());
    }

    /**
     * initialize controller as a service
     * return void
     */
    public function initControllerService()
    {
        $this->register(
            new ServiceControllerServiceProvider(),
            [
                "tactician.inflector" => "class_name",
                "tactician.middlewares" =>
                [
                    new CommandLoggerMiddleware($this["monolog"]),
                    new CommandValidationMiddleware($this["validator"], $this["monolog"])
                ]
            ]
        );
    }

    /**
     * initialize array processor to json
     * return void
     */
    public function initFractal()
    {
        $this->register(new FractalServiceProvider());
    }

    /**
     * initialize default event subscriber
     * return void
     */
    public function initDefaultSubscribers()
    {
        $this->registerSubscriber(
            ExceptionHandler::class,
            function () {
                return new ExceptionHandler($this);
            }
        );
    }

    /**
     * initialize web application firewall
     * return void
     */
    public function initFirewall()
    {
        if (! isset($this["users"])) {
            throw new \RuntimeException("users must be set in container");
        }

        $this["security.jwt"] = $this["config"]->get("jwt");
        $this->register(new SecurityJWTServiceProvider());
        $this->register(new SecurityServiceProvider());
        $this["security.firewalls"] = $this["config"]->get("firewall");
    }

    /**
     * register command for our application
     * @param array $commands
     * @param callable $handler
     */
    public function registerCommands(array $commands, callable $handler)
    {
        foreach ($commands as $command) {
            $handler_id = "app.handler." . join('', array_slice(explode("\\", $command), -1));
            $this[$handler_id] = $handler;
        }
    }

    /**
     * @param string $class
     * @param callable $callback
     */
    public function registerSubscriber($class, callable $callback)
    {
        $service_id = "event." . strtolower(str_replace("\\", ".", $class));

        $this[$service_id] = $callback;

        $this["dispatcher"]->addSubscriberService($service_id, $class);
    }
}
