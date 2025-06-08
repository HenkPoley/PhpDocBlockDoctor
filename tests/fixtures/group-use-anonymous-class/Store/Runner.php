<?php
namespace Pitfalls\GroupUseAnonymousClass\Store;

use Pitfalls\GroupUseAnonymousClass\SimpleSAML\Utils;

class Runner {
    public function run(): void {
        $store = new SQLStore();
        $store->hashData('test');
    }
}
