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
final class CheckDCIRules {
    public function __construct(File $file, Context $context) {
        $this->parser = $file;
        $this->context = $context;
    }

    public function check() {
        $this->context_checkRules();
    }

    ///// Roles ///////////////////////////////////////////

    private $parser;

    protected function parser_error(string $msg, int $pos, string $errorCode, ?array $data = null) {
        $this->parser->addError($msg, $pos, $errorCode, $data);
    }

    private $context;

    private function context_roleNames() {
        return array_keys($this->context->roles());
    }

    private function context_methodNamed($fullName) : Method {
        return $this->context->methods()[$fullName];
    }

    private function context_checkRoleMethodPositions() {
        $start = 0;
        $end = PHP_INT_MAX;
        $currentRole = null;

        foreach($this->context->methods() as $method) {
            $role = $method->role();

            if(!$role) {
                $end = $method->start();
            } else if($currentRole != $role) {
                $start = $role->pos();
                $end = PHP_INT_MAX;
                $currentRole = $role;
            }

            if($role && ($method->start() < $start || $method->start() > $end)) {
                $msg = 'RoleMethod "%s" must be positioned below its Role.';
                $data = [$method->fullName()];
                $this->parser_error($msg, $method->start(), 'InvalidRoleMethodPosition', $data);
            }
        }
    }

    protected function context_checkRules() {
        $this->context_checkRoleMethodPositions();

        $assignedPos = 0;        
        $accessedOutside = [];

        foreach ($this->context->roles() as $role) {
            if($role->access() != T_PRIVATE) {
                $msg = 'Role "%s" must be private.';
                $data = [$role->name()];
                $this->parser_error($msg, $role->pos(), 'InvalidRoleDefinition', $data);
            }
        }

        foreach ($this->context->methods() as $method) {
            $assigned = [];
            $role = $method->role();

            if($role && $method->access() == T_PUBLIC) {
                $msg = 'RoleMethod "%s" is public, must be private or protected.';
                $data = [$method->fullName()];
                $this->parser_error($msg, $method->start(), 'InvalidRoleMethod', $data);
            }

            foreach($method->refs() as $ref) {

                if($ref->type() == Ref::ROLE) {
                    if($ref->isAssignment()) {
                        $assigned[$ref->to()] = $ref;
                    } else {
                        // Does it reference a Role directly, or a normal method?
                        // A direct reference is allowed only if in one of its RoleMethods
                        if(!$role || $role->name() != $ref->to()) {
                            $msg = 'Role "%s" accessed outside its RoleMethods here.';
                            $data = [$ref->to()];
                            $this->parser_error($msg, $ref->pos(), 'InvalidRoleAccess', $data);
                        }
                    }
                } else {
                    // References a RoleMethod, check access
                    $referenced = $this->context_methodNamed($ref->to());

                    if($method->role() != $referenced->role()) {
                        if($referenced->access() == T_PRIVATE) {
                            $data = [$referenced->fullName()];

                            $msg = 'Private RoleMethod "%s" accessed outside its own RoleMethods here.';
                            $this->parser_error($msg, $ref->pos(), 'InvalidRoleMethodAccess', $data);
                            $accessedOutside[] = $ref;
                        }
                    }
                }
            }

            if(count($assigned) > 0) {
                $roleNames = $this->context_roleNames();

                if($assignedPos) {
                    // Roles were assigned already in another method
                    foreach ($assigned as $ref) {
                        $msg = 'All Roles must be bound inside a single method.';
                        $this->parser_error($msg, $ref->pos(), 'InvalidRoleBinding');

                        $msg = 'Method where roles are currently bound.';
                        $this->parser_error($msg, $assignedPos, 'InvalidRoleBinding');
                    }
                }
                else if(count($assigned) < count($roleNames)) {
                    $missing = array_diff($roleNames, array_keys($assigned));
                    $msg = 'All Roles must be bound inside a single method. Missing: %s';
                    $data = [join(", ", $missing)];
                    $this->parser_error($msg, $method->start(), 'InvalidRoleBinding', $data);
                } else {
                    $assignedPos = $method->start();
                }
            }
        }

        foreach ($accessedOutside as $ref) {
            $method = $this->context_methodNamed($ref->to());
            $data = [$method->fullName()];
            $msg = 'Private RoleMethod "%s" accessed outside its own RoleMethods. Make it protected if this is intended.';
            $this->parser_error($msg, $method->start(), 'InvalidRoleMethodAccess', $data);
        }
    }
}