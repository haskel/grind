<?php

use Haskel\Component\Grind\Configuration;
use Haskel\Component\Grind\Daemon;
use Haskel\Component\Grind\HelloWorker;
use Haskel\Component\Grind\Lock\PidFileLock;
use Haskel\Component\Grind\Strategy\SimpleStrategy;


$pidLock = null;
$strategy = null;
$name = null;
$worker = null;
$logger = null;

$config = new Configuration();
$pidLock = new PidFileLock('sdfasdf.pid');
$strategy = new SimpleStrategy();

$daemon = new Daemon(HelloWorker::class, $pidLock, $config, $strategy);
$daemon->start();
