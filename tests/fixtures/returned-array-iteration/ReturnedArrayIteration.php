<?php
namespace Pitfalls\ReturnedArrayIteration;

class Item {
    public function work(): void {
        throw new \RuntimeException();
    }
}

class Provider {
    /**
     * @return Item[]
     */
    public function getItems(): array {
        return [new Item()];
    }
}

class Runner {
    private Provider $p;
    public function __construct(Provider $p) { $this->p = $p; }
    public function run(): void {
        foreach ($this->p->getItems() as $it) {
            $it->work();
        }
    }
}
