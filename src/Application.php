<?php

namespace Qadept\Tunnel;

use Qadept\Tunnel\Command\Create;
use Symfony\Component\Console\Input\InputInterface;

class Application extends \Symfony\Component\Console\Application
{
    protected function getCommandName(InputInterface $input)
    {
        return 'qatunnel';
    }

    protected function getDefaultCommands()
    {
        $defaultCommands = parent::getDefaultCommands();
        $defaultCommands[] = new Create();

        return $defaultCommands;
    }

    public function getDefinition()
    {
        $inputDefinition = parent::getDefinition();
        $inputDefinition->setArguments();

        return $inputDefinition;
    }
}