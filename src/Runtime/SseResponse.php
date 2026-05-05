<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Generator;
use Illuminate\Http\Client\Response;

final class SseResponse
{
    public static function parse(Response $response): Generator
    {
        $body = (string) $response->toPsrResponse()->getBody();
        $buffer = '';

        foreach (str_split($body, 8192) as $chunk) {

            $buffer .= $chunk;

            while (($separator = strpos($buffer, "\n\n")) !== false) {

                $rawEvent = substr($buffer, 0, $separator);
                $buffer = substr($buffer, $separator + 2);

                $event = self::parseEvent($rawEvent);

                if ($event !== null) {
                    yield $event;
                }

            }

        }

        if ($buffer !== '') {

            $event = self::parseEvent($buffer);

            if ($event !== null) {
                yield $event;
            }

        }
    }

    private static function parseEvent(string $raw): ?ExecutorEvent
    {
        $data = '';
        $lines = explode("\n", $raw);

        foreach ($lines as $line) {

            if (str_starts_with($line, 'data: ')) {
                $data .= substr($line, 6);
            }

        }

        if ($data === '') {
            return null;
        }

        $decoded = json_decode($data, true);

        if (!is_array($decoded)) {
            return null;
        }

        return ExecutorEvent::fromArray($decoded);
    }
}
