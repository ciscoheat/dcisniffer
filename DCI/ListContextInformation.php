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
final class ListContextInformation {
    public function __construct(File $file, Context $context, bool $listRoleInterfaces) {
        $this->parser = $file;
        $this->context = $context;
        $this->_listRoleInterfaces = $listRoleInterfaces;
    }

    public function listInformation() {
        $this->context_listInformation();
    }

    // Supplied from RoleConventionsSniff
    private ?bool $_listRoleInterfaces;

    ///// Roles ///////////////////////////////////////////

    private $parser;

    protected function parser_warning(string $msg, int $pos, string $errorCode, ?array $data = null) {
        $this->parser->addWarning($msg, $pos, $errorCode, $data);
    }

    private $context;

    private function context_methodNamed($fullName) : Method {
        return $this->context->methods()[$fullName];
    }

    protected function context_listInformation() {

        $listMethodsToCount = 0;
        $listRoleInterfaces = [];

        $listCallsTo = [];
        foreach ($this->context->methods() as $method) {
            if($method->role() && in_array('listCallsTo', $method->tags()))
                $listCallsTo[$method->fullName()] = 0;
        }

        $unreferenced = array_filter($this->context->methods(), function($method) {
            return !!$method->role();
        });

        $outsideRef = array_filter($this->context->methods(), function($method) {
            return $method->role() && $method->access() == T_PROTECTED;
        });

        foreach ($this->context->methods() as $method) {
            $listMethodsInRoleMethod = [];
            $role = $method->role();
            
            $listCallsIn = in_array('listCallsIn', $method->tags());

            foreach($method->refs() as $ref) {
                switch($ref->type()) {
                    case Ref::ROLE:
                        if($this->_listRoleInterfaces) {
                            $listRoleInterfaces[$ref->to()][] = $ref->calls();
                        }
                        break;

                    case Ref::ROLEMETHOD:
                        if($role && $listCallsIn) {
                            $this->parser_warning($ref->to(), $ref->pos(), 'ListInRoleMethods');
                            $listMethodsInRoleMethod[$ref->to()] = true;
                        }
    
                        if(array_key_exists($ref->to(), $listCallsTo)) {
                            $data = [$method->fullName(), $ref->to()];
                            $this->parser_warning('"%s" calls "%s" here', $ref->pos(), 'ListToRoleMethods', $data);
                            $listCallsTo[$ref->to()]++;
                        }
    
                        $referenced = $this->context_methodNamed($ref->to());
    
                        if($method->role() != $referenced->role()) {
                            if($referenced->access() != T_PRIVATE) {
                                unset($outsideRef[$ref->to()]);
                            }
                        }
    
                        unset($unreferenced[$ref->to()]);
                        break;

                }
            }

            if(count($listMethodsInRoleMethod) > 0) {
                $methods = array_keys($listMethodsInRoleMethod);
                sort($methods);
                $this->parser_warning($method->fullName() . ' calls to [' . implode(', ', $methods) . ']', $method->start(), 'ListRoleMethods');
            }

        } // end foreach methods

        foreach($listCallsTo as $to => $count) {
            $method = $this->context_methodNamed($to);
            $data = [$count, $to];
            $this->parser_warning('%u call(s) to %s', $method->start(), 'ListToRoleMethods', $data);
        }
        foreach($listRoleInterfaces as $role => $methods) {
            $rolePos = $this->context->roles()[$role]->pos();
            $methods = array_filter(array_unique($methods), function($a) {
                return !!$a;
            });
            $msg = 'RoleInterface for %s: [%s]';
            $data = [$role, implode(', ', $methods)];
            $this->parser_warning($msg, $rolePos, 'ListRoleInterface', $data);
        }

        foreach ($unreferenced as $method) {
            $msg = 'Unreferenced RoleMethod "%s"';
            $data = [$method->fullName()];
            $this->parser_warning($msg, $method->start(), 'UnreferencedRoleMethod', $data);
        }

        foreach ($outsideRef as $method) {
            if(in_array($method, $unreferenced)) continue;
            $msg = 'RoleMethod "%s" has no references outside its Role and can be made private.';
            $data = [$method->fullName()];
            $this->parser_warning($msg, $method->start(), 'NoExternalRoleMethodReferences', $data);
        }
    }
}