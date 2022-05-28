<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class ImportCommand extends Command
{
    public const TASK_NAME = 'import';

    public function __construct(
        private StorageInterface $storage,
        private ImportInterface $mapper,
        private LoggerInterface $logger
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('state:import')
            ->setDescription('Import play state from backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full import. Ignore last sync date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any changes.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('servers-filter', 's', InputOption::VALUE_OPTIONAL, 'Select backends. Comma (,) seperated.', '')
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --servers-filter logic.')
            ->addOption('trace', null, InputOption::VALUE_NONE, 'Enable Debug Tracing mode.')
            ->addOption(
                'always-update-metadata',
                null,
                InputOption::VALUE_NONE,
                'Always update the locally stored metadata from backend.'
            )
            ->addOption(
                'direct-mapper',
                null,
                InputOption::VALUE_NONE,
                'Direct mapper is memory efficient, However its slower than the default mapper.'
            )
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    protected function process(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                throw new RuntimeException('Unable to read data given config.');
            }
            Config::save('servers', Yaml::parseFile($config));
            $custom = true;
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $list = [];
        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $serversFilter);
        $isCustom = !empty($serversFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        $inTradeMode = true === $input->getOption('trace');

        $mapperOpts = [];

        if ($input->getOption('dry-run')) {
            $output->writeln('<info>Dry run mode. No changes will be committed.</info>');

            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
            $this->storage->setOptions(options: [Options::DEBUG_TRACE => true]);
        }

        if ($input->getOption('always-update-metadata')) {
            $mapperOpts[Options::MAPPER_ALWAYS_UPDATE_META] = true;
        }

        if ($input->getOption('direct-mapper')) {
            $this->logger->info('Using Direct mapper');
            $this->mapper = new DirectMapper(logger: $this->logger, storage: $this->storage);
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if ($isCustom && $input->getOption('exclude') === in_array($serverName, $selected)) {
                $this->logger->info(
                    sprintf('%s: Ignoring backend as requested by [-s, --servers-filter].', $serverName)
                );
                continue;
            }

            if (true !== ag($server, 'import.enabled')) {
                $this->logger->info(sprintf('%s: Ignoring backend as requested by user config.', $serverName));
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    sprintf(
                        '%s: Unexpected type. Expecting \'%s\' but got \'%s\'.',
                        $serverName,
                        implode(', ', array_keys($supported)),
                        $type
                    )
                );
                continue;
            }

            if (null === ($url = ag($server, 'url')) || false === filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->error(
                    sprintf('%s: Backend does not have valid url.', $serverName),
                    [
                        'url' => $url ?? 'None'
                    ]
                );
                continue;
            }

            $server['name'] = $serverName;
            $list[$serverName] = $server;
        }

        if (empty($list)) {
            $output->writeln(
                sprintf(
                    '<error>%s</error>',
                    $isCustom ? '[-s, --servers-filter] Filter did not match any server.' : 'No servers were found.'
                )
            );
            return self::FAILURE;
        }

        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        $this->logger->notice(sprintf('Running WatchState Version \'%s\'.', getAppVersion()));

        $this->logger->notice('MAPPER: Preloading database into memory.');
        if ($inTradeMode) {
            $this->logger->notice(
                sprintf('SYSTEM: Memory Usage (Now: %s) - (Peak: %s).', getMemoryUsage(), getPeakMemoryUsage())
            );
        }

        $this->mapper->loadData();

        if ($inTradeMode) {
            $this->logger->notice(
                sprintf('SYSTEM: Memory Usage (Now: %s) - (Peak: %s).', getMemoryUsage(), getPeakMemoryUsage())
            );
        }

        $this->logger->notice('MAPPER: Finished Preloading database.');

        $this->storage->singleTransaction();

        foreach ($list as $name => &$server) {
            Data::addBucket($name);

            $opts = ag($server, 'options', []);

            if ($input->getOption('trace')) {
                $opts[Options::DEBUG_TRACE] = true;
            }

            if ($input->getOption('timeout')) {
                $opts['client']['timeout'] = $input->getOption('timeout');
            }

            $server['options'] = $opts;
            $server['class'] = makeServer($server, $name);

            $after = ag($server, 'import.lastSync', null);

            if (true === (bool)ag($opts, Options::FORCE_FULL, false) || true === $input->getOption('force-full')) {
                $after = null;
            }

            if (null === $after) {
                $this->logger->notice(
                    sprintf('%s: Importing play state changes since beginning.', $name)
                );
            } else {
                $after = makeDate($after);
                $this->logger->notice(
                    sprintf(
                        '%s: Importing play state changes since \'%s\'.',
                        $name,
                        $after->format('Y-m-d H:i:s T')
                    )
                );
            }

            array_push($queue, ...$server['class']->pull($this->mapper, $after));

            if (true === Data::get(sprintf('%s.no_import_update', $name))) {
                $this->logger->notice(sprintf('%s: Not updating last sync date. Backend reported an error.', $name));
            } else {
                if (false === $this->mapper->inDryRunMode()) {
                    Config::save(sprintf('servers.%s.import.lastSync', $name), time());
                }
            }
        }

        unset($server);

        $this->logger->notice(sprintf('HTTP: Waiting on \'%d\' external requests.', count($queue)));

        if ($inTradeMode) {
            $this->logger->notice(
                sprintf('SYSTEM: Memory Usage (Now: %s) - (Peak: %s).', getMemoryUsage(), getPeakMemoryUsage())
            );
        }

        foreach ($queue as $_key => $response) {
            if ($inTradeMode) {
                $this->logger->notice(
                    sprintf('SYSTEM: Memory Usage (Now: %s) - (Peak: %s).', getMemoryUsage(), getPeakMemoryUsage())
                );
            }

            $requestData = $response->getInfo('user_data');

            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }

            $queue[$_key] = null;

            gc_collect_cycles();
        }

        $this->logger->notice('HTTP: Finished waiting on external requests.');

        if ($inTradeMode) {
            $this->logger->notice(
                sprintf('SYSTEM: Memory Usage (Now: %s) - (Peak: %s).', getMemoryUsage(), getPeakMemoryUsage())
            );
        }

        $queue = $requestData = null;

        $total = count($this->mapper);

        if ($total >= 1) {
            $this->logger->notice(sprintf('MAPPER: Updating \'%d\' items.', $total));
            if ($inTradeMode) {
                $this->logger->notice(
                    sprintf('SYSTEM: Memory Usage (Now: %s) - (Peak: %s).', getMemoryUsage(), getPeakMemoryUsage())
                );
            }
        }

        $operations = $this->mapper->commit();

        $a = [
            [
                'Type' => ucfirst(StateInterface::TYPE_MOVIE),
                'Added' => $operations[StateInterface::TYPE_MOVIE]['added'] ?? '-',
                'Updated' => $operations[StateInterface::TYPE_MOVIE]['updated'] ?? '-',
                'Failed' => $operations[StateInterface::TYPE_MOVIE]['failed'] ?? '-',
            ],
            new TableSeparator(),
            [
                'Type' => ucfirst(StateInterface::TYPE_EPISODE),
                'Added' => $operations[StateInterface::TYPE_EPISODE]['added'] ?? '-',
                'Updated' => $operations[StateInterface::TYPE_EPISODE]['updated'] ?? '-',
                'Failed' => $operations[StateInterface::TYPE_EPISODE]['failed'] ?? '-',
            ],
        ];

        (new Table($output))->setHeaders(array_keys($a[0]))->setStyle('box')->setRows(array_values($a))->render();

        if (false === $input->getOption('dry-run')) {
            foreach ($list as $server) {
                if (null === ($name = ag($server, 'name'))) {
                    continue;
                }

                Config::save(sprintf('servers.%s.persist', $name), $server['class']->getPersist());
            }

            if (false === $custom && is_writable(dirname($config))) {
                copy($config, $config . '.bak');
            }

            file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));
        }

        if ($inTradeMode) {
            $this->logger->notice(
                sprintf('SYSTEM: Memory Usage (Now: %s) - (Peak: %s).', getMemoryUsage(), getPeakMemoryUsage())
            );
        }

        return self::SUCCESS;
    }
}
