<?php
namespace Haskel\Component\Grind;

use Haskel\Component\Grind\Exception\DaemonException;
use Haskel\Component\Grind\Lock\PidLockInterface;

class DaemonManager
{
    /**
     * Returns an information about daemon running status
     *
     * @param PidLockInterface $pidLock
     *
     * @return bool
     */
    public static function signal(PidLockInterface $pidLock, $signal)
    {
        if (self::isRunning($pidLock)) {
            $pid = $pidLock->getPid();
            posix_kill($pid, $signal);
        }
    }

    /**
     * Returns an information about daemon running status
     *
     * @param PidLockInterface $pidLock
     *
     * @return bool
     */
    public static function isRunning(PidLockInterface $pidLock)
    {
        $isRunning = false;
        if ($pidLock->isLocked()) {
            $pid = $pidLock->getPid();
            if (posix_kill($pid, SIG_DFL)) {
                $isRunning = true;
            }
        }
        return $isRunning;
    }

    /**
     * Try to terminate daemon by pid file
     *
     * @param string $pidFile Path to the pid file
     * @param string $daemonName Daemon name
     *
     * @throws DaemonException If cannot send signal to the daemon
     * @throws DaemonException If pid file is absent or isn't readable
     *
     * @return void
     */
    public static function terminate(PidLockInterface $pidLock, $daemonName)
    {
        if ($pidLock->isLocked()) {
            $pid = $pidLock->getPid();
            if (posix_kill($pid, SIGTERM)) {
                $lastError = posix_get_last_error();
                if ($lastError) {
                    throw new DaemonException("Termination of {$daemonName} is failed. " . posix_strerror($lastError));
                } else {
                    return;
                }
            }
        }

        throw new DaemonException("Daemon {$daemonName} is not running or no access to the pid lock");
    }
}