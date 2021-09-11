<?php


namespace PHP_CodeSniffer\Standards\DCI\Sniffs;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

class RoleConventionsSniff implements Sniff {
    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array(int)
     */
    public function register() {
        return [
            T_PUBLIC, T_PRIVATE, T_PROTECTED, 
            T_CLASS, T_CLOSE_CURLY_BRACKET,
            T_VARIABLE,
            T_DOC_COMMENT_TAG
        ];
    }

    private File $file;

    private int $_classStart = 0;
    private int $_classEnd = 0;

    private ?object $_currentMethod = null;

    private $_roles = [];
    private $_methods = [];

    private function addRole(string $name, int $pos, int $access) {
        if($access != T_PRIVATE) {
            $msg = 'Role "%s" must be private.';
            $data = [$name];
            $this->file->addError($msg, $pos, 'RoleIsNotPrivate', $data);
        }

        $base = (object)[
            'name' => $name, 'pos' => $pos, 'access' => $access, 
            'methods' => []
        ];

        return $this->_roles[$name] = $base;
    }

    private function addMethod(string $name, int $start, int $end, int $access) {
        // TODO: Support another convention than underscore
        $isRoleMethod = preg_match('/^([a-zA-Z]+)_+([a-zA-Z]+)$/', $name, $matches);

        if($isRoleMethod && $access == T_PUBLIC) {
            $msg = 'RoleMethod "%s->%s" is public, must be private or protected.';
            $data = [$matches[1], $matches[2]];
            $this->file->addError($msg, $start, 'RoleMethodIsPublic', $data);
        }
        
        $base = (object)[
            'name' => $name, 'start' => $start, 'end' => $end, 'access' => $access, 
            'refs' => [], 'role' => $isRoleMethod ? [$matches[1], $matches[2]] : null
        ];

        return $this->_methods[$name] = $base;
    }

    private function addRoleRef(object $method, string $to, int $pos, bool $isAssignment) {

        // TODO: Support another convention than underscore
        $isRoleMethod = preg_match('/^([a-zA-Z]+)_+([a-zA-Z]+)$/', $to, $matches);
        $isRole = strpos($to, '_') === false;

        if(!$isRole && !$isRoleMethod) return;

        $method->refs[] = (object)[
            //'from' => $method,
            'to' => $to,
            'roleMethod' => $isRoleMethod ? [$matches[1], $matches[2]] : null,
            'pos' => $pos,
            'isAssignment' => $isAssignment
        ];
    }

    private function checkRules() {
        $file = $this->file;

        foreach ($this->_methods as $method) {
            $assigned = [];
            foreach($method->refs as $ref) {
                // Check if assignment
                if($ref->isAssignment) {
                    if(array_key_exists($ref->to, $this->_roles))
                        $assigned[] = $ref->to;
                }
                else if(!$ref->roleMethod) {
                    // References a Role directly, allowed only if in one of its RoleMethods
                    if(!$method->role || $method->role[0] != $ref->to) {
                        $msg = 'Role "%s" accessed outside its RoleMethods';
                        $data = [$ref->to];
                        $file->addError($msg, $ref->pos, 'RoleAccessedOutsideItsMethods', $data);
                    }
                } else {
                    // References a RoleMethod, check access
                    $roleMethod = $this->_methods[$ref->to];
                    $roleName = $roleMethod->role[0];
                    $roleMethodName = $roleMethod->role[1];

                    if(!array_key_exists($roleName, $this->_roles)) {
                        $msg = 'Role "%s" does not exist. Add it as "private $%s;" above its RoleMethods.';
                        $data = [$roleName, $roleName];
                        $file->addError($msg, $roleMethod->start, 'NoRoleExists', $data);
                    } else {
                        // Add RoleMethod to the Role
                        $this->_roles[$roleName]->methods[$roleMethodName] = $roleMethod;
                    }
    
                    if((!$method->role || $method->role[0] != $roleName) && $roleMethod->access == T_PRIVATE) {
                        $msg = 'Private RoleMethod "%s->%s" accessed outside its own RoleMethods here.';
                        $data = $ref->roleMethod;
                        $file->addError($msg, $ref->pos, 'InvalidRoleMethodAccess', $data);
    
                        $msg = 'Private RoleMethod "%s->%s" accessed outside its own RoleMethods. Make it protected if this is intended.';
                        $data = $ref->roleMethod;
                        $file->addError($msg, $roleMethod->start, 'AdjustRoleMethodAccess', $data);
                    }
                }    
            }

            if(count($assigned) > 0 && count($assigned) < count($this->_roles)) {
                $missing = array_diff(array_keys($this->_roles), $assigned);
                $msg = 'Not all Roles are bound inside a single method. Missing: %s';
                $data = [join(",", $missing)];
                $file->addError($msg, $method->start, 'UnboundRoles', $data);
                return;
            }
        }
        
        $roles = array_values($this->_roles);
        foreach($roles as $key => $role) {
            $start = $role->pos;
            $end = $roles[$key + 1]->pos ?? PHP_INT_MAX;

            foreach($role->methods as $name => $method) {
                if($method->start < $start || $method->start > $end) {
                    $msg = 'RoleMethod "%s->%s" is not positioned below its Role.';
                    $data = $method->role;
                    $file->addError($msg, $method->start, 'RoleMethodPosition', $data);
                }
            }
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
        $this->file = $file;

        $tokens = $file->getTokens();
        $current = $tokens[$stackPtr];
        $type = $current['code'];

        // Check if class should be parsed
        if($type == T_DOC_COMMENT_TAG && $this->_classStart == 0) {
            $tag = strtolower($current['content']);

            if(
                in_array($tag, ['@context', '@dci', '@dcicontext'])
                &&
                $classPos = $file->findNext(T_CLASS, $stackPtr, null, false, null, true)
            ) {
                $class = $tokens[$classPos];

                $this->_classStart = $class['scope_opener'];
                $this->_classEnd = $class['scope_closer'];
            }
        }
        
        // Only parse inside a class
        if($this->_classStart == 0) return;

        if($type == T_CLOSE_CURLY_BRACKET) {
            if($this->_classStart > 0 && $current['scope_closer'] == $this->_classEnd) {
                // Class ended, check all DCI rules.
                $this->checkRules();
                // Reset class state
                $this->_classStart = 0;
                $this->_classEnd = 0;
                $this->_roles = [];
                $this->_methods = [];
            }
            else if($this->_currentMethod && $current['scope_closer'] == $this->_currentMethod->end) {
                // RoleMethod ended.
                $this->_currentMethod = null;
            }
        }
        else if($type == T_PRIVATE || $type == T_PROTECTED || $type == T_PUBLIC) {
            // Check if it's a method
            if($funcPos = $file->findNext(T_FUNCTION, $stackPtr, null, false, null, true)) {
                $funcToken = $tokens[$funcPos];
                
                $funcNamePos = $file->findNext(T_STRING, $funcPos);
                $funcName = $tokens[$funcNamePos]['content'];

                $this->_currentMethod = $this->addMethod(
                    $funcName, $funcPos, $funcToken['scope_closer'], $type, $file
                );
            }
            // Check if it's a Role definition
            else if($rolePos = $file->findNext(T_VARIABLE, $stackPtr, null, false, null, true)) {
                $name = substr($tokens[$rolePos]['content'], 1);
                // Check if normal var or a Role
                // TODO: Support another convention than underscore
                if(strpos($name, '_') === false) {
                    $this->addRole($name, $rolePos, $type, $file);
                }
            }
        }
        else if($type == T_VARIABLE && $current['content'] == '$this') {
            if($callPos = $file->findNext(T_STRING, $stackPtr, null, false, null, true)) {
                $name = $tokens[$callPos]['content'];
                $isAssignment = $file->findNext(T_EQUAL, $callPos, null, false, null, true);
                $this->addRoleRef($this->_currentMethod, $name, $callPos, $isAssignment);
            }
        }
    }
}
