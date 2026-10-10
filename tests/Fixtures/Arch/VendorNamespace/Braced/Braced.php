<?php

namespace Fixture\VendorNamespace\Braced {
    use Acme\Gateway;

    final class Braced
    {
        public function gateway(): Gateway
        {
            return new Gateway;
        }
    }
}

namespace {
    $kernel = new App\Http\Kernel;
}
