<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Tools\Console\Command;

use Doctrine\Migrations\Exception\NoMigrationsFoundWithCriteria;
use Doctrine\Migrations\Exception\UnknownMigrationVersion;
use Doctrine\Migrations\Metadata\ExecutedMigrationsSet;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function count;
use function getcwd;
use function is_string;
use function is_writable;
use function sprintf;
use function substr;

/**
 * The MigrateCommand class is responsible for executing a migration from the current version to another
 * version up or down. It will calculate all the migration versions that need to be executed and execute them.
 */
final class MigrateCommand extends DoctrineCommand
{
    /** @var string */
    protected static $defaultName = 'migrations:migrate';

    protected function configure() : void
    {
        $this
            ->setAliases(['migrate'])
            ->setDescription(
                'Execute a migration to a specified version or the latest available version.'
            )
            ->addArgument(
                'version',
                InputArgument::OPTIONAL,
                'The version FQCN or alias (first, prev, next, latest) to migrate to.',
                'latest'
            )
            ->addOption(
                'write-sql',
                null,
                InputOption::VALUE_OPTIONAL,
                'The path to output the migration SQL file instead of executing it. Defaults to current working directory.',
                false
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Execute the migration as a dry run.'
            )
            ->addOption(
                'query-time',
                null,
                InputOption::VALUE_NONE,
                'Time all the queries individually.'
            )
            ->addOption(
                'allow-no-migration',
                null,
                InputOption::VALUE_NONE,
                'Do not throw an exception if no migration is available.'
            )
            ->addOption(
                'all-or-nothing',
                null,
                InputOption::VALUE_OPTIONAL,
                'Wrap the entire migration in a transaction.',
                false
            )
            ->setHelp(<<<EOT
The <info>%command.name%</info> command executes a migration to a specified version or the latest available version:

    <info>%command.full_name%</info>

You can optionally manually specify the version you wish to migrate to:

    <info>%command.full_name% FQCN</info>

You can specify the version you wish to migrate to using an alias:

    <info>%command.full_name% prev</info>
    <info>These alias are defined : first, latest, prev, current and next</info>

You can specify the version you wish to migrate to using an number against the current version:

    <info>%command.full_name% current+3</info>

You can also execute the migration as a <comment>--dry-run</comment>:

    <info>%command.full_name% FQCN --dry-run</info>

You can output the would be executed SQL statements to a file with <comment>--write-sql</comment>:

    <info>%command.full_name% FQCN --write-sql</info>

Or you can also execute the migration without a warning message which you need to interact with:

    <info>%command.full_name% --no-interaction</info>

You can also time all the different queries if you wanna know which one is taking so long:

    <info>%command.full_name% --query-time</info>

Use the --all-or-nothing option to wrap the entire migration in a transaction.
EOT
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $migratorConfigurationFactory = $this->getDependencyFactory()->getConsoleInputMigratorConfigurationFactory();
        $migratorConfiguration        = $migratorConfigurationFactory->getMigratorConfiguration($input);

        $question = 'WARNING! You are about to execute a database migration that could result in schema changes and data loss. Are you sure you wish to continue?';
        if (! $migratorConfiguration->isDryRun() && ! $this->canExecute($question, $input)) {
            $this->io->error('Migration cancelled!');

            return 3;
        }

        $this->getDependencyFactory()->getMetadataStorage()->ensureInitialized();

        $allowNoMigration = $input->getOption('allow-no-migration');
        $versionAlias     = $input->getArgument('version');
        $path             = $input->getOption('write-sql');

        try {
            $version = $this->getDependencyFactory()->getVersionAliasResolver()->resolveVersionAlias($versionAlias);
        } catch (UnknownMigrationVersion|NoMigrationsFoundWithCriteria $e) {
            $this->getVersionNameFromAlias($versionAlias);

            return 1;
        }

        $planCalculator                = $this->getDependencyFactory()->getMigrationPlanCalculator();
        $statusCalculator              = $this->getDependencyFactory()->getMigrationStatusCalculator();
        $executedUnavailableMigrations = $statusCalculator->getExecutedUnavailableMigrations();

        if ($this->checkExecutedUnavailableMigrations($executedUnavailableMigrations, $input) === false) {
            return 3;
        }

        $plan = $planCalculator->getPlanUntilVersion($version);

        if (count($plan) === 0 && ! $allowNoMigration) {
            $this->io->warning('Could not find any migrations to execute.');

            return 1;
        }

        if (count($plan) === 0) {
            $this->getVersionNameFromAlias($versionAlias);

            return 0;
        }

        $migrator = $this->getDependencyFactory()->getMigrator();
        if ($migratorConfiguration->isDryRun()) {
            $sql = $migrator->migrate($plan, $migratorConfiguration);

            $path = is_string($path) ? $path : getcwd();

            if (! is_string($path) || ! is_writable($path)) {
                $this->io->error('Path not writeable!');

                return 1;
            }

            $writer = $this->getDependencyFactory()->getQueryWriter();
            $writer->write($path, $plan->getDirection(), $sql);

            return 0;
        }

        $this->getDependencyFactory()->getLogger()->notice(
            'Migrating' . ($migratorConfiguration->isDryRun() ? ' (dry-run)' : '') . ' {direction} to {to}',
            [
                'direction' => $plan->getDirection(),
                'to' => (string) $version,
            ]
        );

        $migrator->migrate($plan, $migratorConfiguration);

        $this->io->newLine();

        return 0;
    }

    private function checkExecutedUnavailableMigrations(
        ExecutedMigrationsSet $executedUnavailableMigrations,
        InputInterface $input
    ) : bool {
        if (count($executedUnavailableMigrations) !== 0) {
            $this->io->warning(sprintf(
                'You have %s previously executed migrations in the database that are not registered migrations.',
                count($executedUnavailableMigrations)
            ));

            foreach ($executedUnavailableMigrations->getItems() as $executedUnavailableMigration) {
                $this->io->text(sprintf(
                    '<comment>>></comment> %s (<comment>%s</comment>)',
                    $executedUnavailableMigration->getExecutedAt() !== null
                        ? $executedUnavailableMigration->getExecutedAt()->format('Y-m-d H:i:s')
                        : null,
                    $executedUnavailableMigration->getVersion()
                ));
            }

            $question = 'Are you sure you wish to continue?';

            if (! $this->canExecute($question, $input)) {
                $this->io->error('Migration cancelled!');

                return false;
            }
        }

        return true;
    }

    private function getVersionNameFromAlias(string $versionAlias) : void
    {
        if ($versionAlias === 'first') {
            $this->io->error('Already at first version.');

            return;
        }

        if ($versionAlias === 'next' || $versionAlias === 'latest') {
            $this->io->error('Already at latest version.');

            return;
        }

        if (substr($versionAlias, 0, 7) === 'current') {
            $this->io->error('The delta couldn\'t be reached.');

            return;
        }

        $this->io->error(sprintf(
            'Unknown version: %s',
            OutputFormatter::escape($versionAlias)
        ));
    }
}
