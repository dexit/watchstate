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
            ->setDescription('Import play state and metadata from backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full import. Ignore last sync date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any changes.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('servers-filter', 's', InputOption::VALUE_OPTIONAL, 'Select backends. Comma (,) seperated.', '')
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --servers-filter logic.')
            ->addOption(
                'always-update-metadata',
                null,
                InputOption::VALUE_NONE,
                'Mapper option. Always update the locally stored metadata from backend.'
            )
            ->addOption(
                'direct-mapper',
                null,
                InputOption::VALUE_NONE,
                'Direct mapper is memory efficient, However its slower than the default mapper.'
            )
            ->addOption(
                'metadata-only',
                null,
                InputOption::VALUE_NONE,
                'import metadata changes only. Works when there are records in storage.'
            )
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->setAliases(['import', 'pull']);
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
            $this->mapper = new DirectMapper(logger: $this->logger, storage: $this->storage);
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));
            $metadata = false;

            if ($isCustom && $input->getOption('exclude') === in_array($serverName, $selected)) {
                $this->logger->info('SYSTEM: Ignoring [%(backend)] as requested by servers filter flag.', [
                    'backend' => $serverName,
                ]);
                continue;
            }

            // -- sanity check in case user has both import.enabled and options.IMPORT_METADATA_ONLY enabled.
            if (true === (bool)ag($server, 'import.enabled')) {
                if (true === ag_exists($server, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                    $server = ag_delete($server, 'options.' . Options::IMPORT_METADATA_ONLY);
                }
            }

            if (true === (bool)ag($server, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $metadata = true;
            }

            if (true === $input->getOption('metadata-only')) {
                $metadata = true;
            }

            if (true !== (bool)ag($server, 'import.enabled') && true !== $metadata) {
                $this->logger->info('SYSTEM: Ignoring [%(backend)] imports are disabled for this backend.', [
                    'backend' => $serverName,
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error('SYSTEM: Ignoring [%(backend)] because of the unexpected type [%(type)].', [
                    'type' => $type,
                    'backend' => $serverName,
                ]);
                continue;
            }

            if (null === ($url = ag($server, 'url')) || false === filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->error('SYSTEM: Ignoring [%(backend)] because of invalid URL.', [
                    'backend' => $serverName,
                    'url' => $url ?? 'None',
                ]);
                continue;
            }

            $server['name'] = $serverName;
            $list[$serverName] = $server;
        }

        if (empty($list)) {
            // -- @RELEASE - expand this message to account for filtering, import status etc.
            $this->logger->warning('No backends were found');
            return self::FAILURE;
        }

        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        $this->logger->info(sprintf('Using WatchState Version - \'%s\'.', getAppVersion()));

        $this->logger->notice('SYSTEM: Preloading %(mapper) data.', [
            'mapper' => afterLast($this->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $this->mapper->loadData();

        $this->logger->notice('SYSTEM: Preloading %(mapper) data is complete.', [
            'mapper' => afterLast($this->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $this->storage->singleTransaction();

        foreach ($list as $name => &$server) {
            Data::addBucket($name);
            $metadata = false;
            $opts = ag($server, 'options', []);

            if (true === (bool)ag($server, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $opts[Options::IMPORT_METADATA_ONLY] = true;
                $metadata = true;
            }

            if (true === $input->getOption('metadata-only')) {
                $opts[Options::IMPORT_METADATA_ONLY] = true;
                $metadata = true;
            }

            if ($input->getOption('trace')) {
                $opts[Options::DEBUG_TRACE] = true;
            }

            if ($input->getOption('timeout')) {
                $opts['client']['timeout'] = (float)$input->getOption('timeout');
            }

            $server['options'] = $opts;
            $server['class'] = makeServer($server, $name);

            $after = ag($server, 'import.lastSync', null);

            if (true === (bool)ag($opts, Options::FORCE_FULL, false) || true === $input->getOption('force-full')) {
                $after = null;
            }

            if (null !== $after) {
                $after = makeDate($after);
            }

            $this->logger->notice('SYSTEM: Importing [%(backend)] %(import_type) changes.', [
                'backend' => $name,
                'import_type' => true === $metadata ? 'METADATA ONLY' : 'METADATA & PLAY STATE',
                'since' => null === $after ? 'Beginning' : $after->format('Y-m-d H:i:s T'),
            ]);

            array_push($queue, ...$server['class']->pull($this->mapper, $after));

            $inDryMode = $this->mapper->inDryRunMode() || ag($server, 'options.' . Options::DRY_RUN);

            if (false === (bool)Data::get("{$name}.has_errors", false) && false === $inDryMode) {
                Config::save(sprintf('servers.%s.import.lastSync', $name), time());
            } else {
                $this->logger->warning(
                    'SYSTEM: Not updating last import date for [%(backend)]. Backend reported an error.',
                    [
                        'backend' => $name,
                    ]
                );
            }
        }

        unset($server);

        $start = makeDate();
        $this->logger->notice('SYSTEM: Waiting on [%(total)] requests.', [
            'total' => number_format(count($queue)),
            'time' => [
                'start' => $start,
            ],
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        foreach ($queue as $_key => $response) {
            $requestData = $response->getInfo('user_data');

            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }

            $queue[$_key] = null;

            gc_collect_cycles();
        }

        $end = makeDate();

        $this->logger->notice('SYSTEM: Finished waiting on [%(total)] requests.', [
            'total' => number_format(count($queue)),
            'time' => [
                'start' => $start,
                'end' => $end,
                'duration' => $end->getTimestamp() - $start->getTimestamp(),
            ],
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $queue = $requestData = null;

        $total = count($this->mapper);

        if ($total >= 1) {
            $this->logger->notice('SYSTEM: Found [%(total)] updated objects.', [
                'total' => $total,
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
            ]);
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
            if (false === $custom && is_writable(dirname($config))) {
                copy($config, $config . '.bak');
            }

            file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));
        }

        return self::SUCCESS;
    }
}
