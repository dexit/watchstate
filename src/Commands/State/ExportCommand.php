<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Servers\ServerInterface;
use Nyholm\Psr7\Uri;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class ExportCommand extends Command
{
    public function __construct(private ExportInterface $mapper, private LoggerInterface $logger)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('state:export')
            ->setDescription('Export watch state to servers.')
            ->addOption('read-mapper', null, InputOption::VALUE_OPTIONAL, 'Configured mapper.', $this->mapper::class)
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption('memory-usage', 'm', InputOption::VALUE_NONE, 'Show memory usage.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export.')
            ->addOption(
                'servers-filter',
                's',
                InputOption::VALUE_OPTIONAL,
                'Sync selected servers, comma seperated. \'s1,s2\'.',
                ''
            )
            ->addOption(
                'ignore-date',
                null,
                InputOption::VALUE_NONE,
                'Ignore date comparison, and update server watched state to match database.'
            )
            ->addOption('stats-show', null, InputOption::VALUE_NONE, 'Show final status.')
            ->addOption(
                'stats-filter',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter final status output e.g. (servername.key)',
                null
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];
        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $serversFilter);
        $isCustom = !empty($serversFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if ($isCustom && !in_array($serverName, $selected, true)) {
                continue;
            }

            if (true !== ag($server, 'export.enabled')) {
                $output->writeln(
                    sprintf('<error>Ignoring \'%s\' as requested by \'servers.yaml\'.</error>', $serverName),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                continue;
            }

            if (!isset($supported[$type])) {
                $output->writeln(
                    sprintf(
                        '<error>Server \'%s\' Used Unsupported type. Expecting one of \'%s\' but got \'%s\' instead.</error>',
                        $serverName,
                        implode(', ', array_keys($supported)),
                        $type
                    )
                );
                return self::FAILURE;
            }

            if (null === ag($server, 'url')) {
                $output->writeln(sprintf('<error>Server \'%s\' has no url.</error>', $serverName));
                return self::FAILURE;
            }

            $list[] = [
                'name' => $serverName,
                'kind' => $supported[$type],
                'server' => $server,
            ];
        }

        if (empty($list)) {
            throw new RuntimeException(
                $isCustom ? '--servers-filter/-s did not return any server.' : 'No server were found.'
            );
        }

        $logger = null;

        if ($input->getOption('redirect-logger') || $input->getOption('memory-usage')) {
            $logger = new CliLogger($output, (bool)$input->getOption('memory-usage'));
        }

        $requests = [];

        if (count($list) >= 1) {
            $this->mapper->loadData();
        }

        if (null !== $logger) {
            $this->logger = $logger;
            $this->mapper->setLogger($logger);
        }

        foreach ($list as $server) {
            $name = ag($server, 'name');
            Data::addBucket($name);

            $class = Container::get(ag($server, 'kind'));
            assert($class instanceof ServerInterface);

            $opts = ag($server, 'server.options', []);

            if ($input->getOption('ignore-date')) {
                $opts[ServerInterface::OPT_EXPORT_IGNORE_DATE] = true;
            }

            $class = $class->setUp(
                name:    $name,
                url:     new Uri(ag($server, 'server.url')),
                token:   ag($server, 'server.token', null),
                userId:  ag($server, 'server.user', null),
                options: $opts
            );

            if (null !== $logger) {
                $class = $class->setLogger($logger);
            }

            $after = $input->getOption('force-full') ? null : ag($server, 'server.import.lastSync', null);

            if (null === $after) {
                $this->logger->notice(
                    sprintf('Importing \'%s\' play state changes since beginning.', $name)
                );
            } else {
                $after = makeDate($after);
                $this->logger->notice(
                    sprintf('Importing \'%s\' play state changes since \'%s\'.', $name, $after)
                );
            }

            array_push($requests, ...$class->push($this->mapper, $after));

            if (true === Data::get(sprintf('%s.no_export_update', $name))) {
                $this->logger->notice(
                    sprintf('Not updating \'%s\' export date, as the server reported an error.', $name)
                );
            } else {
                Config::save(sprintf('servers.%s.export.lastSync', $name), time());
            }
        }

        $this->logger->notice(sprintf('Waiting on (%d) (Compare State) Requests.', count($requests)));
        foreach ($requests as $response) {
            $requestData = $response->getInfo('user_data');
            try {
                if (200 === $response->getStatusCode()) {
                    $requestData['ok']($response);
                } else {
                    $requestData['error']($response);
                }
            } catch (ExceptionInterface $e) {
                $requestData['error']($e);
            }
        }
        $this->logger->notice(sprintf('Finished waiting on (%d) Requests.', count($requests)));

        $changes = $this->mapper->getQueue();

        if (empty($changes)) {
            $this->logger->notice('No State change detected.');
            return self::SUCCESS;
        }

        $this->logger->notice(sprintf('Waiting on (%d) (Stats Change) Requests.', count($changes)));
        foreach ($changes as $response) {
            $requestData = $response->getInfo('user_data');
            try {
                if (200 !== $response->getStatusCode()) {
                    throw new ServerException($response);
                }
                $this->logger->debug(
                    sprintf(
                        'Processed: State (%s) - %s',
                        ag($requestData, 'state', '??'),
                        ag($requestData, 'itemName', '??'),
                    )
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error($e->getMessage());
            }
        }
        $this->logger->notice(sprintf('Finished waiting on (%d) Requests.', count($changes)));

        // -- Update Server.yaml with new lastSync date.
        file_put_contents(
            Config::get('path') . DS . 'config' . DS . 'servers.yaml',
            Yaml::dump(Config::get('servers', []), 8, 2)
        );

        return self::SUCCESS;
    }
}
