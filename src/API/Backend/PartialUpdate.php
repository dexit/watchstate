<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Patch;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use JsonException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class PartialUpdate
{
    use APITraits;

    #[Patch(Index::URL . '/{name:backend}[/]', name: 'backends.view')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        try {
            $data = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return api_error(r('Invalid JSON data. {error}', ['error' => $e->getMessage()]),
                HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        foreach ($data as $update) {
            if (!ag_exists($update, 'key')) {
                return api_error('No key to update was present.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            $list->set($name . '.' . ag($update, 'key'), ag($update, 'value'));
        }

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');

        $list->persist();

        $backend = $this->getBackends(name: $name);

        if (empty($backend)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }
        $backend = array_pop($backend);

        return api_response(HTTP_STATUS::HTTP_OK, [
            'backend' => array_filter(
                $backend,
                fn($key) => false === in_array($key, ['options', 'webhook'], true),
                ARRAY_FILTER_USE_KEY
            ),
            'links' => [
                'self' => (string)$apiUrl,
                'list' => (string)$apiUrl->withPath(parseConfigValue(Index::URL)),
            ],
        ]);
    }
}
