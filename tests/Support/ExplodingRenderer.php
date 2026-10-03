<?php

namespace Kukux\DigitalSignature\Tests\Support;

/** A renderer that fails: routing must roll back what it opened and rethrow. */
class ExplodingRenderer extends StubPdfRenderer
{
    public function render(string $view, array $data, string $destinationPath): string
    {
        throw new \RuntimeException('renderer exploded');
    }
}
