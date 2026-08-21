<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Trait;

use Crustum\Ai\Http\Contract\HttpResponseInterface;

/**
 * Exposes the raw HTTP HttpResponseInterface on responses and steps.
 */
trait HasRawResponseTrait
{
    /**
     * The raw HTTP HttpResponseInterface, if available for the provider.
     */
    public ?HttpResponseInterface $raw = null;

    /**
     * Set the raw HTTP HttpResponseInterface.
     *
     * @param \Crustum\Ai\Http\Contract\HttpResponseInterface|null $response Raw HTTP HttpResponseInterface
     * @return static
     */
    public function withRawResponse(?HttpResponseInterface $response): static
    {
        $this->raw = $response;

        return $this;
    }

    /**
     * Prepare the instance for serialization, discarding the unserializable raw HTTP HttpResponseInterface.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $vars = get_mangled_object_vars($this);

        unset($vars['raw']);

        return $vars;
    }
}
