<?php
namespace Haskel\Component\Grind;

use Haskel\Component\Grind\Enum\DaemonMode;
use Haskel\Component\Grind\Exception\DaemonException;
use Haskel\Component\Grind\Lock\PidFileLock;
use Haskel\Component\Grind\Lock\PidLockInterface;
use Haskel\Component\Grind\Strategy\Command;
use Haskel\Component\Grind\Strategy\SimpleStrategy;
use Haskel\Component\Grind\Strategy\StrategyInterface;
use Psr\Log\LoggerInterface;

class Daemon
{

    /**
     * @var string
     */
    protected $name = '';

    /**
     * @var string
     */
    protected $processNamePrefix = 'grind.daemon';

    /**
     * @var bool
     */
    protected $isActive = false;

    /**
     * @var int|null
     */
    protected $pid;

    /**
     * @var PidLockInterface
     */
    protected $pidLock;

    /**
     * @var string
     */
    protected $workerAlias;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var StrategyInterface
     */
    protected $strategy;

    /**
     * @var Stats
     */
    protected $stats;

    /**
     * @var resource|null
     */
    protected $stdout;

    /**
     * @var resource|null
     */
    protected $stderr;

    protected $terminationStartedAt = null;

    protected $signals = [
        'SIG_DFL'   => 0,
        'SIG_ERR'   => -1,
        'SIGHUP'    => 1,
        'SIGINT'    => 2,
        'SIGQUIT'   => 3,
        'SIGILL'    => 4,
        'SIGTRAP'   => 5,
        'SIGABRT'   => 6,
        //'SIGIOT'    => 6,
        'SIGBUS'    => 7,
        'SIGFPE'    => 8,
        'SIGKILL'   => 9,
        'SIGUSR1'   => 10,
        'SIGSEGV'   => 11,
        'SIGUSR2'   => 12,
        'SIGPIPE'   => 13,
        'SIGALRM'   => 14,
        'SIGTERM'   => 15,
        'SIGSTKFLT' => 16,
        //'SIGCLD'    => 17,
        'SIGCHLD'   => 17,
        'SIGCONT'   => 18,
        'SIGSTOP'   => 19,
        'SIGTSTP'   => 20,
        'SIGTTIN'   => 21,
        'SIGTTOU'   => 22,
        'SIGURG'    => 23,
        'SIGXCPU'   => 24,
        'SIGXFSZ'   => 25,
        'SIGVTALRM' => 26,
        'SIGPROF'   => 27,
        'SIGWINCH'  => 28,
        'SIGPOLL'   => 29,
        //'SIGIO'     => 29,
        'SIGPWR'    => 30,
        'SIGSYS'    => 31,
        //'SIGBABY'   => 31,
    ];

    /**
     * @var array|WorkerInterface[]
     */
    private $workers = [];

    public function __construct($workerAlias,
                                $name = null,
                                PidLockInterface $pidLock = null,
                                Configuration $config = null,
                                StrategyInterface $strategy = null)
    {
        if (!$config) {
            $config = new Configuration();
        }

        if (!$strategy) {
            $strategy = new SimpleStrategy();
        }

        if (!$pidLock) {
            $file = sys_get_temp_dir() . "/" . preg_replace("~[^A-Za-z0-9\-]~", "", strtolower($workerAlias)) . ".pid";
            $pidLock = new PidFileLock($file);
        }

        if (!$name) {
            $name = $workerAlias;
        }

        $this->workerAlias = $workerAlias;
        $this->name        = $name;
        $this->config      = $config;
        $this->pidLock     = $pidLock;
        $this->strategy    = $strategy;
        $this->stats       = new Stats();
        
        $this->strategy->setDaemonConfiguration($config);
        $this->strategy->setStats($this->stats);

        $this->validate();
    }

    public function checkEnvironment()
    {
        if (!extension_loaded('pcntl')) {
            throw new \Exception('pcntl extension required');
        }

        if (!extension_loaded('posix')) {
            throw new \Exception('posix extension required');
        }

        // Check the PHP configuration
        if ( !defined( 'SIGHUP' ) ) {
            trigger_error( 'PHP is compiled without --enable-pcntl directive', E_USER_ERROR );
        }

        // Check for CLI
        if ( ( php_sapi_name() !== 'cli' ) ) {
            trigger_error( 'You can only create daemon from the command line (CLI-mode)', E_USER_ERROR );
        }

        // Check for POSIX
        if (!function_exists('posix_getpid')) {
            trigger_error( 'PHP is compiled without --enable-posix directive', E_USER_ERROR );
        }

        // Enable Garbage Collector (PHP >= 5.3)
        if ( function_exists( 'gc_enable' ) ) {
            gc_enable();
        }

    }

    protected function validate()
    {
        $this->config->validate();

//        if (!class_exists($this->workerAlias)) {
//            throw new DaemonException("Worker class {$this->workerAlias} not exists");
//        }
    }

    /**
     * Start the daemon
     *
     * @return void
     */
    public function start()
    {
        $this->logger->info("Preparing to start the daemon {$this->name}");

        $this->daemonize();
        $this->initSignalHandler();

        $this->logger->info("Starting daemon {$this->name}; pid={$this->pid}");

        $this->pidLock->acquire($this->pid);
        $this->isActive = true;
        $this->loop();
        $this->pidLock->release();
    }

    private function initSignalHandler()
    {
        $signalHandler = $this->getSignalHandler();
        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGCHLD, $signalHandler);
        pcntl_signal(SIGALRM, $signalHandler);
        pcntl_signal(SIGHUP,  $signalHandler);
        pcntl_signal(SIGTSTP, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
    }

    /**
     * Returns a handler for signals
     *
     * @throws DaemonException
     * @return \Closure|callable
     */
    protected function getSignalHandler()
    {
        $daemon = $this;
        return function ($signal) use ($daemon) {
            /** @var $daemon Daemon */
            $pid = getmypid();
            $isCurrentDaemon = ($pid == $daemon->getPid());

            switch ($signal) {
                case SIGTERM:
                case SIGHUP:
                    if ($isCurrentDaemon) {
                        $daemon->stop($signal);
                    } else {
                        $worker = $daemon->requireWorkerByPid($pid);
                        $worker->stop();
                    }
                    break;

                case SIGCHLD:
                case SIGALRM:
                    $daemon->waitingForWorkers();
                    break;

                default:
                    throw new DaemonException("Unexpected signal {$signal} for pid={$pid}");
                    break;
            }
        };
    }

    /**
     * Waits for worker's termination
     *
     * Can stop the daemon if it was last worker and mode is WAIT_WORKERS
     *
     * @todo: add pcntl_wifstopped
     * @todo: maybe replace to pcntl_wait()
     *
     * @return void
     */
    public function waitingForWorkers()
    {
        while ($workerPid = pcntl_waitpid(-1, $status, WNOHANG)) {
            if ($workerPid == -1) {
                if ($this->config->mode == DaemonMode::WAIT_WORKERS && $this->isActive) {
                    posix_kill($this->pid, SIGTERM);
                }
                break;

            } else {
                if (pcntl_wifexited($status)) {
                    $exitCode = pcntl_wexitstatus($status);
                    $this->stats->workerGone($workerPid, $exitCode);
                    $this->logger->info("Worker pid={$workerPid} gone; code={$exitCode}");
                }
                if (pcntl_wifsignaled($status)) {
                    $signal = pcntl_wtermsig($status);
                    $this->stats->workerReceivedSignal($workerPid, $signal);
                    $signalName = $this->getSignalName($signal);
                    $this->logger->error("Worker {$workerPid} exited by signal {$signalName} (#{$signal})");
                }
                unset($this->workers[$workerPid]);
            }
        }
    }

    /**
     * @param int $signal
     *
     * @return string
     */
    private function getSignalName($signal)
    {
        $sigName = array_search($signal, $this->signals);
        if ($sigName) {
            return $sigName;
        }

        return '';
    }

    /**
     * Return a worker for specified PID
     *
     * @param integer $pid Worker PID
     *
     * @throws DaemonException If worker isn't exist
     * @return WorkerInterface
     */
    public function requireWorkerByPid($pid)
    {
        if (!isset($this->workers[$pid])) {
            throw new DaemonException("Worker with pid $pid is not found");
        }
        return $this->workers[$pid];
    }


    /**
     * Returns PID for daemon
     *
     * @return int|null
     */
    public function getPid()
    {
        return $this->pid;
    }

    private function checkForceTerminate()
    {
        // If we started termination then we should be able to force termination after timeout
        if ($this->terminationStartedAt) {
            $currentTime = microtime(true);
            if ($currentTime > ($this->terminationStartedAt + $this->config->terminationForceWait)) {
                $this->logger->error("Forced termination of daemon {$this->name} after timeout");
                $this->stop(SIGKILL);
            }
        }
    }

    /**
     * Main loop for daemon
     *
     * Creates workers and dispatches all signals
     *
     * @return void
     */
    protected function loop()
    {
        // сколько воркеров еще можно запустить
        $remaining = $this->config->maxWorkers;

        while ($this->isActive || count($this->workers)) {
            $this->checkForceTerminate();

            $command = $this->strategy->whatToDo();
            switch ($command) {
                case Command::INCREASE:
                    $this->addWorker();
                    if ($this->config->mode === DaemonMode::WAIT_WORKERS) {
                        $remaining--;
                    }
                    break;

                case Command::DECREASE:
                    $this->removeWorker();
                    break;

                case Command::SKIP:
                default:
                    // do nothing
                    break;
            }

            $this->stats->measure();

            sleep($this->config->loopWaitInterval);
            pcntl_signal_dispatch();
        }
    }


    private function removeWorker()
    {
        $workerPid = key($this->workers);
        $this->logger->info("Requesting worker $workerPid to terminate");
        posix_kill($workerPid, SIGTERM);
    }


    private function addWorker()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new DaemonException('Can not fork process. ' . posix_strerror(posix_get_last_error()));
        } else if ($pid) {
            $this->logger->info("Forked new worker with pid $pid");
            $this->workers[$pid] = null;
        } else {
            $workerPid = getmypid();
//            $workerArgs  = $this->input->getOption('args');
//            $workerInput = new StringInput($workerArgs);
            $this->setProcessName('worker');

            $worker = $this->createWorker();
            $this->workers[$workerPid] = $worker;
            $this->workers[$workerPid]->run();
            exit;
        }
    }

    /**
     * @return WorkerInterface
     */
    protected function createWorker()
    {
        return new $this->workerAlias($this);
    }

    /**
     * Stop the demon
     *
     * This will send SIGTERM signal to all
     *
     * @param int $signal Termination signal for workers
     *
     * @return void
     */
    public function stop($signal = SIGTERM)
    {
        $activeWorkers = array_keys($this->workers);
        foreach ($activeWorkers as $workerPid) {
            posix_kill($workerPid, $signal);
        }

        $shouldStop = ($signal === SIGTERM);

        if ($shouldStop) {
            $this->terminationStartedAt = $this->terminationStartedAt ?: microtime(true);

            $timeout = floor($this->terminationStartedAt + $this->config->terminationForceWait - microtime(true));
            $this->logger->info("Daemon {$this->name} is terminating, timeout is {$timeout}s");
            $this->isActive = false;
        }
    }

    /**
     * Detach from console and become demon
     *
     * @throws DaemonException
     * @return void
     */
    protected function daemonize()
    {
        $this->logger->debug("Exiting from main process");

        $daemonPid = pcntl_fork();
        if ($daemonPid > 0) {
            exit;
        } elseif ($daemonPid == -1) {
            throw new DaemonException(posix_strerror(posix_get_last_error()));
        }

        $this->logger->debug("Isolating daemon process");

        // one more fork to become isolated
        $daemonPid = pcntl_fork();

        if ($daemonPid == -1) {
            throw new DaemonException(posix_strerror(posix_get_last_error()));
        } elseif ($daemonPid > 0) {
            exit;
        }

        $this->setProcessName('master');

        $this->logger->debug("Attempting to become a session leader in the group");

        if (posix_setsid() == -1) {
            $errorMessage = 'Can not become the leader. ' . posix_strerror(posix_get_last_error());
            throw new DaemonException($errorMessage);
        }

        if ($this->config->user) {
            $user = posix_getpwnam($this->config->user);
            if (!isset($user['uid'])) {
                throw new DaemonException("Can't get uid by username {$this->config->user}");
            }
            if (!posix_setuid($user['uid'])) {
                $errorMessage = 'Can not change user. ' . posix_strerror(posix_get_last_error());
                throw new DaemonException($errorMessage);
            }
        }

        umask(0);
        chdir('/');

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        fopen("/dev/null", "r");
        $this->stdout = fopen($this->config->outputLogFile, "wb");
        $this->stderr = fopen($this->config->errorLogFile, "wb");

        $this->pid = posix_getpid();
    }

    /**
     * Sets process name
     *
     * @param $type
     */
    private function setProcessName($type)
    {
        $daemonName = $this->name;
        if ($this->config->namePrefix) {
            $daemonName = "{$this->config->namePrefix}:$daemonName";
        }
        if ($this->config->namePostfix) {
            $daemonName = "$daemonName:{$this->config->namePostfix}";
        }
        $processName = trim("$this->processNamePrefix {$type}: {$daemonName}");

        cli_set_process_title($processName);
    }
}