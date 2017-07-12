<?php
namespace Haskel\Component\Grind\Strategy;

use Haskel\Component\Grind\Configuration;
use Haskel\Component\Grind\Stats;

class SimpleStrategy implements StrategyInterface
{
    /**
     * @var Configuration
     */
    private $daemonConfig;

    /**
     * @var Stats
     */
    private $daemonStats;

    public function whatToDo()
    {
        return Command::SKIP;
    }

    public function setDaemonConfiguration(Configuration $configuration)
    {
        $this->daemonConfig = $configuration;
    }

    public function setStats(Stats $stats)
    {
        $this->daemonStats = $stats;
    }
}