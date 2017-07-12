<?php
namespace Haskel\Component\Grind;

use Haskel\Component\Grind\Enum\DaemonMode;

class Configuration
{
    public $namePrefix = null;
    public $namePostfix = null;
    public $maxWorkers = 1;
    public $minWorkers = 1;
    public $mode = DaemonMode::KEEP_WORKERS;
    public $loopWaitInterval = 60; // sec
    public $terminationForceWait = 10; // sec
    public $maxMemUsage = null;
    public $maxMemInPercent = null;
    public $outputLogFile = 'output.log';
    public $errorLogFile  = 'error.log';
    public $user = null;
    public $group = null;
    public $gid = null;
    public $uid = null;

    public function validate()
    {

    }
}