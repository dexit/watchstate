<?php

declare(strict_types=1);

namespace App\API\Backends\Library;

use App\API\Backends\Index as BackendsIndex;
use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\DataUtil;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Index
{
    use APITraits;

    #[Get(BackendsIndex::URL . '/{name:backend}/library[/]', name: 'backends.library.list')]
    public function listLibraries(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('No backend was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->getClient(name: $name);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $response = [
            'type' => ag(array_flip(Config::get('supported')), $client::class),
            'libraries' => $client->listLibraries(),
            'links' => [
                'self' => (string)parseConfigValue(BackendsIndex::URL . "/{$name}/library"),
                'backend' => (string)parseConfigValue(BackendsIndex::URL . "/{$name}"),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    #[Post(BackendsIndex::URL . '/{name:backend}/library[/]', name: 'backends.library.ignore')]
    public function ignoreLibrary(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('No backend was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($id = DataUtil::fromRequest($request)->get('id', null))) {
            return api_error('No library id was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $remove = 'DELETE' === $request->getMethod();

        $config = ConfigFile::open(Config::get('backends_file'), 'yaml');

        if (null === $config->get($name)) {
            return api_error(r("Backend '{backend}' not found.", ['backend' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $ignoreIds = array_map(
            fn($v) => trim($v),
            explode(',', (string)$config->get("{$name}.options." . Options::IGNORE, ''))
        );

        $mode = !(true === $remove);
        if ($mode === in_array($id, $ignoreIds)) {
            return api_error(r("Library id '{id}' is {message} ignored.", [
                'id' => $id,
                'message' => $remove ? "not" : 'already',
            ]), $remove ? HTTP_STATUS::HTTP_NOT_FOUND : HTTP_STATUS::HTTP_CONFLICT);
        }

        $found = false;

        $libraries = $this->getClient(name: $name)->listLibraries();
        foreach ($libraries as &$library) {
            if ((string)ag($library, 'id') === (string)$id) {
                $ignoreIds[] = $id;
                $library['ignored'] = !$remove;
                $found = true;
                break;
            }
        }

        if (false === $found) {
            return api_error(r("The library id '{id}' is incorrect.", ['id' => $name]), HTTP_STATUS::HTTP_NOT_FOUND, [
                'possible_ids' => array_column($libraries, 'id'),
            ]);
        }

        if (true === $remove) {
            $ignoreIds = array_diff($ignoreIds, [$id]);
        }

        $config->set("{$name}.options." . Options::IGNORE, implode(',', array_values($ignoreIds)))->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'type' => $config->get("{$name}.type"),
            'libraries' => $libraries,
            'links' => [
                'self' => (string)parseConfigValue(BackendsIndex::URL . "/{$name}/library"),
                'backend' => (string)parseConfigValue(BackendsIndex::URL . "/{$name}"),
            ],
        ]);
    }

    #[Delete(BackendsIndex::URL . '/{name:backend}/library/{id}[/]', name: 'backends.library.ignore.delete')]
    public function deleteIgnoreLibrary(iRequest $request, array $args = []): iResponse
    {
        return $this->ignoreLibrary($request->withParsedBody(['id' => ag($args, 'id')]), $args);
    }
}
