<?php


namespace PHP_CodeSniffer\Standards\DCI\Sniffs;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

require_once __DIR__ . '/../DCIRole.php';
use PHP_CodeSniffer\Standards\DCI\DCIRole;

class RoleConventionsSniff implements Sniff {
    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array(int)
     */
    public function register() {
        return [
            T_PRIVATE, T_PROTECTED, 
            T_CLASS, T_CLOSE_CURLY_BRACKET,
            T_VARIABLE,
            T_DOC_COMMENT_TAG
        ];
    }

    private $_roles = [];

    private function role(string $name) {
        if(!array_key_exists($name, $this->_roles)) {
            $this->_roles[$name] = new DCIRole($name);
        }
        return $this->_roles[$name];
    }

    private int $_classStart = 0;
    private int $_classEnd = 0;

    private ?object $_currentRoleMethod = null;

    private $_calls = [];

    private function checkRules($file) {
        // Sort all roles by pos to check method locations
        $roles = array_values($this->_roles);
        usort($roles, function($a, $b) {
            return $a->pos < $b->pos ? -1 : 1;
        });
        
        //print_r($roles);
        foreach ($roles as $key => $role) {
            if($role->pos == 0) {
                $msg = 'Role "%s" does not exist. Add it as a private var above its RoleMethods.';
                $data = [$role->name];
                $file->addError($msg, reset($role->roleMethods)->pos, 'NoRoleExists', $data);
            }

            $start = $role->pos;
            $end = $roles[$key + 1]->pos ?? PHP_INT_MAX;

            foreach($role->roleMethods as $name => $method) {
                if($method->pos < $start || $method->pos > $end) {
                    $msg = 'RoleMethod "%s->%s" is not positioned below its Role.';
                    $data = [$role->name, $name];
                    $file->addError($msg, $method->pos, 'RoleMethodPos', $data);    
                }
            }
        }

        // Check valid role method calls
        foreach($this->_calls as $call) {
            extract($call);
            // Redundant check:
            //if($from->name == $to) continue;

            $roleMethod = $this->_roles[$to]->roleMethods[$method];

            if($roleMethod->access == T_PRIVATE) {
                $msg = 'Private RoleMethod "%s->%s" called outside its own RoleMethods here.';
                $data = [$to, $method];
                $file->addError($msg, $callPos, 'RoleMethodAccessError', $data);

                $msg = 'Private RoleMethod "%s->%s" called outside its own RoleMethods. Make it protected if this is intended.';
                $data = [$to, $method];
                $file->addError($msg, $roleMethod->pos, 'AdjustRoleMethodAccess', $data);
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

                if(!$file->getClassProperties($classPos)['is_final']) {
                    $msg = 'A DCI Context must be final.';
                    $file->addError($msg, $classPos, 'ContextNotFinal');
                }
            }
        }
        
        // Only parse inside a class
        if($this->_classStart == 0) return;

        if($type == T_CLOSE_CURLY_BRACKET) {
            if($this->_classStart > 0 && $current['scope_closer'] == $this->_classEnd) {
                // Class ended, check all DCI rules.
                $this->checkRules($file);                
                // Reset class state
                $this->_classStart = 0;
                $this->_classEnd = 0;
                $this->_roles = [];
            }
            else if($this->_currentRoleMethod && $current['scope_closer'] == $this->_currentRoleMethod->end) {
                // RoleMethod ended.
                $this->_currentRoleMethod = null;
            }
        }
        else if($type == T_PRIVATE || $type == T_PROTECTED) {
            //var_dump($current);

            // Check if it's a RoleMethod
            if($funcPos = $file->findNext(T_FUNCTION, $stackPtr, null, false, null, true)) {
                $funcNamePos = $file->findNext(T_STRING, $funcPos);
                $funcName = $tokens[$funcNamePos]['content'];

                // TODO: Allow different convention than underscore
                if(preg_match('/^([a-zA-Z]+)_+([a-zA-Z]+)$/', $funcName, $matches)) {
                    $this->_currentRoleMethod = $this->role($matches[1])->addMethod(
                        $matches[2], 
                        $funcNamePos,
                        $tokens[$funcPos]['scope_closer'],
                        $type
                    );
                }
            }
            // Check if it's a Role definition
            else if($rolePos = $file->findNext(T_VARIABLE, $stackPtr, null, false, null, true)) {
                $name = substr($tokens[$rolePos]['content'], 1);
                // Check if normal var or a Role
                // TODO: Allow different convention than underscore
                if(strpos($name, '_') === false) {
                    if($type != T_PRIVATE) {
                        $msg = 'Role "%s" must be private.';
                        $data = [$name];
                        $file->addError($msg, $rolePos, 'InvalidRoleAccess', $data);
                    }
                    else {
                        $this->role($name)->pos = $rolePos;
                    }
                }
            }
        }
        else if($type == T_VARIABLE && $current['content'] == '$this') {
            if($methodPos = $file->findNext(T_STRING, $stackPtr, null, false, null, true)) {
                $name = $tokens[$methodPos]['content'];
                
                // TODO: Allow different convention than underscore
                $pos = strpos($name, '_');

                // Check if Role is accessed directly
                if($pos === false) {
                    if(array_key_exists($name, $this->_roles)) {
                        if(!$this->_currentRoleMethod || $this->_currentRoleMethod->role->name != $name) {
                            $msg = 'Role "%s" accessed outside its RoleMethods';
                            $data = [$name];
                            $file->addError($msg, $methodPos, 'RoleAccessedOutsideItsMethods', $data);        
                        }
                    }                    
                } else if($pos > 0) {
                    $roleName = substr($name, 0, $pos);
                    
                    // TODO: Allow different convention than underscore
                    while($name[$pos] == '_') 
                        $pos++;

                    $methodName = substr($name, $pos);
                    
                    // Check role method access, excluding same Role access
                    if(!$this->_currentRoleMethod || $roleName != $this->_currentRoleMethod->role->name) {
                        $this->_calls[] = [
                            'from' => $this->_currentRoleMethod, 
                            'to' => $roleName, 
                            'method' => $methodName,
                            'callPos' => $methodPos
                        ];
                    }
                }
            }
        }

        //file_put_contents('e:\temp\tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));
    }
}
