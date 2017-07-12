<?php
namespace Haskel\Component\Grind\Lock;

interface PidLockInterface
{
    public function acquire($pid);
    public function getPid();
    public function isLocked();
    public function release();
}