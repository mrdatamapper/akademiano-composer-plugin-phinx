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
    const PHINX_CONFIG_FILE = "phinx.yml";

    /** @var  Composer */
    protected $composer;
    /** @var  IOInterface */
    protected $io;
    /** @var  [] */
    protected $config;

    protected $rootDir;
    protected $vendorDir;

    public static function getSubscribedEvents()
    {
        $result = array(
            ScriptEvents::POST_INSTALL_CMD => array(
                array('onPostInstallCmd', 0)
            ),
            ScriptEvents::POST_UPDATE_CMD => array(
                array('onPostUpdateCmd', 0)
            ),
        );
        return $result;
    }

    public function activate(Composer $composer, IOInterface $io)
    {
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

    public function onPostInstallCmd(\Composer\Script\Event $event)
    {
        $this->prepareMigrations($event);
    }

    public function onPostUpdateCmd(\Composer\Script\Event $event)
    {
        $this->prepareMigrations($event);
    }

    public function getVendorDir()
    {
        if (null === $this->vendorDir) {
            $this->vendorDir = $this->getComposer()->getConfig()->get('vendor-dir');
        }
        return $this->vendorDir;
    }

    public function getRootDir()
    {
        if (null === $this->rootDir) {
            $this->rootDir = dirname($this->getVendorDir());
        }
        return $this->rootDir;
    }

    public function prepareMigrations(\Composer\Script\Event $event)
    {
        $this->processPackages($event);
        $this->processModules();
        $this->phinxInfo();
    }

    public function processPackages(\Composer\Script\Event $event)
    {
        printf('Phinx migrations processor start' . PHP_EOL);
        $composer = $this->getComposer();
        $installationManager = $composer->getInstallationManager();
        $repositoryManager = $composer->getRepositoryManager();
        $repository = $repositoryManager->getLocalRepository();
        /** @var Package[] $packages */
        $packages = $repository->getPackages();
        foreach ($packages as $package) {
            $pathPackage = $installationManager->getInstallPath($package);
            $pathClasses = $this->getPackagePatch($package);
            foreach ($pathClasses as $path) {
                $path = (array)$path;
                foreach ($path as $pathItem) {
                    $path = $pathPackage . "/" . $pathItem;
                    $className = basename($path);
                    printf('Process package "%s" : "%s".' . PHP_EOL, $pathPackage, $className);
                    $this->tryAddMigration($path, $this->getRootDir());
                }
            }
        }
    }

    public function getPackagePatch(PackageInterface $package)
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
                try {
                    copy($filePath, $dist);
                } catch (\Throwable $e) {
                    print(EscClr::fg("red", sprintf('Error "%s" in copy file from "%s" to "%s"' . PHP_EOL, $e->getMessage(), $filePath, $dist)));
                }
                echo EscClr::fg("green", "install migration $file") . "\n";
            }
        }
    }

    public function processModules()
    {
        $rootPath = $this->getRootDir();
        $modDir = $rootPath . "/modules";
        if (!file_exists($modDir)) {
            return;
        }
        $modules = array_diff(scandir($modDir), array('..', '.'));
        foreach ($modules as $moduleDir) {
            $moduleName = basename($moduleDir);
            echo "process module $moduleName \n";
            $this->tryAddMigration($modDir . "/" . $moduleDir, $rootPath);
        }
    }

    public function phinxInfo()
    {
        $rootDir = $this->getRootDir();
        $vendorDir = $this->getVendorDir();
        if (!file_exists($rootDir . DIRECTORY_SEPARATOR . self::PHINX_CONFIG_FILE)) {
            $return = null;
            $output = [];
            $command = $vendorDir . DIRECTORY_SEPARATOR . "robmorgan/phinx/bin/phinx init 2>&1";
            exec($command, $output, $return);
            if ($return !== 0) {
                print(EscClr::fg("red", sprintf('----------' .PHP_EOL.
                    'Execute : %s' . PHP_EOL . '%s', $command, implode(PHP_EOL, $output))));
            }
        }

        $return = null;
        $output = [];
        $command = $vendorDir . DIRECTORY_SEPARATOR . "robmorgan/phinx/bin/phinx test 2>&1";
        exec($command, $output, $return);
        if ($return !== 0) {
            print(EscClr::fg("red", sprintf('----------' .PHP_EOL.
                'Execute : %s' . PHP_EOL . '%s', $command, implode(PHP_EOL, $output))));
        } else {
            $return = null;
            $output = [];
            $command = $vendorDir . DIRECTORY_SEPARATOR . "robmorgan/phinx/bin/phinx status 2>&1";
            exec($command, $output, $return);
            $message =  sprintf('----------' .PHP_EOL.
                'Execute : %s' . PHP_EOL . '%s', $command, implode(PHP_EOL, $output));;
            if ($return !== 0) {
                $message = EscClr::fg("red", $message);
            }
            print($message);
        }
    }
}
