<?php

/*
 * static class helper for setting up php interpreters
 */
class KmsCi_Environment_PhpmigHelper extends KmsCi_Environment_BaseHelper {

    public function exec($envParams, $bootstrapFile, $params = null)
    {
        if (empty($params)) $params = array();
        $cmd = '';
        foreach($envParams as $k=>$v) {
            $cmd.=$k.'='.escapeshellarg($v).' ';
        }
        $cmd .= $this->_runner->getUtilHelper()->getBin('phpmig');
        if ($this->_runner->isArg('debug') || $this->_runner->isArg('verbose')) {
            $cmd .= ' -vvv';
        }
        if (!empty($bootstrapFile)) $cmd .= ' --bootstrap='.escapeshellarg($bootstrapFile);
        foreach ($params as $k=>$v) {
            if (is_numeric($k)) {
                $cmd .= ' '.escapeshellarg($v);
            } else {
                $cmd .= '--'.$k.'='.escapeshellarg($v);
            }
        };
        $ans = $this->_runner->getUtilHelper()->exec($cmd);
        \Kmig\Migrator::clearCaches();
        return $ans;
    }

    public function getNewPhpmig($envParams, $bootstrapFile, $integId)
    {
        $phpmig = new KmsCi_Environment_PhpmigHelper_Phpmig($envParams, $bootstrapFile, $integId);
        return $phpmig;
    }

}

class KmsCi_Environment_PhpmigHelper_Phpmig {

    protected $_envParams;
    protected $_bootstrapFile;
    protected $_container;
    protected $_command;
    protected $_allMigrations;
    protected $_hasPhpmig = false;
    protected $_hasKmigData = false;

    public function __construct($envParams, $kmigpath, $integId)
    {
        foreach ($envParams as $k=>$v) {
            putenv($k.'='.$v);
        }
        $container = array();
        if (file_exists($kmigpath.'/phpmig.php')) {
            $this->_hasPhpmig = true;
            require($kmigpath.'/phpmig.php');
            if (empty($container)) {
                throw new Exception('invalid container, make sure the relevant phpmig.php file sets a $container variable');
            }
            $this->_container = $container;
            $datafilename = KmsCi_Kmig_IntegrationHelper::getInstanceByIntegrationId($integId)->getKmigPhpmigDataFileName();
            if (file_Exists($datafilename)) {
                $this->_hasKmigData = true;
                \Kmig\Helper\Phpmig\KmigAdapter::setContainerValuesFromDataFile($this->_container, $datafilename);
            }
            $this->_command = new KmsCi_Environment_PhpmigHelper_Command();
            $this->_command->kmig_container = $this->_container;
            $this->_allMigrations = $this->_command->kmig_getMigrations();
            /** @var \Kmig\Helper\Phpmig\KmigAdapter $adapter */
            $adapter = $this->_container['phpmig.adapter'];
            $this->_allVersions = $adapter->fetchAll();
        }
    }

    public function isAllMigrationsRan()
    {
        if (!$this->_hasPhpmig) {
            return true;
        } else {
            $ok = true;
            foreach ($this->_allMigrations as $migration) {
                if (!in_array($migration->getVersion(), $this->_allVersions)) {
                    $ok = false;
                }
            }
            return $ok;
        }
    }

    public function migrate()
    {
        if (!$this->_hasPhpmig) {
            return true;
        } else {
            $versions = $this->_allVersions;
            $migrations = $this->_allMigrations;
            ksort($migrations);
            $output = new Symfony\Component\Console\Output\ConsoleOutput();
            $migrator = new \Phpmig\Migration\Migrator($this->_container['phpmig.adapter'], $this->_container, $output);
            foreach($migrations as $migration) {
                if (!in_array($migration->getVersion(), $versions)) {
                    $migrator->up($migration);
                }
            }
            return true;
        }
    }

    public function getContainer()
    {
        return $this->_container;
    }

}

class KmsCi_Environment_PhpmigHelper_Command extends \Phpmig\Console\Command\MigrateCommand {

    public $kmig_container;

    public function getBootstrap()
    {
        return '';
    }

    public function getContainer()
    {
        return $this->kmig_container;
    }

    public function kmig_getMigrations()
    {
        $output = new Symfony\Component\Console\Output\ConsoleOutput();
        return $this->bootstrapMigrations($output);
    }

}
