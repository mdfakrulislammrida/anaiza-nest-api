<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A picture from the public disk, handed to the admin page from the page's OWN address.
 *
 * Why it exists: an upload field in the admin shows a stored picture by fetching its address from the browser (Filament's upload loads the file before it
 * draws the preview). The storage address is built from APP_URL, so when the admin is opened on another host or scheme than APP_URL (the shop and the API
 * answer on two host names, http or https) the browser refuses that fetch, the preview is never drawn and only the file name is left. Served from here the
 * address is always the page's own, so it always loads, with or without the public/storage link on the server.
 *
 * Signed-in admins only. Only the public disk, only pictures, and a path that cannot leave the disk. A missing file answers 404 (the field asks for the neutral
 * tile instead, see App\Filament\Support\UploadPreview).
 */
class UploadPreviewController extends Controller
{
    /** The neutral tile shown for a file that is not there: 96 x 96, a quiet linen square with a small picture mark. */
    private const PLACEHOLDER = 'iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAIAAABt+uBvAAAACXBIWXMAAA7EAAAOxAGVKw4bAAABLUlEQVR42u3cTQ6CQAwGUGm8hzHe/z5ujNGN13DhhgQhuJhOwfctjT/hpaXDEBxez+tB5hMIAAECBAgQIECABBAgQIAAAQIESAABAgQIECBAgAQQIECAAAECBOhfc2z31Y/7LfNITueLCtJigAABMsUyJ07ypNtGBY3ncaPZvGGgqUhxo+irU9/ISRrQhoDmZtbn9ZqNll1BU6OxTkGjDi02NprWzhqjTMeh3QN1KxeBX4924bNzi8x9bnf8OvjzezAK6qxcUuZgRU2dOhclUVZn/M6OHReVdSpciERxne58sWmdXVVQO52m7qF2+gMl6LT7iVA7y2m+aZ+2Le/Wc58M/h5HBQECBAgQIECABBAgQIAAAQIESAABAgQIECBAgAQQIECAAAECBOhf8wZysDikLw5LeQAAAABJRU5ErkJggg==';

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __invoke(string $path): Response|StreamedResponse
    {
        abort_if(Auth::user() === null, 403);

        abort_unless(self::isSafe($path), 404);

        $disk = Storage::disk('public');

        abort_unless($disk->exists($path), 404);
        abort_unless(in_array($disk->mimeType($path), self::ALLOWED, true), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The neutral tile. The address ends in the missing file's name, which is only there so the field can still show that name. */
    public function placeholder(string $name): Response
    {
        abort_if(Auth::user() === null, 403);

        return response(base64_decode(self::PLACEHOLDER), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A relative path made of letters, digits and the usual file-name marks; never an absolute path, a drive, a backslash or "..". */
    public static function isSafe(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 300
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_contains($path, "\0")
            && ! str_contains($path, '://')
            && preg_match('/^[\pL\pN._\-\/ ()]+$/u', $path) === 1;
    }
}
