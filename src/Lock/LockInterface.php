<?php
namespace Haskel\Component\Grind\Lock;

interface LockInterface
{
    public function lock();
    public function unlock();
}