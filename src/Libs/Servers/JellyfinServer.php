<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use Closure;
use DateTimeInterface;
use JsonException;
use JsonMachine\Items;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use StdClass;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class JellyfinServer implements ServerInterface
{
    private const GUID_MAPPER = [
        'plex' => Guid::GUID_PLEX,
        'imdb' => Guid::GUID_IMDB,
        'tmdb' => Guid::GUID_TMDB,
        'tvdb' => Guid::GUID_TVDB,
        'tvmaze' => Guid::GUID_TVMAZE,
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
    ];

    protected const WEBHOOK_ALLOWED_TYPES = [
        'Movie',
        'Episode',
    ];

    protected const WEBHOOK_ALLOWED_EVENTS = [
        'ItemAdded',
        'UserDataSaved',
    ];

    protected const WEBHOOK_TAINTED_EVENTS = [
        'PlaybackStart',
        'PlaybackStop',
    ];

    protected UriInterface|null $url = null;
    protected string|null $token = null;
    protected string|null $user = null;
    protected array $options = [];
    protected string $name = '';
    protected bool $loaded = false;
    protected bool $isEmby = false;
    protected array $persist = [];
    protected string $cacheKey;
    protected array $cacheData = [];

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function setUp(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        string|int|null $userId = null,
        array $persist = [],
        array $options = []
    ): ServerInterface {
        return (new self($this->http, $this->logger, $this->cache))->setState(
            $name,
            $url,
            $token,
            $userId,
            $persist,
            $options
        );
    }

    public function getPersist(): array
    {
        return $this->persist;
    }

    public function addPersist(string $key, mixed $value): ServerInterface
    {
        $this->persist = ag_set($this->persist, $key, $value);
        return $this;
    }

    public function setLogger(LoggerInterface $logger): ServerInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public static function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

        if (!str_starts_with($userAgent, 'Jellyfin-Server/')) {
            return $request;
        }

        $body = clone $request->getBody();

        if (null === ($json = json_decode((string)$body, true))) {
            return $request;
        }

        $attributes = [
            'SERVER_ID' => ag($json, 'ServerId', ''),
            'SERVER_NAME' => ag($json, 'ServerName', ''),
            'SERVER_VERSION' => afterLast($userAgent, '/'),
            'USER_ID' => ag($json, 'UserId', ''),
            'USER_NAME' => ag($json, 'NotificationUsername', ''),
        ];

        foreach ($attributes as $key => $val) {
            $request = $request->withAttribute($key, $val);
        }

        return $request;
    }

    public function parseWebhook(ServerRequestInterface $request): StateInterface
    {
        if (null === ($json = json_decode($request->getBody()->getContents(), true))) {
            throw new HttpException('No payload.', 400);
        }

        $event = ag($json, 'NotificationType', 'unknown');
        $type = ag($json, 'ItemType', 'not_found');

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('Not allowed Type [%s]', $type), 200);
        }

        $type = strtolower($type);

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed Event [%s]', afterLast(__CLASS__, '\\'), $event), 200);
        }

        $date = time();

        $meta = match ($type) {
            StateInterface::TYPE_MOVIE => [
                'via' => $this->name,
                'title' => ag($json, 'Name', '??'),
                'year' => ag($json, 'Year', 0000),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            StateInterface::TYPE_EPISODE => [
                'via' => $this->name,
                'series' => ag($json, 'SeriesName', '??'),
                'year' => ag($json, 'Year', 0000),
                'season' => ag($json, 'SeasonNumber', 0),
                'episode' => ag($json, 'EpisodeNumber', 0),
                'title' => ag($json, 'Name', '??'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            default => throw new HttpException('Invalid content type.', 400),
        };

        $guids = [];

        foreach ($json as $key => $val) {
            if (str_starts_with($key, 'Provider_')) {
                $guids[self::afterString($key, 'Provider_')] = $val;
            }
        }

        $guids = self::getGuids($type, $guids);

        foreach (Guid::fromArray($guids)->getPointers() as $guid) {
            $this->cacheData[$guid] = ag($json, 'Item.ItemId');
        }

        $isWatched = (int)(bool)ag($json, 'Played', ag($json, 'PlayedToCompletion', 0));

        $row = [
            'type' => $type,
            'updated' => $date,
            'watched' => $isWatched,
            'meta' => $meta,
            ...$guids
        ];

        if (true === Config::get('webhook.debug')) {
            saveWebhookPayload($request, "{$this->name}.{$event}", $json + ['entity' => $row]);
        }

        return Container::get(StateInterface::class)::fromArray($row)->setIsTainted(
            in_array($event, self::WEBHOOK_TAINTED_EVENTS)
        );
    }

    protected function getHeaders(): array
    {
        $opts = [
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        if (true === $this->isEmby) {
            $opts['headers']['X-MediaBrowser-Token'] = $this->token;
        } else {
            $opts['headers']['X-Emby-Authorization'] = sprintf(
                'MediaBrowser Client="%s", Device="script", DeviceId="", Version="%s", Token="%s"',
                Config::get('name'),
                Config::get('version'),
                $this->token
            );
        }

        return array_replace_recursive($opts, $this->options['client'] ?? []);
    }

    protected function getLibraries(Closure $ok, Closure $error): array
    {
        if (!($this->url instanceof UriInterface)) {
            throw new RuntimeException('No host was set.');
        }

        if (null === $this->token) {
            throw new RuntimeException('No token was set.');
        }

        if (null === $this->user) {
            throw new RuntimeException('No User was set.');
        }

        try {
            $this->logger->debug(
                sprintf('Requesting libraries From %s.', $this->name),
                ['url' => $this->url->getHost()]
            );

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'Recursive' => 'false',
                        'Fields' => 'ProviderIds',
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                    ]
                )
            );

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            $content = $response->getContent(false);

            $this->logger->debug(sprintf('===[ Sample from %s List library response ]===', $this->name));
            $this->logger->debug(!empty($content) ? mb_substr($content, 0, 200) : 'Empty response body');
            $this->logger->debug('===[ End ]===');

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        'Request to %s responded with unexpected code (%d).',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
                Data::add($this->name, 'no_import_update', true);
                return [];
            }

            $json = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            unset($content);

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->notice(sprintf('No libraries found at %s.', $this->name));
                Data::add($this->name, 'no_import_update', true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
            Data::add($this->name, 'no_import_update', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('Unable to decode %s response. Reason: \'%s\'.', $this->name, $e->getMessage())
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        $ignoreIds = null;

        if (null !== ($this->options['ignore'] ?? null)) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', $this->options['ignore']));
        }

        $promises = [];
        $ignored = $unsupported = 0;

        foreach ($listDirs as $section) {
            $key = (string)ag($section, 'Id');
            $title = ag($section, 'Name', '???');
            $type = ag($section, 'CollectionType', 'unknown');

            if ('movies' !== $type && 'tvshows' !== $type) {
                $unsupported++;
                $this->logger->debug(sprintf('Skipping %s library - %s. Not supported type.', $this->name, $title));

                continue;
            }

            $type = $type === 'movies' ? StateInterface::TYPE_MOVIE : StateInterface::TYPE_EPISODE;
            $cName = sprintf('(%s) - (%s:%s)', $title, $type, $key);

            if (null !== $ignoreIds && in_array($key, $ignoreIds, true)) {
                $ignored++;
                $this->logger->notice(
                    sprintf('Skipping %s library - %s. Ignored by user config option.', $this->name, $cName)
                );
                continue;
            }

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'parentId' => $key,
                        'recursive' => 'true',
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                        'includeItemTypes' => 'Movie,Episode',
                        'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved',
                    ]
                )
            );

            $this->logger->debug(sprintf('Requesting %s - %s library content.', $this->name, $cName), ['url' => $url]);

            try {
                $promises[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'ok' => $ok($cName, $type, $url),
                            'error' => $error($cName, $type, $url),
                        ]
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    sprintf('Request to %s library - %s failed. Reason: %s', $this->name, $cName, $e->getMessage()),
                    ['url' => $url]
                );
                continue;
            }
        }

        if (0 === count($promises)) {
            $this->logger->notice(
                sprintf(
                    'No requests were made to any of %s libraries. (total: %d, ignored: %d, Unsupported: %d).',
                    $this->name,
                    count($listDirs),
                    $ignored,
                    $unsupported
                )
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        return $promises;
    }

    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            function (string $cName, string $type) use ($after, $mapper) {
                return function (ResponseInterface $response) use ($mapper, $cName, $type, $after) {
                    try {
                        if (200 !== $response->getStatusCode()) {
                            $this->logger->error(
                                sprintf(
                                    'Request to %s - %s responded with (%d) unexpected code.',
                                    $this->name,
                                    $cName,
                                    $response->getStatusCode()
                                )
                            );
                            return;
                        }

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/Items',
                            ],
                        );

                        $this->logger->notice(sprintf('Parsing Successful %s - %s response.', $this->name, $cName));
                        foreach ($it as $entity) {
                            $this->processImport($mapper, $type, $cName, $entity, $after);
                        }
                        $this->logger->notice(
                            sprintf(
                                'Finished Parsing %s - %s (%d objects) response.',
                                $this->name,
                                $cName,
                                Data::get("{$this->name}.{$type}_total")
                            )
                        );
                    } catch (JsonException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to decode %s - %s - response. Reason: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            )
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    ['url' => $url]
                );
            }
        );
    }

    public function push(array $entities, DateTimeInterface|null $after = null): array
    {
        $requests = [];

        foreach ($entities as &$entity) {
            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    $entity = null;
                    continue;
                }
            }

            $entity->jf_id = null;
            $entity->plex_guid = null;

            if (!$entity->hasGuids()) {
                continue;
            }

            foreach ($entity->getPointers() as $guid) {
                if (null === ($this->cacheData[$guid] ?? null)) {
                    continue;
                }
                $entity->jf_id = $this->cacheData[$guid];
                break;
            }
        }

        unset($entity);

        foreach ($entities as $entity) {
            if (StateInterface::TYPE_MOVIE === $entity->type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $this->name,
                    $entity->meta['title'] ?? '??',
                    $entity->meta['year'] ?? 0000,
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%dx%d) - %s]',
                        $this->name,
                        $entity->meta['series'] ?? '??',
                        $entity->meta['season'] ?? 0,
                        $entity->meta['episode'] ?? 0,
                        $entity->meta['title'] ?? '??',
                    )
                );
            }

            if (null === ($entity->jf_id ?? null)) {
                $this->logger->notice(sprintf('Ignoring %s. Not found in cache.', $iName));
                continue;
            }

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$this->url->withPath(sprintf('/Users/%s/items', $this->user))->withQuery(
                        http_build_query(
                            [
                                'ids' => $entity->jf_id,
                                'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved',
                                'enableUserData' => 'true',
                                'enableImages' => 'false',
                            ]
                        )
                    ),
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'state' => &$entity,
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }

        $stateRequests = [];

        foreach ($requests as $response) {
            try {
                $json = ag(
                        json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
                        'Items',
                        []
                    )[0] ?? [];

                $state = $response->getInfo('user_data')['state'];
                assert($state instanceof StateInterface);

                if (StateInterface::TYPE_MOVIE === $state->type) {
                    $iName = sprintf(
                        '%s - [%s (%d)]',
                        $this->name,
                        $state->meta['title'] ?? '??',
                        $state->meta['year'] ?? 0000,
                    );
                } else {
                    $iName = trim(
                        sprintf(
                            '%s - [%s - (%dx%d) - %s]',
                            $this->name,
                            $state->meta['series'] ?? '??',
                            $state->meta['season'] ?? 0,
                            $state->meta['episode'] ?? 0,
                            $state->meta['title'] ?? '??',
                        )
                    );
                }

                if (empty($json)) {
                    $this->logger->notice(sprintf('Ignoring %s. does not exists.', $iName));
                    continue;
                }

                $isWatched = (int)(bool)ag($json, 'UserData.Played', false);

                $date = ag($json, 'UserData.LastPlayedDate', ag($json, 'DateCreated', ag($json, 'PremiereDate', null)));

                if (null === $date) {
                    $this->logger->notice(sprintf('Ignoring %s. No date is set.', $iName));
                    continue;
                }

                $date = strtotime($date);

                if ($state->watched === $isWatched) {
                    $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                    continue;
                }

                if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                    if ($date >= $state->updated) {
                        $this->logger->debug(sprintf('Ignoring %s. Date is newer then what in db.', $iName));
                        continue;
                    }
                }

                $stateRequests[] = $this->http->request(
                    1 === $state->watched ? 'POST' : 'DELETE',
                    (string)$this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($json, 'Id'))),
                    array_replace_recursive(
                        $this->getHeaders(),
                        [
                            'user_data' => [
                                'state' => 1 === $state->watched ? 'Watched' : 'Unwatched',
                                'itemName' => $iName,
                            ],
                        ]
                    )
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }

        unset($requests);

        return $stateRequests;
    }

    public function export(ExportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            function (string $cName, string $type) use ($mapper, $after) {
                return function (ResponseInterface $response) use ($mapper, $cName, $type, $after) {
                    try {
                        if (200 !== $response->getStatusCode()) {
                            $this->logger->error(
                                sprintf(
                                    'Request to %s - %s responded with (%d) unexpected code.',
                                    $this->name,
                                    $cName,
                                    $response->getStatusCode()
                                )
                            );
                            return;
                        }

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/Items',
                            ],
                        );

                        $this->logger->notice(sprintf('Parsing Successful %s - %s response.', $this->name, $cName));
                        foreach ($it as $entity) {
                            $this->processExport($mapper, $type, $cName, $entity, $after);
                        }
                        $this->logger->notice(
                            sprintf(
                                'Finished Parsing %s - %s (%d objects) response.',
                                $this->name,
                                $cName,
                                Data::get("{$this->name}.{$type}_total")
                            )
                        );
                    } catch (JsonException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to decode %s - %s - response. Reason: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            )
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    ['url' => $url]
                );
            }
        );
    }

    protected function processExport(
        ExportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        Data::increment($this->name, $type . '_total');

        try {
            if (StateInterface::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - %s - [%s (%d)]',
                    $this->name,
                    $library,
                    $item->Name ?? $item->OriginalTitle ?? '??',
                    $item->ProductionYear ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - %s - [%s - (%dx%d) - %s]',
                        $this->name,
                        $library,
                        $item->SeriesName ?? '??',
                        $item->ParentIndexNumber ?? 0,
                        $item->IndexNumber ?? 0,
                        $item->Name ?? ''
                    )
                );
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $date = strtotime($date);

            if (null !== $after && $date >= $after->getTimestamp()) {
                $this->logger->debug(sprintf('Ignoring %s. Ignored date is equal or newer than lastSync.', $iName));
                Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                return;
            }

            if (!$this->hasSupportedIds((array)($item->ProviderIds ?? []))) {
                $this->logger->debug(
                    sprintf('Ignoring %s. No supported guid.', $iName),
                    (array)($item->ProviderIds ?? [])
                );
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            $guids = self::getGuids($type, (array)($item->ProviderIds ?? []));

            if (null === ($entity = $mapper->findByIds($guids))) {
                $this->logger->debug(
                    sprintf('Ignoring %s. Not found in db.', $iName),
                    (array)($item->ProviderIds ?? [])
                );
                Data::increment($this->name, $type . '_ignored_not_found_in_db');
                return;
            }

            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if ($date >= $entity->updated) {
                    $this->logger->debug(sprintf('Ignoring %s. Date is newer then what in db.', $iName));
                    Data::increment($this->name, $type . '_ignored_date_is_newer');
                    return;
                }
            }

            $isWatched = (int)(bool)($item->UserData?->Played ?? false);

            if ($isWatched === $entity->watched) {
                $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                Data::increment($this->name, $type . '_ignored_state_unchanged');
                return;
            }

            $this->logger->debug(sprintf('Queuing %s.', $iName));

            $mapper->queue(
                $this->http->request(
                    1 === $entity->watched ? 'POST' : 'DELETE',
                    (string)$this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, $item->Id)),
                    array_replace_recursive(
                        $this->getHeaders(),
                        [
                            'user_data' => [
                                'state' => 1 === $entity->watched ? 'Watched' : 'Unwatched',
                                'itemName' => $iName,
                            ],
                        ]
                    )
                )
            );
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
        }
    }

    protected function processImport(
        ImportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        try {
            Data::increment($this->name, $type . '_total');

            if (StateInterface::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - %s - [%s (%d)]',
                    $this->name,
                    $library,
                    $item->Name ?? $item->OriginalTitle ?? '??',
                    $item->ProductionYear ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - %s - [%s - (%dx%d) - %s]',
                        $this->name,
                        $library,
                        $item->SeriesName ?? '??',
                        $item->ParentIndexNumber ?? 0,
                        $item->IndexNumber ?? 0,
                        $item->Name ?? ''
                    )
                );
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $date = strtotime($date);

            if (null !== $after && $date >= $after->getTimestamp()) {
                $this->logger->debug(sprintf('Ignoring %s. date is equal or newer than lastSync.', $iName));
                Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                return;
            }

            if (!$this->hasSupportedIds((array)($item->ProviderIds ?? []))) {
                $this->logger->debug(
                    sprintf('Ignoring %s. No valid GUIDs.', $iName),
                    (array)($item->ProviderIds ?? [])
                );
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            if (StateInterface::TYPE_MOVIE === $type) {
                $meta = [
                    'via' => $this->name,
                    'title' => $item->Name ?? $item->OriginalTitle ?? '??',
                    'year' => $item->ProductionYear ?? 0000,
                    'date' => makeDate($item->PremiereDate ?? $item->ProductionYear ?? 'now')->format('Y-m-d'),
                ];
            } else {
                $meta = [
                    'via' => $this->name,
                    'series' => $item->SeriesName ?? '??',
                    'year' => $item->ProductionYear ?? 0000,
                    'season' => $item->ParentIndexNumber ?? 0,
                    'episode' => $item->IndexNumber ?? 0,
                    'title' => $item->Name ?? '',
                    'date' => makeDate($item->PremiereDate ?? $item->ProductionYear ?? 'now')->format('Y-m-d'),
                ];
            }

            $guids = self::getGuids($type, (array)($item->ProviderIds ?? []));

            foreach (Guid::fromArray($guids)->getPointers() as $guid) {
                $this->cacheData[$guid] = $item->Id;
            }

            $row = [
                'type' => $type,
                'updated' => $date,
                'watched' => (int)(bool)($item->UserData?->Played ?? false),
                'meta' => $meta,
                ...$guids,
            ];

            $mapper->add($this->name, $iName, Container::get(StateInterface::class)::fromArray($row), [
                'after' => $after,
                self::OPT_IMPORT_UNWATCHED => (bool)($this->options[self::OPT_IMPORT_UNWATCHED] ?? false),
            ]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
        }
    }

    protected static function getGuids(string $type, array $ids): array
    {
        $guid = [];

        $ids = array_change_key_case($ids, CASE_LOWER);

        foreach ($ids as $key => $value) {
            if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                continue;
            }

            if ($key !== 'plex') {
                $value = $type . '/' . $value;
            }

            if ('string' !== Guid::SUPPORTED[self::GUID_MAPPER[$key]]) {
                settype($value, Guid::SUPPORTED[self::GUID_MAPPER[$key]]);
            }

            $guid[self::GUID_MAPPER[$key]] = $value;
        }

        return $guid;
    }

    protected function hasSupportedIds(array $ids): bool
    {
        $ids = array_change_key_case($ids, CASE_LOWER);

        foreach ($ids as $key => $value) {
            if (null !== (self::GUID_MAPPER[$key] ?? null) && !empty($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __destruct()
    {
        if (!empty($this->cacheData)) {
            $this->cache->set($this->cacheKey, $this->cacheData);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function setState(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        string|int|null $userId = null,
        array $persist = [],
        array $opts = []
    ): ServerInterface {
        if (true === $this->loaded) {
            throw new RuntimeException('setState: already called once');
        }

        if (null === $userId && null === ($opts['user'] ?? null)) {
            throw new RuntimeException('Jellyfin/Emby media servers: require userId to be set.');
        }

        $this->cacheKey = md5(__CLASS__ . '.' . $name . $userId . $url);

        if ($this->cache->has($this->cacheKey)) {
            $this->cacheData = $this->cache->get($this->cacheKey);
        }

        $this->name = $name;
        $this->url = $url;
        $this->token = $token;
        $this->user = $userId ?? $opts['user'];
        $this->persist = $persist;
        $this->isEmby = (bool)($opts['emby'] ?? false);

        if (null !== ($opts['emby'] ?? null)) {
            unset($opts['emby']);
        }

        $this->options = $opts;
        $this->loaded = true;

        return $this;
    }

    protected static function afterString(string $subject, string $search): string
    {
        return empty($search) ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }
}
