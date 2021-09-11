<?php

namespace PHP_CodeSniffer\Standards\DCI;

class DCIRole {
    public string $name;
    public int $pos = 0;
    public array $roleMethods = [];

    public function __construct($name) {
        $this->name = $name;
    }

    public function addMethod($name, $pos, $access) {
        $this->roleMethods[$name] = ['pos' => $pos, 'access' => $access];
    }
}
