<?php

namespace App\CalSync\CalDav;

use DOMElement;

/**
 * One <d:response> of a multistatus body. Only properties returned with a 2xx propstat
 * are kept: "404 Not Found" properties are treated as absent.
 */
final readonly class DavResponse
{
    /**
     * @param  array<string, DOMElement>  $properties  Keyed by Clark notation: "{namespace}name".
     */
    public function __construct(
        public string $href,
        private array $properties,
    ) {}

    public function has(string $ns, string $name): bool
    {
        return isset($this->properties['{'.$ns.'}'.$name]);
    }

    public function property(string $ns, string $name): ?DOMElement
    {
        return $this->properties['{'.$ns.'}'.$name] ?? null;
    }

    /**
     * Trimmed text content of a property, null when absent or empty.
     */
    public function text(string $ns, string $name): ?string
    {
        $text = trim($this->property($ns, $name)?->textContent ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * The first <d:href> inside a property (e.g. current-user-principal, calendar-home-set).
     */
    public function href(string $ns, string $name): ?string
    {
        $href = $this->descendants($ns, $name, Ns::DAV, 'href')[0] ?? null;
        $text = trim($href?->textContent ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * Elements nested anywhere inside a property, e.g. the <c:calendar/> marker inside
     * <d:resourcetype> or the <d:write/> privileges inside <d:current-user-privilege-set>.
     *
     * @return list<DOMElement>
     */
    public function descendants(string $ns, string $name, string $childNs, string $childName): array
    {
        $property = $this->property($ns, $name);

        if ($property === null) {
            return [];
        }

        return iterator_to_array($property->getElementsByTagNameNS($childNs, $childName), false);
    }
}
