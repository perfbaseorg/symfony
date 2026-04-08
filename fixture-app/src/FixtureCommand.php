<?php

declare(strict_types=1);

namespace Perfbase\Symfony\FixtureApp;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
