<?php
namespace Pitfalls\ForeachReturnArrayAnnotation;

class Item
{
    public function act(): void
    {
        throw new \LogicException();
    }
}

class Provider
{
    /**
     * @return Item[]
     */
    public function getItems(): array
    {
        return [new Item()];
    }
}

class Consumer
{
    public function run(): void
    {
        $provider = new Provider();
        foreach ($provider->getItems() as $item) {
            $item->act();
        }
    }
}
