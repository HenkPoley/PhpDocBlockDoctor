<?php
namespace HenkPoley\DocBlockDoctor\TestFixtures\AnonymousClassProperty;

use HenkPoley\DocBlockDoctor\TestFixtures\AnonymousClassProperty\{Configuration, Store};
use HenkPoley\DocBlockDoctor\TestFixtures\AnonymousClassProperty\Store\RedisStore;

class RedisStoreTest
{
    protected Client $client;
    protected RedisStore $store;
    protected array $config;

    protected function setUp(): void
    {
        $this->config = [];
        $this->client = new class ($this) extends Client {
            public function __construct(protected RedisStoreTest $unitTest) {}
        };
        $this->store = new Store\RedisStore($this->client);
    }
}
