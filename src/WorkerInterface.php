<?php
namespace Haskel\Component\Grind;

interface WorkerInterface
{
    public function run();
    public function stop();
}