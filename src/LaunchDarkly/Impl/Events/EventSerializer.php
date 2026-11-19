<?php

declare(strict_types=1);

namespace LaunchDarkly\Impl\Events;

use LaunchDarkly\LDContext;
use LaunchDarkly\Types\AttributeReference;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Internal class that translates analytics events into the format used for sending them to LaunchDarkly.
 *
 * @ignore
 * @internal
 */
class EventSerializer
{
    private bool $_allAttributesPrivate;
    /** @var AttributeReference[] */
    private array $_privateAttributes;
    private LoggerInterface $_logger;

    public function __construct(array $options)
    {
        $logger = $options['logger'] ?? null;
        $this->_logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
        $this->_allAttributesPrivate = !!($options['all_attributes_private'] ?? false);

        $allParsedPrivate = [];
        foreach ($options['private_attribute_names'] ?? [] as $attr) {
            $parsed = AttributeReference::fromPath($attr);
            if ($parsed->getError() === null) {
                $allParsedPrivate[] = $parsed;
            }
        }
        $this->_privateAttributes = $allParsedPrivate;
    }

    /**
     * Serializes the events as a JSON array.
     *
     * An event that cannot be encoded as JSON, for instance because it contains a string that is not
     * valid UTF-8 or a float that is INF or NAN, is dropped and logged. The other events are kept.
     *
     * @return string the JSON array, or an empty string if no event could be encoded
     */
    public function serializeEvents(array $events): string
    {
        $filtered = [];
        foreach ($events as $e) {
            $filtered[] = $this->filterEvent($e);
        }
        $ret = json_encode($filtered);
        if ($ret !== false) {
            return $ret;
        }

        // At least one event cannot be encoded. Encode the events one at a time to find it.
        $encoded = [];
        foreach ($filtered as $e) {
            $json = json_encode($e);
            if ($json === false) {
                $kind = is_string($e['kind'] ?? null) ? $e['kind'] : 'unknown';
                $this->_logger->warning("Dropped an analytics event of kind \"$kind\" that could not be encoded as JSON: " . json_last_error_msg());
                continue;
            }
            $encoded[] = $json;
        }
        if (count($encoded) === 0) {
            return '';
        }
        return '[' . implode(',', $encoded) . ']';
    }

    private function filterEvent(array $e): array
    {
        // Feature, custom, and migration op events inline the full context, so anonymous context
        // attributes must be redacted for all of them. Other event kinds do not redact anonymous
        // attributes.
        $redactAnonymousAttributes = in_array($e['kind'] ?? '', ['feature', 'custom', 'migration_op'], true);

        $ret = [];
        foreach ($e as $key => $value) {
            if ($key == 'context') {
                $ret[$key] = $this->serializeContext($value, $redactAnonymousAttributes);
            } else {
                $ret[$key] = $value;
            }
        }
        return $ret;
    }

    private function serializeContext(LDContext $context, bool $redactAnonymousAttributes): array
    {
        if ($context->isMultiple()) {
            $ret = ['kind' => 'multi'];
            for ($i = 0; $i < $context->getIndividualContextCount(); $i++) {
                $c = $context->getIndividualContext($i);
                if ($c !== null) {
                    $ret[$c->getKind()] = $this->serializeContextSingleKind($c, false, $redactAnonymousAttributes);
                }
            }
            return $ret;
        } else {
            return $this->serializeContextSingleKind($context, true, $redactAnonymousAttributes);
        }
    }

    private function serializeContextSingleKind(LDContext $c, bool $includeKind, bool $redactAnonymousAttributes): array
    {
        $ret = ['key' => $c->getKey()];
        if ($includeKind) {
            $ret['kind'] = $c->getKind();
        }
        if ($c->isAnonymous()) {
            $ret['anonymous'] = true;
        }
        $redacted = [];
        $allPrivate = array_merge($this->_privateAttributes, $c->getPrivateAttributes() ?? []);
        $redactAllAttributes = $this->_allAttributesPrivate || ($redactAnonymousAttributes && $c->isAnonymous());
        if ($c->getName() !== null && !$this->checkWholeAttributePrivate('name', $allPrivate, $redacted, $redactAllAttributes)) {
            $ret['name'] = $c->getName();
        }
        foreach ($c->getCustomAttributeNames() as $attr) {
            if (!$this->checkWholeAttributePrivate($attr, $allPrivate, $redacted, $redactAllAttributes)) {
                $value = $c->get($attr);
                $ret[$attr] = self::redactJsonValue(null, $attr, $value, $allPrivate, $redacted);
            }
        }
        if (count($redacted) !== 0) {
            $ret['_meta'] = ['redactedAttributes' => $redacted];
        }
        return $ret;
    }

    private function checkWholeAttributePrivate(string $attr, array $allPrivate, array &$redactedOut, bool $redactAllAttributes): bool
    {
        if ($redactAllAttributes) {
            $redactedOut[] = $attr;
            return true;
        }
        foreach ($allPrivate as $p) {
            if ($p->getComponent(0) === $attr && $p->getDepth() === 1) {
                $redactedOut[] = $attr;
                return true;
            }
        }
        return false;
    }

    private static function redactJsonValue(?array $parentPath, string $name, mixed $value, array $allPrivate, array &$redactedOut): mixed
    {
        if (!is_array($value) || count($value) === 0) {
            return $value;
        }
        $ret = [];
        $currentPath = $parentPath ?? [];
        $currentPath[] = $name;
        foreach ($value as $k => $v) {
            if (is_int($k)) {
                // This is a regular array, not an object with string properties-- redactions don't apply. Technically,
                // that's not a 100% solid assumption because in PHP, an array could have a mix of int and string keys.
                // But that's not true in JSON or in pretty much any other SDK, so there wouldn't really be any clear
                // way to apply our redaction logic in that case anyway.
                return $value;
            }
            $wasRedacted = false;
            foreach ($allPrivate as $p) {
                if ($p->getDepth() !== count($currentPath) + 1) {
                    continue;
                }
                if ($p->getComponent(count($currentPath)) !== $k) {
                    continue;
                }
                $match = true;
                for ($i = 0; $i < count($currentPath); $i++) {
                    if ($p->getComponent($i) !== $currentPath[$i]) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    $redactedOut[] = $p->getPath();
                    $wasRedacted = true;
                    break;
                }
            }
            if (!$wasRedacted) {
                $ret[$k] = self::redactJsonValue($currentPath, $k, $v, $allPrivate, $redactedOut);
            }
        }
        if (count($ret) === 0) {
            // Substitute an empty object here, because an empty array would serialize as [] rather than {}
            return new \stdClass();
        }
        return $ret;
    }
}
