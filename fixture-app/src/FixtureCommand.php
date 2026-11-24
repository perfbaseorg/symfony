<?php

declare(strict_types=1);

namespace Perfbase\Symfony\FixtureApp;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// The attribute is read on PHP 8 by Symfony 6.1+, which deprecates relying
// on $defaultName alone. On PHP 7.4 it is a comment and $defaultName applies.
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'perfbase:fixture')]
class FixtureCommand extends Command
{
    protected static $defaultName = 'perfbase:fixture';

    protected function configure(): void
    {
        $this->setName('perfbase:fixture');
        $this->setDescription('Run the fixture command used by install-level smoke tests.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('fixture');

        return Command::SUCCESS;
    }
}
