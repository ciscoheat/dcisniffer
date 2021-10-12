<?php

namespace PHP_CodeSniffer\Standards\DCI;

trait JsonData {
    public function jsonData(...$exclude) {
        $output = [];
        foreach (\get_object_vars($this) as $name => $value) {
            if($name[0] == '_') {
                $field = substr($name, 1);
                if(!in_array($field, $exclude))
                    $output[$field] = $value;
            }
        }

        return $output;
    }
}