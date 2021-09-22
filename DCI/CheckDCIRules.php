<?php 

namespace PHP_CodeSniffer\Standards\DCI;

require_once __DIR__ . '/Context.php';

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\DCI\Context;
use PHP_CodeSniffer\Standards\DCI\Role;
use PHP_CodeSniffer\Standards\DCI\Method;
use PHP_CodeSniffer\Standards\DCI\Ref;

// currentClass_checkRules: [methods_get, methods_getAll, parser_error, parser_warning, roles_exist, roles_get, roles_getAll, roles_getNames]"

/**
 * @context
 */
final class CheckDCIRules {
    public function __construct(File $file, Context $context) {
        $this->parser = $file;
        $this->context = $context;
        $this->roles = $context->roles();
        $this->methods = $context->methods();
    }

    public function check() {
        $this->context_checkRules();
    }

    public ?string $_listRoleMethods = null;

    ///// Roles ///////////////////////////////////////////

    private $parser;

    protected function parser_error(string $msg, int $pos, string $errorCode, ?array $data = null) {
        $this->parser->addError($msg, $pos, $errorCode, $data);
    }

    protected function parser_warning(string $msg, int $pos, string $errorCode, ?array $data = null) {
        $this->parser->addWarning($msg, $pos, $errorCode, $data);
    }

    private $roles;

    protected function roles_exist($roleName) {
        return $this->roles_named($roleName) != null;
    }

    protected function roles_named($roleName) {
        return $this->roles[$roleName] ?? null;
    }

    protected function roles_names() {
        return array_keys($this->roles);
    }

    protected function roles_all() {
        return ($this->roles);
    }

    protected function roles_checkRoleMethodPositions() {
        // Use array_values to get a numeric key for comparison with
        // the next Role's position.
        $roles = array_values($this->roles);
        $lastKey = count($roles) - 1;

        foreach($roles as $key => $role) {
            $start = $role->pos();
            $end = $key < $lastKey ? $roles[$key + 1]->pos() : PHP_INT_MAX;

            foreach($role->methods() as $name => $method) {
                if($method->start() < $start || $method->start() > $end) {
                    $msg = 'RoleMethod "%s" is not positioned below its Role.';
                    $data = [$method->fullName()];
                    $this->parser_error($msg, $method->start(), 'RoleMethodPosition', $data);
                }
            }
        }
    }

    private $context;

    private function context_methodNamed($fullName) : Method {
        return $this->context->methods()[$fullName];
    }

    protected function context_checkRules() {
        $assignedPos = 0;

        foreach ($this->context->methods() as $method) {
            $assigned = [];
            $listMethods = [];

            $role = $method->role();

            foreach($method->refs() as $ref) {
                if($ref->type() == Ref::ROLE) {
                    if($ref->isAssignment()) {
                        $assigned[$ref->to()] = $ref;
                    } else {
                        // Does it reference a Role directly, or a normal method?
                        // A direct reference is allowed only if in one of its RoleMethods
                        if(!$role || $role->name() != $ref->to()) {
                            $msg = 'Role "%s" accessed outside its RoleMethods';
                            $data = [$ref->to()];
                            $this->parser_error($msg, $ref->pos(), 'RoleAccessedOutsideItsMethods', $data);
                        }
                    }
                } else {
                    // References a RoleMethod, check access

                    // Debug feature
                    if($role && $this->_listRoleMethods == $method->fullName()) {
                        $this->parser_warning($ref->to(), $ref->pos(), 'ListRoleMethods');
                        $listMethods[$ref->to()] = true;
                    }

                    $referenced = $this->context_methodNamed($ref->to());

                    if($referenced->access() == T_PRIVATE && $method->role() != $referenced->role()) {
                        $data = [$referenced->fullName()];

                        $msg = 'Private RoleMethod "%s" accessed outside its own RoleMethods here.';
                        $this->parser_error($msg, $ref->pos(), 'InvalidRoleMethodAccess', $data);
                        
                        $msg = 'Private RoleMethod "%s" accessed outside its own RoleMethods. Make it protected if this is intended.';
                        $this->parser_error($msg, $referenced->start(), 'AdjustRoleMethodAccess', $data);
                    }
                }
            }

            // Debug feature: listRoleMethods
            if(count($listMethods) > 0) {
                $methods = array_keys($listMethods);
                sort($methods);
                $this->parser_warning($method->fullName() . ': [' . implode(', ', $methods) . ']', $method->start(), 'ListRoleMethods');
            }

            if(count($assigned) > 0) {
                $roleNames = $this->roles_names();
                if($assignedPos) {
                    foreach ($assigned as $ref) {                        
                        $msg = 'All Roles must be bound inside a single method. Move this assignment to the other method.';
                        $this->parser_error($msg, $ref->pos, 'RoleNotBoundInSingleMethod');

                        $msg = 'Method where roles are currently bound.';
                        $this->parser_error($msg, $assignedPos, 'RoleNotBoundInSingleMethod');
                    }
                }
                else if(count($assigned) < count($roleNames)) {
                    $missing = array_diff($roleNames, array_keys($assigned));
                    $msg = 'All Roles must be bound inside a single method. Missing: %s';
                    $data = [join(", ", $missing)];
                    $this->parser_error($msg, $method->start(), 'RolesNotBoundInSingleMethod', $data);
                } else {
                    $assignedPos = $method->start();
                }
            }
        }

        $this->roles_checkRoleMethodPositions();
    }

}