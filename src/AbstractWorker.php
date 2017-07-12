<?php
namespace Haskel\Component\Grind;

use Haskel\Component\Grind\Enum\WorkerMode;

abstract class AbstractWorker implements WorkerInterface
{
    protected $pid;
    protected $isActive = false;
    protected $periodicallyWaitTime = 0;
    protected $mode;

    public function __construct()
    {
        $this->pid = getmypid();
    }

    public function run()
    {
        $this->isActive = true;

        while ($this->isActive) {
            $this->execute();
            if ($this->mode == WorkerMode::PERIODICALLY) {
                usleep($this->periodicallyWaitTime * 1e6);
            }
            pcntl_signal_dispatch();
        }
    }

    abstract protected function execute();

    public function stop()
    {
        $this->isActive = false;
    }
}