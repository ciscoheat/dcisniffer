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
        $this->context = $context;
        $this->_fileName = $saveDir . '/' . $context->name() . '.json';
    }

    public function saveVisData() {
        $this->methods_convertToVisData();
    }

    private string $_fileName;

    ///// Roles ///////////////////////////////////////////

    private Context $context;

    protected function context_role($name) : ?Role {
        return $this->context->role($name);
    }

    private array $methods;

    protected function methods_convertToVisData() {
        $nodes = [];
        $edges = [];

        foreach ($this->methods as $method) {
            $role = $this->context_role($method->role());
            $refs = $method->refs();

            if(!$role && !count($refs)) continue;

            if($role) {
                $label = [$role->name(), array_search($method, $role->methods())];

                if($method->access() == T_PRIVATE) {
                    $label[0] = '<i>' . $label[0] . '</i>';
                    $label[1] = '<i>' . $label[1] . '</i>';
                }

                $label = implode("\n", $label);
            } else {
                $label = $method->fullName();
            }

            $node = (object)[
                'id' => $method->fullName(),
                'label' => $label,
                'group' => $role ? $role->name() : '__CONTEXT'
            ];

            $hasEdge = false;
            foreach($refs as $ref) {

                switch($ref->type()) {
                    case Ref::ROLE:
                        // TODO: Role references
                        break;

                    case Ref::METHOD:
                    case Ref::ROLEMETHOD:
                        $edge = (object)[
                            'from' => $method->fullName(),
                            'to' => $ref->to()
                        ];

                        $edges[] = $edge;
                        $hasEdge = true;
                        break;
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