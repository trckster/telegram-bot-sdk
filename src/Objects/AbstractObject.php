<?php

namespace Telegram\Bot\Objects;

use ArrayIterator;
use CachingIterator;
use IteratorAggregate;
use JsonSerializable;
use Stringable;
use Telegram\Bot\Helpers\Json;
use Illuminate\Support\Collection;
use Telegram\Bot\Contracts\Arrayable;
use Telegram\Bot\Contracts\Jsonable;
use Telegram\Bot\Traits\ForwardsCalls;

abstract class AbstractObject implements Arrayable, IteratorAggregate, Jsonable, JsonSerializable, Stringable
{
    use ForwardsCalls;

    protected Collection $fields;

    public function __construct(array $fields = [])
    {
        $this->fields = new Collection($fields);
    }

    public static function make(array $fields = []): self
    {
        return new static($fields);
    }

    public function toArray(): array
    {
        return Json::decode($this->toJson());
    }

    public function toJson(int $options = 0): string
    {
        return Json::encode($this->jsonSerialize(), $options);
    }

    public function jsonSerialize(): array
    {
        return $this->fields->jsonSerialize();
    }

    public function getIterator(): ArrayIterator
    {
        return $this->fields->getIterator();
    }

    public function getCachingIterator(int $flags = CachingIterator::CALL_TOSTRING): CachingIterator
    {
        return $this->fields->getCachingIterator($flags);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public function __call(string $name, array $arguments)
    {
        return $this->forwardCallTo($this->fields, $name, $arguments);
    }
}
