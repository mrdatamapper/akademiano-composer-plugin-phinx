<?php

namespace Akademiano\Composer\Phinx;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Plugin\CommandEvent;
use Composer\EventDispatcher\Event;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** @var  Composer */
    protected $composer;
    /** @var  IOInterface */
    protected $io;
    /** @var  [] */
    protected $config;

    public static function getSubscribedEvents()
    {
        printf('Phinx migrations processor start');
        $result = array(
            ScriptEvents::POST_INSTALL_CMD => array(
                array( 'onPostInstallCmd', 0 )
            ),
            ScriptEvents::POST_UPDATE_CMD  => array(
                array( 'onPostUpdateCmd', 0 )
            ),
        );
        return $result;
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        printf('Phinx migrations processor start');
        $this->setComposer($composer);
        $this->setIo($io);
        $config = $composer->getPackage()->getConfig();
        $this->setConfig($config);
    }

    /**
     * @return Composer
     */
    public function getComposer()
    {
        return $this->composer;
    }

    /**
     * @param Composer $composer
     */
    public function setComposer(Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     * @return IOInterface
     */
    public function getIo()
    {
        return $this->io;
    }

    /**
     * @param IOInterface $io
     */
    public function setIo(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * @return mixed
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param mixed $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    public function onPostInstallCmd(CommandEvent $event) 
    {
        $this->processPackages($event);
    }

    public function onPostUpdateCmd(CommandEvent $event) 
    {
        $this->processPackages($event);
    }

    public function processPackages(Event $event)
    {
        printf('Phinx migrations processor start');
        $composer = $this->getComposer();
        $installationManager = $composer->getInstallationManager();
        $vendorPath = $composer->getConfig()->get('vendor-dir');
        $rootPath = dirname($vendorPath);
        $repositoryManager = $composer->getRepositoryManager();
        $repository = $repositoryManager->getLocalRepository();
        /** @var Package[] $packages */
        $packages = $repository->getPackages();
        foreach ($packages as $package) {
            $pathPackage = $installationManager->getInstallPath($package);
            $pathClasses = $this->getPackagePatch($package);
            foreach ($pathClasses as $path) {
                $path = $pathPackage . "/" . $path;
                $className = basename($path);
                echo "process package {$pathPackage} : {$className} \n";
                $this->tryAddMigration($path, $rootPath);
            }
        }
        $this->processModules($rootPath);
    }


    public  function getPackagePatch(PackageInterface $package)
    {
        $autoload = $package->getAutoload();
        $patchArr = [];
        foreach ($autoload as $type => $clItems) {
            foreach ($clItems as $class => $path) {
                if ($type === "psr-0") {
                    $patchArr[] = $path . $class;
                } elseif ($type === "psr-4") {
                    $patchArr[] = $path;
                }
            }
        }
        array_unique($patchArr);
        return $patchArr;
    }

    public function tryAddMigration($path, $rootPath)
    {
        $mgrPackagePath = $path . "/db/migrations";
        if (!file_exists($mgrPackagePath)) {
//            echo EscClr::fg("dark_gray", "not exist $mgrPackagePath") . "\n";
            return;
        }
        $migrationsDir = $rootPath . "/db/migrations";
        $migrations = array_diff(scandir($mgrPackagePath), array('..', '.'));
        foreach ($migrations as $file) {
            if (strpos($file, "_mysql.php") !== false) {
                continue;
            }
            $filePath = $mgrPackagePath . '/' . $file;
            $dist = $migrationsDir . "/" . $file;
            if (!file_exists($dist)) {
                copy($filePath, $dist);
                echo EscClr::fg("green", "install migration $file") . "\n";
            }
        }
    }

    public function processModules($rootPath)
    {
        $modDir = $rootPath . "/modules";
        if (!file_exists($modDir)) {
            return;
        }
        $modules = array_diff(scandir($modDir), array('..', '.'));
        foreach($modules as $moduleDir) {
            $moduleName = basename($moduleDir);
            echo "process module $moduleName \n";
            $this->tryAddMigration($modDir . "/" .$moduleDir, $rootPath);
        }
    }

}
