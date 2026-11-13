<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends Controller
{
    /**
     * Serve a file from the public disk - and from nowhere else.
     *
     * The route is public and matches any path, and this used to serve
     * storage_path('app/public/'.$filePath) exactly as given. '../' walked out
     * of the disk, and so did its encodings: /file/download/..%2F..%2F..%2F.env
     * handed the .env to anyone who asked - the application key, the database
     * credentials and every gateway secret.
     *
     * The path is now resolved - dot segments, either slash, symlinks - and
     * served only when what it resolves to is a regular file INSIDE the disk
     * root. The root is compared with its trailing separator: without it a
     * directory merely named like the disk, public_anything, would pass.
     *
     * Staying public is deliberate. Everything on this disk is already
     * reachable through the /storage symlink, so containment rather than a
     * sign-in is what closes the hole, and links shown to guests keep working.
     * Anything refused answers 404, the same as a file that does not exist.
     */
    public function download(string $filePath): BinaryFileResponse
    {
        abort_if(str_contains($filePath, "\0"), 404);

        $root     = realpath(storage_path('app/public'));
        $resolved = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$filePath);

        abort_unless(
            $resolved !== false
                && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
                && is_file($resolved),
            404
        );

        return response()->download($resolved);
    }
}
