<?php

class KmsCi_Runner_IntegrationTests extends KmsCi_Runner_Base {

    protected function _run($integid, $clsname, $isRemote = false)
    {
        $ret = true;
        /** @var KmsCi_Runner_IntegrationTest_Base $tests */
        $tests = new $clsname($this->_runner, $integid);
        // skip integrations
        if ($tests->isSkipRun()) {
            return true;
        }
        // skip non-remote integration tests
        if ($isRemote && !$tests->isRemote()) {
            return true;
        }
        // skip remote integration tests
        if (!$isRemote && $tests->isRemote()) {
            return true;
        }
        $filter = $this->_runner->getArg('filter', '');
        if (empty($filter) || preg_match($filter, $integid) === 1) {
            echo "{$clsname}: \n";
            if (!$tests->run()) {
                $ret = false;
            }
        }
        echo "\n\n";
        return $ret;
    }

    protected function _setup($integId, $clsname)
    {
        /** @var KmsCi_Runner_IntegrationTest_Base $tests */
        $tests = new $clsname($this->_runner, $integId);
        if (!$tests->setup()) {
            echo "Failed to setup integration\n";
            $ret = false;
        } else {
            $filterTests = $this->_runner->getArg('filter-tests', '');
            if (empty($filterTests)) {
                $ret = true;
            } else {
                $ret = $tests->runSetupTests($filterTests);
            }
        }
        return $ret;
    }

    protected function _runAll($params)
    {
        $ret = true;
        $isRemote = (isset($params['isRemote']) && $params['isRemote']);
        $rootPath = $this->_runner->getConfig('rootPath', '');
        if (!empty($rootPath)) {
            // if there is a rootPath config - scan all subdirectories of this path for integration tests
            // it looks for a file under tests/integration/main.php - for each directory
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath), RecursiveIteratorIterator::SELF_FIRST);
            foreach($files as $file) {
                /** @var DirectoryIterator $file */
                $filename = $file->getPathname();
                if (strpos($filename, '/tests/integration/main.php') !== false) {
                    $str = file_get_contents($filename);
                    if (preg_match("/\s+([a-zA-Z]+)_Integration\s/i", $str, $matches)) {
                        $integid = strtolower($matches[1]);
                        $clsname = ucfirst($integid).'_Integration';
                        require_once($filename);
                        $ret = $this->_run($integid, $clsname, $isRemote) ? $ret : false;
                    };
                }
            }
        }
        // look in the integrationTestsPath
        foreach (glob($this->_runner->getConfig('integrationTestsPath').'/*') as $fn) {
            if (is_dir($fn)) {
                $tmp = explode('/', $fn);
                $integid = $tmp[count($tmp)-1];
                $clsname = 'IntegrationTests_'.$integid;
                $mainfn = $fn.'/main.php';
                if (file_exists($mainfn)) {
                    require_once($mainfn);
                    $ret = $this->_run($integid, $clsname, $isRemote) ? $ret : false;
                }
            }
        }
        return $ret;
    }

    public function run($params = array())
    {
        $testsPath = $this->_runner->getConfig('integrationTestsPath', '');
        if (empty($testsPath)) {
            $this->_runner->log('WARNING: no integrationTestsPath');
            return true;
        } elseif (isset($params['isSetupIntegration']) && $params['isSetupIntegration']) {
            return $this->setupIntegration($this->_runner->getArg('setup-integration'));
        } else {
            return $this->_runAll($params);
        }
    }

    public function setupIntegration($integId)
    {
        if ($className = self::getIntegrationClassById($integId, $this->_runner)) {
            $ret = $this->_setup($integId, $className);
            return $ret;
        } else {
            return false;
        }
    }

    /**
     * @param $integId
     * @param $runner KmsCi_CliRunnerAbstract
     * @return string the integration class name
     */
    public static function getIntegrationClassById($integId, $runner)
    {
        $foundIt = false;
        $retClassName = '';
        $integmapfile = $runner->getConfigPath().'/integrationMap.json';
        if (file_exists($integmapfile)) {
            $integmap = json_decode(file_get_contents($integmapfile), true);
        } else {
            $integmap = array();
        };
        if (array_key_exists($integId, $integmap)) {
            list($retClassName, $filename) = $integmap[$integId];
            if (file_exists($filename)) {
                require_once($filename);
                $foundIt = true;
            }
        }
        if (!$foundIt) {
            $rootPath = $runner->getConfig('rootPath', '');
            if (!empty($rootPath)) {
                // look for integration in directories under the rootPath
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($files as $file) {
                    /** @var DirectoryIterator $file */
                    $filename = $file->getPathname();
                    if (strpos($filename, '/tests/integration/main.php') !== false) {
                        $str = file_get_contents($filename);
                        if (preg_match("/\s+([a-zA-Z]+)_Integration\s/i", $str, $matches)) {
                            $tmpIntegid = strtolower($matches[1]);
                            if ($integId == $tmpIntegid) {
                                $clsname = ucfirst($tmpIntegid) . '_Integration';
                                require_once($filename);
                                $retClassName = $clsname;
                                $foundIt = true;
                                $integmap[$integId] = array($retClassName, $filename);
                                file_put_contents($integmapfile, json_encode($integmap));
                            }
                        }
                    }
                }
            }
        }
        if (!$foundIt) {
            $clsname = 'IntegrationTests_'.$integId;
            $mainfn = $runner->getConfig('integrationTestsPath').'/'.$integId.'/main.php';
            if (!file_exists($mainfn)) {
                echo "file not found: {$mainfn}\n";
            } else {
                require_once($mainfn);
                $retClassName = $clsname;
                $integmap[$integId] = array($retClassName, $mainfn);
                file_put_contents($integmapfile, json_encode($integmap));
            }
        }
        return $retClassName;
    }

}
