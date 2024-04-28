<?php

declare(strict_types=1);

namespace App\API\Backends\Library;

use App\API\Backends\Index as BackendsIndex;
use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Unmatched
{
    use APITraits;

    #[Get(BackendsIndex::URL . '/{name:backend}/unmatched[/[{id}[/]]]', name: 'backends.library.unmatched')]
    public function listLibraries(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('No backend was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $backendOpts = $opts = $list = [];

        if ($params->get('timeout')) {
            $backendOpts = ag_set($backendOpts, 'client.timeout', (float)$params->get('timeout'));
        }

        if ($params->get('raw')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        try {
            $client = $this->getClient(name: $name, config: $backendOpts);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $ids = [];
        if (null !== ($id = ag($args, 'id'))) {
            $ids[] = $id;
        } else {
            foreach ($client->listLibraries() as $library) {
                if (false === (bool)ag($library, 'supported') || true === (bool)ag($library, 'ignored')) {
                    continue;
                }
                $ids[] = ag($library, 'id');
            }
        }

        foreach ($ids as $libraryId) {
            foreach ($client->getLibrary(id: $libraryId, opts: $opts) as $item) {
                if (null === ($externals = ag($item, 'guids', null)) || empty($externals)) {
                    $list[] = $item;
                }
            }
        }

        $response = [
            'items' => $list,
            'links' => [
                'self' => (string)$request->getUri()->withHost('')->withPort(0)->withScheme(''),
                'backend' => (string)parseConfigValue(BackendsIndex::URL . "/{$name}"),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }
}
