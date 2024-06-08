<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexClient;
use App\Backends\Plex\PlexGuid;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class SearchQuery
{
    use CommonTrait;
    use PlexActionTrait;

    private string $action = 'plex.searchQuery';

    public function __construct(
        protected iHttp $http,
        protected iLogger $logger,
        private iDB $db,
        private PlexGuid $plexGuid
    ) {
    }

    /**
     * Get Users list.
     *
     * @param Context $context
     * @param string $query
     * @param int $limit
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string $query, int $limit = 25, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->search($context, $query, $limit, $opts),
            action: $this->action
        );
    }

    /**
     * Search Backend Titles.
     *
     * @throws ExceptionInterface if the request failed
     * @throws JsonException if the response cannot be parsed
     * @throws RuntimeException
     */
    private function search(Context $context, string $query, int $limit = 25, array $opts = []): Response
    {
        $url = $context->backendUrl->withPath('/hubs/search')->withQuery(
            http_build_query(
                array_replace_recursive(
                    [
                        'query' => $query,
                        'limit' => $limit,
                        'includeGuids' => 1,
                        'includeExternalMedia' => 0,
                        'includeCollections' => 0,
                    ],
                    $opts['query'] ?? []
                )
            )
        );

        $this->logger->debug('Searching [{backend}] libraries for [{query}].', [
            'backend' => $context->backendName,
            'query' => $query,
            'url' => $url
        ]);

        $response = $this->http->request(
            'GET',
            (string)$url,
            array_replace_recursive($context->backendHeaders, $opts['headers'] ?? [])
        );

        $this->logger->debug('Requesting [{backend}] Users list.', [
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Search request for [{query}] in [{backend}] returned with unexpected [{status_code}] status code.',
                    context: [
                        'backend' => $context->backendName,
                        'query' => $query,
                        'status_code' => $response->getStatusCode(),
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug('Parsing Searching [{backend}] libraries for [{query}] payload.', [
                'backend' => $context->backendName,
                'query' => $query,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        $list = [];

        $plexGuid = $this->plexGuid->withContext($context);

        foreach (ag($json, 'MediaContainer.Hub', []) as $leaf) {
            $type = strtolower(ag($leaf, 'type', ''));

            if (false === in_array($type, [PlexClient::TYPE_EPISODE, PlexClient::TYPE_MOVIE])) {
                continue;
            }

            foreach (ag($leaf, 'Metadata', []) as $item) {
                try {
                    $entity = $this->createEntity($context, $plexGuid, $item, $opts);
                } catch (\Throwable $e) {
                    $this->logger->error('Error creating entity: {error}', ['error' => $e->getMessage()]);
                    continue;
                }

                if (null !== ($localEntity = $this->db->get($entity))) {
                    $entity->id = $localEntity->id;
                }

                $builder = $entity->getAll();
                $builder['url'] = (string)$this->getWebUrl(
                    $context,
                    $entity->type,
                    (int)ag(
                        $entity->getMetadata($entity->via),
                        iState::COLUMN_ID
                    )
                );

                $builder[iState::COLUMN_TITLE] = ag(
                    $entity->getMetadata($entity->via),
                    iState::COLUMN_EXTRA . '.' . iState::COLUMN_TITLE,
                    $entity->title
                );
                $builder['full_title'] = $entity->getName();
                $builder[iState::COLUMN_META_PATH] = ag($entity->getMetadata($entity->via), iState::COLUMN_META_PATH);

                if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                    $builder['raw'] = $item;
                }

                $list[] = $builder;
            }
        }

        return new Response(status: true, response: $list);
    }
}
