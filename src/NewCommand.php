<?php

namespace DokkuLaravel;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Laravel application')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addArgument('domain', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directory = getcwd();

        $name = $input->getArgument('name');
        $domain = $input->getArgument('domain');

        $commands = [
            'laravel new ' . $name
        ];

        $this->runCommands($commands, $output, $directory);

        $n = new InstallLaravel($name, $domain);
        $n->run();

        $output->writeln('<info>Installed</info>');
        $output->writeln("<info>cd {$name}; cat dokku-deploy.txt</info>");
    }


    /**
     * @param array $commands
     * @param OutputInterface $output
     * @param $directory
     */
    protected function runCommands(array $commands, OutputInterface $output, $directory)
    {
        $process = new Process(implode(' && ', $commands), $directory, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });
    }
}