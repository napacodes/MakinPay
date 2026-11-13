<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Api\OpenApiGenerator;
use Illuminate\Console\Command;

/**
 * Write the OpenAPI document to disk, for the Flutter client's code
 * generation step.
 *
 * A file rather than only the endpoint, because a build pipeline that has to
 * boot the application and make an HTTP request to read its own specification
 * is a pipeline that breaks whenever the application cannot boot - which is
 * exactly when you most want to regenerate the client and find out why.
 */
class WriteOpenApiDocument extends Command
{
    protected $signature = 'api:openapi {--path=public/openapi.json : Where to write it, relative to the project root}';

    protected $description = 'Write the mobile API OpenAPI document to a file';

    public function handle(OpenApiGenerator $generator): int
    {
        $document = $generator->generate();
        $path     = base_path((string) $this->option('path'));

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->error('Could not create '.$directory);

            return self::FAILURE;
        }

        file_put_contents(
            $path,
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        $this->info(sprintf('Wrote %d paths to %s', count($document['paths']), $path));

        return self::SUCCESS;
    }
}
