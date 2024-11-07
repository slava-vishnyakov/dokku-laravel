<?php

namespace DokkuLaravel;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->addArgument('domain', InputArgument::REQUIRED)
            // https://github.com/laravel/installer/blob/master/src/NewCommand.php
            ->addOption('migratoro', null, InputOption::VALUE_NONE, 'Add migratoro')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository')
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub')
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'The database driver your application will use')
            ->addOption('stack', null, InputOption::VALUE_OPTIONAL, 'The Breeze / Jetstream stack that should be installed')
            ->addOption('breeze', null, InputOption::VALUE_NONE, 'Installs the Laravel Breeze scaffolding')
            ->addOption('jet', null, InputOption::VALUE_NONE, 'Installs the Laravel Jetstream scaffolding')
            ->addOption('dark', null, InputOption::VALUE_NONE, 'Indicate whether Breeze or Jetstream should be scaffolded with dark mode support')
            ->addOption('typescript', null, InputOption::VALUE_NONE, 'Indicate whether Breeze should be scaffolded with TypeScript support')
            ->addOption('ssr', null, InputOption::VALUE_NONE, 'Indicate whether Breeze or Jetstream should be scaffolded with Inertia SSR support')
            ->addOption('api', null, InputOption::VALUE_NONE, 'Indicates whether Jetstream should be scaffolded with API support')
            ->addOption('teams', null, InputOption::VALUE_NONE, 'Indicates whether Jetstream should be scaffolded with team support')
            ->addOption('verification', null, InputOption::VALUE_NONE, 'Indicates whether Jetstream should be scaffolded with email verification support')
            ->addOption('pest', null, InputOption::VALUE_NONE, 'Installs the Pest testing framework')
            ->addOption('phpunit', null, InputOption::VALUE_NONE, 'Installs the PHPUnit testing framework')
            ->addOption('prompt-breeze', null, InputOption::VALUE_NONE, 'Issues a prompt to determine if Breeze should be installed (Deprecated)')
            ->addOption('prompt-jetstream', null, InputOption::VALUE_NONE, 'Issues a prompt to determine if Jetstream should be installed (Deprecated)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = getcwd();

        $name = $input->getArgument('name');
        $domain = $input->getArgument('domain');

        $command = [
            'laravel',
            'new',
            $name,
        ];
        foreach($input->getOptions() as $option => $value) {
            if($option === 'migratoro') {
                continue;
            }
            if($value !== null && $value !== false) {
                if(in_array($option, ['branch', 'github', 'organization', 'database', 'stack'])) {
                    $command[] = '--' . $option;
                    $command[] = $value;
                } else {
                    $command[] = '--' . $option;
                }
            }
        }


        $isSuccess = $this->runCommand($command, $output, $directory);
        if(!$isSuccess) {
            $output->writeln('<error>Failed to run command</error>');
            return 1;
        }

        $n = new InstallLaravel($name, $domain, $input->getOption('migratoro'));
        $n->run();

        $output->writeln('<info>Installed!</info>');
        $output->writeln(<<<EOF
        <info>
        phpstorm {$name}
        </info>
        EOF
        );

        return 0;
    }


    /**
     * @param array $commands
     * @param OutputInterface $output
     * @param $directory
     */
    protected function runCommand(array $command, OutputInterface $output, $directory)
    {
        $process = new Process($command, $directory, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        return $process->isSuccessful();
    }

    protected function defaultBranch()
    {
        $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);

        $process->run();

        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output ? $output : 'main';
    }

}
