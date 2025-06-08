<?php
namespace Pitfalls\GroupUseAnonymousClass\Store;

use Pitfalls\GroupUseAnonymousClass\SimpleSAML\{Configuration, Logger, Utils};

class SQLStore {
    public function hashData(string $data): string {
        $secretSalt = (new class() extends Utils\Config {})->getSecretSalt();
        return hash('sha256', $data . $secretSalt);
    }
}
