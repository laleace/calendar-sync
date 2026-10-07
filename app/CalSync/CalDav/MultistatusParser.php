<?php

namespace App\CalSync\CalDav;

use App\CalSync\Exceptions\ProviderException;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class MultistatusParser
{
    /**
     * @return list<DavResponse>
     *
     * @throws ProviderException when the body is not a DAV:multistatus document.
     */
    public static function parse(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET: never fetch external resources. Entity substitution stays disabled.
            $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;

        if (! $loaded || $root === null || $root->namespaceURI !== Ns::DAV || $root->localName !== 'multistatus') {
            throw new ProviderException('Risposta CalDAV non valida: atteso un documento XML multistatus.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', Ns::DAV);

        $responses = [];

        /** @var DOMElement $response */
        foreach ($xpath->query('d:response', $root) as $response) {
            $properties = [];

            /** @var DOMElement $propstat */
            foreach ($xpath->query('d:propstat', $response) as $propstat) {
                $code = self::statusCode($xpath->evaluate('string(d:status)', $propstat));

                if ($code === null || $code < 200 || $code >= 300) {
                    continue;
                }

                /** @var DOMElement $property */
                foreach ($xpath->query('d:prop/*', $propstat) as $property) {
                    $properties['{'.$property->namespaceURI.'}'.$property->localName] = $property;
                }
            }

            $responses[] = new DavResponse(trim($xpath->evaluate('string(d:href)', $response)), $properties);
        }

        return $responses;
    }

    /**
     * "HTTP/1.1 200 OK" → 200
     */
    private static function statusCode(string $statusLine): ?int
    {
        $parts = explode(' ', trim($statusLine));

        return isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null;
    }
}
