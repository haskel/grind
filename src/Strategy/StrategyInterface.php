<?php
namespace Haskel\Component\Grind\Strategy;

use Haskel\Component\Grind\Configuration;
use Haskel\Component\Grind\Stats;

interface StrategyInterface
{
    public function whatToDo();
    public function setDaemonConfiguration(Configuration $configuration);
    public function setStats(Stats $stats);
}