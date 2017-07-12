<?php
namespace Haskel\Component\Grind;

class HelloWorker extends AbstractWorker
{
    public function execute()
    {
        echo "123\n";
        sleep(5);
    }
}