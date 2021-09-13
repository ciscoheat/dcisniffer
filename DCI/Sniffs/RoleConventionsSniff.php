<?php

namespace PHP_CodeSniffer\Standards\DCI\Sniffs;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

/**
 * @context
 */
final class RoleConventionsSniff implements Sniff {
    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array(int)
     */
    public function register() {
        return [
            T_DOC_COMMENT_TAG, T_CLOSE_CURLY_BRACKET, // Context detection
            T_PUBLIC, T_PRIVATE, T_PROTECTED, // Role/RoleMethod access
            T_VARIABLE // Role assignments
        ];
    }

    /**
     * @noDCIRole
     */
    public $roleFormat = '/^([a-zA-Z0-9]+)$/';

    /**
     * @noDCIRole
     */
    public $roleMethodFormat = '/^([a-zA-Z0-9]+)_+([a-zA-Z0-9]+)$/';

    ///// State /////////////////////////////////////////////////////

    private bool $_ignoreNextRole = false;

    ///// Roles /////////////////////////////////////////////////////

    private ?object $currentClass = null;

    protected function currentClass_exists() {
        return $this->currentClass_start() > 0;
    }

    protected function currentClass_start() {
        return $this->currentClass->start ?? 0;
    }

    protected function currentClass_end() {
        return $this->currentClass->end ?? 0;
    }

    protected function currentClass_set(int $start, int $end) {
        $this->currentClass->start = $start;
        $this->currentClass->end = $end;
        $this->currentClass->method = null;
    }

    protected function currentClass_checkStart($token, $stackPtr) {
        if($token['code'] == T_DOC_COMMENT_TAG && !$this->currentClass_exists()) {
            $tag = strtolower($token['content']);
            $tagged = in_array($tag, ['@context', '@dci', '@dcicontext']);

            if($tagged && $classPos = $this->parser_findNext(T_CLASS, $stackPtr)) {
                $class = $this->parser_tokenAt($classPos);
                $this->currentClass_set($class['scope_opener'], $class['scope_closer']);
            }
        }
        
        return $this->currentClass_exists();
    }

    protected function currentClass_checkEnd($token) {
        return $this->currentClass_exists() &&
            $token['code'] == T_CLOSE_CURLY_BRACKET &&
            $token['scope_closer'] == $this->currentClass_end();
    }

    protected function currentClass_checkRules() {
        $assignedOk = 0;

        foreach ($this->methods_getAll() as $method) {
            if($method->role && !$this->roles_exist($method->role->name)) {
                $msg = 'Role "%s" does not exist. Add it as "private $%s;" above its RoleMethods.';
                $data = [$method->role->name, $method->role->name];
                $this->parser_error($msg, $method->start, 'NoRoleExists', $data);
            }

            $assigned = [];
            foreach($method->refs as $ref) {
                // Check if assignment
                if($ref->isAssignment) {
                    if($this->roles_exist($ref->to))
                        $assigned[$ref->to] = $ref;
                }
                else if(!$ref->isRoleMethod) {
                    // Does it reference a Role directly, or a normal method?
                    if($this->roles_exist($ref->to)) {
                        // References a Role directly, allowed only if in one of its RoleMethods
                        if(!$method->role || $method->role->name != $ref->to) {
                            $msg = 'Role "%s" accessed outside its RoleMethods';
                            $data = [$ref->to];
                            $this->parser_error($msg, $ref->pos, 'RoleAccessedOutsideItsMethods', $data);
                        }
                    }
                } else {
                    // References a RoleMethod, check access
                    $roleMethod = $this->methods_get($ref->to);
                    $roleName = $roleMethod->role->name;
                    $roleMethodName = $roleMethod->role->method;

                    if(!$this->roles_exist($roleName)) {
                        $msg = 'Role "%s" does not exist. Add it as "private $%s;" above its RoleMethods.';
                        $data = [$roleName, $roleName];
                        $this->parser_error($msg, $roleMethod->start, 'NoRoleExists', $data);
                    } else {
                        // Add RoleMethod to the Role, will be looped through
                        // after the current loop to check RoleMethod positions.
                        $this->roles_get($roleName)->methods[$roleMethodName] = $roleMethod;
                        
                        if((!$method->role || $method->role->name != $roleName) && $roleMethod->access == T_PRIVATE) {
                            $msg = 'Private RoleMethod "%s->%s" accessed outside its own RoleMethods here.';
                            $data = [$roleName, $roleMethodName];
                            $this->parser_error($msg, $ref->pos, 'InvalidRoleMethodAccess', $data);
                            
                            $msg = 'Private RoleMethod "%s->%s" accessed outside its own RoleMethods. Make it protected if this is intended.';
                            $data = [$roleName, $roleMethodName];
                            $this->parser_error($msg, $roleMethod->start, 'AdjustRoleMethodAccess', $data);
                        }
                    }    
                }
            }

            if(count($assigned) > 0) {
                if($assignedOk) {
                    foreach ($assigned as $ref) {                        
                        $msg = 'All Roles must be bound inside a single method. Move this assignment to the other method.';
                        $this->parser_error($msg, $ref->pos, 'RoleNotBoundInSingleMethod');

                        $msg = 'Method where roles are currently bound.';
                        $this->parser_error($msg, $assignedOk, 'RoleNotBoundInSingleMethod');
                    }
                }
                else if(count($assigned) < count($this->roles_getAll())) {
                    $missing = array_diff($this->roles_getNames(), array_keys($assigned));
                    $msg = 'All Roles must be bound inside a single method. Missing: %s';
                    $data = [join(", ", $missing)];
                    $this->parser_error($msg, $method->start, 'RolesNotBoundInSingleMethod', $data);
                } else {
                    $assignedOk = $method->start;
                }
            }
        }
        
        $roles = array_values($this->roles_getAll());
        foreach($roles as $key => $role) {
            $start = $role->pos;
            $end = $roles[$key + 1]->pos ?? PHP_INT_MAX;

            foreach($role->methods as $name => $method) {
                if($method->start < $start || $method->start > $end) {
                    $msg = 'RoleMethod "%s->%s" is not positioned below its Role.';
                    $data = $method->role;
                    $this->parser_error($msg, $method->start, 'RoleMethodPosition', $data);
                }
            }
        }
    }

    /////////////////////////////////////////////////////////////////

    private ?object $currentMethod = null;

    protected function currentMethod_get() {
        return $this->currentMethod;
    }

    protected function currentMethod_addRoleRef(string $to, int $pos, bool $isAssignment) {

        $isRoleMethod = !!preg_match($this->roleMethodFormat, $to);
        $isRole = !$isRoleMethod && !!preg_match($this->roleFormat, $to);

        if(!$isRole && !$isRoleMethod) return;

        if($isRoleMethod && $isAssignment) {
            $msg = 'Cannot assign to a RoleMethod.';
            $this->parser_error($msg, $pos, 'RoleMethodAssignment');
            return;
        }

        $this->currentMethod->refs[] = (object)[
            //'from' => $method,
            'to' => $to,
            'pos' => $pos,
            'isRoleMethod' => $isRoleMethod,
            'isAssignment' => $isAssignment
        ];
    }
    
    /////////////////////////////////////////////////////////////////

    private File $parser;

    protected function parser_findNext($type, int $start, ?string $value = null, bool $local = true) {
        return $this->parser->findNext(
            $type, $start, null, false, $value, $local
        );
    }

    protected function parser_findPrevious($type, int $start, ?string $value = null, bool $local = true) {
        return $this->parser->findPrevious(
            $type, $start, null, false, $value, $local
        );
    }

    protected function parser_error(string $msg, int $pos, string $errorCode, ?array $data = null) {
        $this->parser->addError($msg, $pos, $errorCode, $data);
    }

    protected function parser_warning(string $msg, int $pos, string $errorCode, ?array $data = null) {
        $this->parser->addWarning($msg, $pos, $errorCode, $data);
    }

    protected function parser_getTokens() {
        return $this->parser->getTokens();
    }

    protected function parser_tokenAt($pos) {
        return $this->parser_getTokens()[$pos];
    }

    /////////////////////////////////////////////////////////////////

    private array $roles = [];

    protected function roles_add(string $name, int $pos, int $access) {
        if($access != T_PRIVATE) {
            $msg = 'Role "%s" must be private.';
            $data = [$name];
            $this->parser_error($msg, $pos, 'RoleNotPrivate', $data);
        }

        $base = (object)[
            'name' => $name, 'pos' => $pos, 'access' => $access, 
            'methods' => []
        ];

        return $this->roles[$name] = $base;
    }

    protected function roles_exist($roleName) {
        return array_key_exists($roleName, $this->roles);
    }

    protected function roles_get($roleName) {
        return $this->roles[$roleName];
    }

    protected function roles_getNames() {
        return array_keys($this->roles);
    }

    protected function roles_getAll() {
        return $this->roles;
    }

    /////////////////////////////////////////////////////////////////

    private array $methods = [];

    protected function methods_get($name) {
        return $this->methods[$name];
    }

    protected function methods_getAll() {
        return $this->methods;
    }

    protected function methods_add(string $name, int $start, int $end, int $access) {
        $isRoleMethod = preg_match($this->roleMethodFormat, $name, $matches);

        if($isRoleMethod && $access == T_PUBLIC) {
            $msg = 'RoleMethod "%s->%s" is public, must be private or protected.';
            $data = [$matches[1], $matches[2]];
            $this->parser_error($msg, $start, 'PublicRoleMethod', $data);
        }
        
        $base = (object)[
            'name' => $name, 'start' => $start, 'end' => $end, 'access' => $access, 
            'refs' => [], 
            'role' => $isRoleMethod ? (object)['name' => $matches[1], 'method' => $matches[2]] : null
        ];

        return $this->methods[$name] = $base;
    }

    /////////////////////////////////////////////////////////////////

    private function rebind(File $parser, $newClass = false, $newMethod = false) {
        $this->parser = $parser;

        if(!$this->currentClass_exists() || $newClass) {
            $this->currentClass = (object)['start' => 0, 'end' => 0];
            $this->roles = [];
            $this->methods = [];
            $this->_ignoreNextRole = false;
            $this->currentMethod = null;
        }

        if($newMethod !== false) {
            $this->currentMethod = $newMethod;
        }
    }

    /**
     * Processes this sniff, when one of its tokens is encountered.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The current file being checked.
     * @param int                         $stackPtr  The position of the current token in the
     *                                               stack passed in $tokens.
     *
     * @return void
     */
    public function process(File $file, $stackPtr) {
        $this->rebind($file);

        //if(!file_exists('e:\temp\tokens.json')) file_put_contents('e:\temp\tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));

        $tokens = $this->parser_getTokens();
        $current = $tokens[$stackPtr];
        $type = $current['code'];

        if(!$this->currentClass_checkStart($current, $stackPtr))
            return;

        if($this->currentClass_checkEnd($current)) {
            $this->currentClass_checkRules();
            $this->rebind($file, true);
            return;
        }

        switch ($type) {
            case T_DOC_COMMENT_TAG:
                $tag = strtolower($current['content']);

                if(in_array($tag, ['@norole', '@nodcirole', '@ignorerole', '@ignoredcirole'])) {
                    $this->_ignoreNextRole = true;
                }

                break;

            case T_CLOSE_CURLY_BRACKET:
                $currentMethod = $this->currentMethod_get();

                if($currentMethod && $current['scope_closer'] == $currentMethod->end) {
                    $this->rebind($file, false, null);
                }
                break;
            
            case T_PRIVATE:
            case T_PROTECTED:
            case T_PUBLIC:
                // Check if it's a method
                if($funcPos = $this->parser_findNext(T_FUNCTION, $stackPtr)) {
                    $funcToken = $tokens[$funcPos];
                    
                    $funcNamePos = $this->parser_findNext(T_STRING, $funcPos);
                    $funcName = $tokens[$funcNamePos]['content'];
    
                    $newMethod = $this->methods_add(
                        $funcName, $funcPos, $funcToken['scope_closer'], $type, $file
                    );

                    $this->rebind($file, false, $newMethod);
                }
                // Check if it's a Role definition
                else if($rolePos = $this->parser_findNext(T_VARIABLE, $stackPtr)) {
                    $name = substr($tokens[$rolePos]['content'], 1);

                    // Check if normal var or a Role
                    if(preg_match($this->roleFormat, $name)) {
                        if(!$this->_ignoreNextRole) {
                            $this->roles_add($name, $rolePos, $type, $file);
                        }
                        $this->_ignoreNextRole = false;
                    }
                }
                break;

            case T_VARIABLE:
                if($current['content'] == '$this' && $callPos = $this->parser_findNext(T_STRING, $stackPtr)) {
                    $isAssignment = null;
                    $assignPos = $callPos;

                    while($isAssignment === null) {
                        $assignPos++;
                        switch($tokens[$assignPos]['code']) {
                            case T_WHITESPACE:
                                break;
                            case "PHPCS_T_EQUAL":
                                $isAssignment = true;
                                break;
                            default:
                                $isAssignment = false;
                                break;
                        }

                    }

                    $name = $tokens[$callPos]['content'];
                    $this->currentMethod_addRoleRef($name, $callPos, $isAssignment);
                }
                break;    
        }
    }
}
