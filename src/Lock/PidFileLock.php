<?php
namespace Haskel\Component\Grind\Lock;

use Haskel\Component\Grind\Exception\DaemonException;

class PidFileLock implements PidLockInterface
{
    /**
     * @var string
     */
    private $file;

    /**
     * @param string $file
     */
    public function __construct($file)
    {
        if (!is_string($file)) {
            throw new DaemonException("Pid-file path must be a string");
        }
        $this->file = $file;
    }

    /**
     * @param $pid
     */
    public function acquire($pid)
    {
        if (is_readable($this->file)) {
            if (posix_kill($pid, SIG_DFL)) {
                throw new DaemonException("Pid-file already acquired, pid={$pid}");
            }

            if (!unlink($this->file)) {
                throw new DaemonException("Can't delete pid-file {$this->file}");
            }
        }

        $result = file_put_contents($this->file, $pid, LOCK_EX);
        if ($result === false) {
            throw new DaemonException("Can't write pid into the file {$this->file}");
        }
    }

    /**
     * @return int
     */
    public function getPid()
    {
        if (is_readable($this->file)) {
            $pid = (int) file_get_contents($this->file);
            return $pid;
        }

        throw new DaemonException("Pid-file {$this->file} is not readable or not exists");
    }

    /**
     * @return bool
     */
    public function isLocked()
    {
        if (is_readable($this->file)) {
            $pid = (int) file_get_contents($this->file);
            return ($pid > 0);
        }

        return false;
    }

    public function release()
    {
        if (file_exists($this->file)) {
            if (!unlink($this->file)) {
                throw new DaemonException("Pid-file {$this->file} was not deleted");
            }
        }
    }
}