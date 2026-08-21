<?php
declare(strict_types=1);

namespace Crustum\Ai\Support;

use Attribute;
use InvalidArgumentException;

/**
 * Controls whether and which tool a model must call.
 *
 * Maps to each provider's native tool_choice field.
 * Can be used as an attribute on agent classes.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ToolChoice
{
    /**
     * Auto mode - model decides whether to call tools.
     *
     * @var string
     */
    public const AUTO = 'auto';

    /**
     * None mode - model will not call any tools.
     *
     * @var string
     */
    public const NONE = 'none';

    /**
     * Required mode - model must call at least one tool.
     *
     * @var string
     */
    public const REQUIRED = 'required';

    /**
     * Tool mode - model must call a specific tool.
     *
     * @var string
     */
    public const TOOL = 'tool';

    /**
     * @var 'auto'|'none'|'required'|'tool'
     */
    public readonly string $mode;

    /**
     * Constructor.
     *
     * @param string $mode The tool choice mode
     * @param string|null $toolName The specific tool name (required for 'tool' mode)
     * @throws \InvalidArgumentException if mode is invalid or toolName is missing/invalid
     */
    public function __construct(
        string $mode,
        public readonly ?string $toolName = null,
    ) {
        if (!in_array($mode, [self::AUTO, self::NONE, self::REQUIRED, self::TOOL], true)) {
            throw new InvalidArgumentException(sprintf('Unrecognized tool choice mode "%s".', $mode));
        }

        if ($mode === self::TOOL && ($toolName === null || $toolName === '')) {
            throw new InvalidArgumentException('Tool choice mode "tool" requires a tool name.');
        }

        if ($mode !== self::TOOL && $toolName !== null) {
            throw new InvalidArgumentException('Tool choice "toolName" is only valid for mode "tool".');
        }

        /** @var 'auto'|'none'|'required'|'tool' $mode */
        $this->mode = $mode;
    }

    /**
     * Require the model to call the tool with the given name.
     *
     * @param string $name The tool name
     */
    public static function tool(string $name): self
    {
        return new self(self::TOOL, $name);
    }

    /**
     * Coerce a ToolChoice, a mode string, or a ['tool' => 'name'] array into a ToolChoice instance.
     *
     * @param self|array<string, mixed>|string $value The value to convert
     * @throws \InvalidArgumentException if value cannot be converted
     */
    public static function from(self|string|array $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return new self($value);
        }

        foreach (['toolName', 'tool', 'name'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                return self::tool($value[$key]);
            }
        }

        throw new InvalidArgumentException('Unrecognized tool choice value.');
    }
}
