<?php

namespace Kukux\DigitalSignature\Support\Der;

/**
 * One decoded TLV, with its exact original bytes.
 *
 * A reader, not a full ASN.1 decoder: it knows tags and lengths and nothing
 * about types, which is all the CMS work needs — pull the issuer Name and
 * serial out of a certificate verbatim, walk a TimeStampResp, re-read a
 * SignedData we (or the hub) produced. Keeping `encoded` byte-for-byte is the
 * point: an issuer copied into IssuerAndSerialNumber must match the
 * certificate's bytes exactly, not a re-encoding of them.
 *
 * Strict DER only: indefinite lengths are refused.
 */
final class Element
{
    /** @var list<self>|null */
    private ?array $children = null;

    private function __construct(
        /** The identifier octet(s) as an int (single-byte tags only above 30). */
        public readonly int $tag,
        public readonly int $tagClass,
        public readonly bool $constructed,
        public readonly int $tagNumber,
        public readonly string $content,
        /** The whole TLV, exactly as read. */
        public readonly string $encoded,
    ) {}

    /**
     * Decode exactly one element; trailing bytes are an error.
     */
    public static function parse(string $der): self
    {
        $offset  = 0;
        $element = self::readAt($der, $offset);

        if ($offset !== strlen($der)) {
            throw new DerException('Unexpected '.(strlen($der) - $offset).' trailing byte(s) after a DER element.');
        }

        return $element;
    }

    /**
     * Decode a run of consecutive elements (the content of a constructed one).
     *
     * @return list<self>
     */
    public static function parseAll(string $der): array
    {
        $offset = 0;
        $out    = [];

        while ($offset < strlen($der)) {
            $out[] = self::readAt($der, $offset);
        }

        return $out;
    }

    /**
     * Read one element at $offset and advance it past the element.
     */
    public static function readAt(string $der, int &$offset): self
    {
        $total = strlen($der);
        $start = $offset;

        if ($offset >= $total) {
            throw new DerException('Unexpected end of DER data.');
        }

        $first       = ord($der[$offset++]);
        $tagClass    = $first & 0xC0;
        $constructed = ($first & 0x20) !== 0;
        $tagNumber   = $first & 0x1F;
        $tag         = $first;

        if ($tagNumber === 0x1F) {
            // High tag number form: base-128 continuation bytes.
            $tagNumber = 0;
            do {
                if ($offset >= $total) {
                    throw new DerException('Truncated DER tag.');
                }
                $byte      = ord($der[$offset++]);
                $tagNumber = ($tagNumber << 7) | ($byte & 0x7F);
            } while ($byte & 0x80);
        }

        if ($offset >= $total) {
            throw new DerException('Truncated DER length.');
        }

        $lengthByte = ord($der[$offset++]);

        if ($lengthByte === 0x80) {
            throw new DerException('Indefinite-length encoding is not DER.');
        }

        if ($lengthByte & 0x80) {
            $count = $lengthByte & 0x7F;

            if ($count > 8 || $offset + $count > $total) {
                throw new DerException('Invalid DER length.');
            }

            $length = 0;
            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
        } else {
            $length = $lengthByte;
        }

        if ($length < 0 || $offset + $length > $total) {
            throw new DerException('DER element runs past the end of the data.');
        }

        $content = substr($der, $offset, $length);
        $offset += $length;

        return new self($tag, $tagClass, $constructed, $tagNumber, $content, substr($der, $start, $offset - $start));
    }

    /**
     * @return list<self>
     */
    public function children(): array
    {
        if (! $this->constructed) {
            throw new DerException('A primitive DER element has no children.');
        }

        return $this->children ??= self::parseAll($this->content);
    }

    public function child(int $index): self
    {
        $children = $this->children();

        if (! isset($children[$index])) {
            throw new DerException("DER element has no child #{$index}.");
        }

        return $children[$index];
    }

    /**
     * The first child carrying context tag [n], or null.
     */
    public function contextChild(int $tagNumber): ?self
    {
        foreach ($this->children() as $child) {
            if ($child->isContext($tagNumber)) {
                return $child;
            }
        }

        return null;
    }

    public function isContext(int $tagNumber): bool
    {
        return $this->tagClass === Der::CONTEXT && $this->tagNumber === $tagNumber;
    }

    public function is(int $tag): bool
    {
        return $this->tag === $tag;
    }

    /**
     * Throw unless this element carries the given (single-byte) tag.
     */
    public function expect(int $tag, string $what): self
    {
        if ($this->tag !== $tag) {
            throw new DerException(sprintf('Expected %s (tag 0x%02X), found tag 0x%02X.', $what, $tag, $this->tag));
        }

        return $this;
    }

    /**
     * Dotted form of an OBJECT IDENTIFIER.
     */
    public function oid(): string
    {
        $this->expect(Der::OID, 'an OBJECT IDENTIFIER');

        $arcs  = [];
        $value = 0;

        for ($i = 0, $n = strlen($this->content); $i < $n; $i++) {
            $byte  = ord($this->content[$i]);
            $value = ($value << 7) | ($byte & 0x7F);

            if (! ($byte & 0x80)) {
                $arcs[] = $value;
                $value  = 0;
            }
        }

        if ($arcs === []) {
            throw new DerException('Empty OBJECT IDENTIFIER.');
        }

        $first  = $arcs[0] >= 80 ? 2 : intdiv($arcs[0], 40);
        $second = $arcs[0] - 40 * $first;

        return implode('.', array_merge([$first, $second], array_slice($arcs, 1)));
    }

    /**
     * An INTEGER that fits in PHP's int (versions, statuses, small counters).
     */
    public function int(): int
    {
        $this->expect(Der::INTEGER, 'an INTEGER');

        if ($this->content === '' || strlen($this->content) > 8) {
            throw new DerException('INTEGER is empty or too large for a native int; use integerBytes().');
        }

        $negative = (ord($this->content[0]) & 0x80) !== 0;
        $value    = 0;

        foreach (str_split($this->content) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        if ($negative && strlen($this->content) < 8) {
            $value -= 1 << (8 * strlen($this->content));
        }

        return $value;
    }

    /**
     * The magnitude of a non-negative INTEGER, big-endian, without the
     * leading sign byte (serial numbers, nonces).
     */
    public function integerBytes(): string
    {
        $this->expect(Der::INTEGER, 'an INTEGER');

        $bytes = ltrim($this->content, "\0");

        return $bytes === '' ? "\0" : $bytes;
    }
}
