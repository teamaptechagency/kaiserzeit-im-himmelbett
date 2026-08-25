<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses input that is not valid UTF-8.
 *
 * Found by feeding a form badly encoded German: the bytes reached a JSON
 * column, json_encode refused them, and the request died as a 500 with a
 * stack trace. A wrongly encoded form is the sender's mistake, not the
 * server's failure — it should come back as "that was not readable text",
 * the way any other bad field does.
 *
 * Checked here rather than field by field because every controller that takes
 * text would otherwise have to remember, and the one that forgot would be the
 * one somebody found.
 */
class RejectMalformedInput
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }

        /* A raw body — an image upload — is not text and is not checked. */
        if ($request->input() === []) {
            return $next($request);
        }

        $bad = $this->firstBadField($request->input());

        if ($bad !== null) {
            throw ValidationException::withMessages([
                $bad => 'That field was not readable text. It looks like the wrong character encoding.',
            ]);
        }

        return $next($request);
    }

    /** @return string|null the field path, or null when everything is fine */
    private function firstBadField(array $input, string $prefix = ''): ?string
    {
        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                if ($found = $this->firstBadField($value, $path)) {
                    return $found;
                }
                continue;
            }

            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                return $path;
            }
        }

        return null;
    }
}
