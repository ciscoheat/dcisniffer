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
    public function __construct(Context $context, string $saveDir) {
        $this->context = $context;
        $this->_fileName = $saveDir . '/' . $context->name() . '.json';
    }

    public function saveJson() {
        $this->context_saveJson();
    }

    private string $_fileName;

    ///// Roles ///////////////////////////////////////////

    private Context $context;

    protected function context_saveJson() {
        file_put_contents($this->_fileName, json_encode($this->context, JSON_PRETTY_PRINT));
    }
}