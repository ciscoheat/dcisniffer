<?php

namespace PHP_CodeSniffer\Standards\DCI;

use PHP_CodeSniffer\Files\File;

require_once __DIR__ . '/Context.php';

use PHP_CodeSniffer\Standards\DCI\Context;
use PHP_CodeSniffer\Standards\DCI\Role;
use PHP_CodeSniffer\Standards\DCI\Method;
use PHP_CodeSniffer\Standards\DCI\Ref;

/**
 * @context
 */
final class ContextVisualization {
    public function __construct(File $file, Context $context, string $saveDir) {
        $this->methods = $context->methods();
        $this->_fileName = $saveDir . '/' . $context->name() . '.json';
    }

    public function saveVisData() {
        $this->methods_convertToVisData();
    }

    private string $_fileName;

    ///// Roles ///////////////////////////////////////////

    private array $methods;

    protected function methods_convertToVisData() {
        $nodes = [];
        $edges = [];

        foreach ($this->methods as $method) {            
            $role = $method->role();
            $refs = $method->refs();

            if(!$role && !count($refs)) continue;

            $node = (object)[
                'id' => $method->fullName(),
                'label' => $role 
                    ? $role->name() . "\n" . array_search($method, $role->methods())
                    : $method->fullName(),
                'group' => $role ? $role->name() : '__CONTEXT'
            ];

            $hasEdge = false;
            foreach($refs as $ref) {

                if($ref->type() == Ref::ROLE) {
                    // TODO: Role references
                } else {
                    $edge = (object)[
                        'from' => $method->fullName(),
                        'to' => $ref->to()
                    ];

                    $edges[] = $edge;
                    $hasEdge = true;
                }
            }

            if($role || $hasEdge) $nodes[] = $node;

        } // end foreach methods

        $this->methods_saveData((object)[
            'nodes' => $nodes,
            'edges' => array_values($edges)
        ]);
    }

    private function methods_saveData($data) {
        file_put_contents($this->_fileName, json_encode($data, JSON_PRETTY_PRINT));
    }
}