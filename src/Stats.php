<?php
namespace Haskel\Component\Grind;

class Stats
{
    public function measure()
    {
        // get mem usage
        // get proc
        // get io
        // get net
        // get workers count
    }

    /**
     * @param $workerPid
     * @param $exitCode
     */
    public function workerGone($workerPid, $exitCode)
    {

    }

    /**
     * @param $workerPid
     * @param $signal
     */
    public function workerReceivedSignal($workerPid, $signal)
    {

    }
}