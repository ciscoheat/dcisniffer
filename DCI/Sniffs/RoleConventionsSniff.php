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
            T_VARIABLE
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

    private ?DCIRole $_currentRole = null;
    private ?object $_currentRoleMethod = null;

    private function checkRules($file) {
        // Sort all roles by pos to check method locations
        $roles = array_values($this->_roles);
        usort($roles, function($a, $b) {
            return $a->pos < $b->pos ? -1 : 1;
        });
        
        //print_r($roles);
        foreach ($roles as $pos => $role) {
            $start = $role->pos;
            $end = $roles[$pos + 1]->pos ?? PHP_INT_MAX;

            foreach($role->roleMethods as $name => $method) {
                if($method->pos < $start || $method->pos > $end) {
                    $msg = 'RoleMethod "%s->%s" is not positioned below its Role.';
                    $data = [$role->name, $name];
                    $file->addError($msg, $method->pos, 'RoleMethodPos', $data);    
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
        $tokens = $file->getTokens();
        $current = $tokens[$stackPtr];
        $type = $current['code'];

        // Only parse inside a class
        if($type != T_CLASS && $this->_classStart == 0) {
            return;
        }

        if($type == T_CLASS) {
            // TODO: Check if class is a DCI Context, if not, ignore.
            $this->_classStart = $current['scope_opener'];
            $this->_classEnd = $current['scope_closer'];

            // TODO: Check if class is sealed
        }
        else if($type == T_CLOSE_CURLY_BRACKET) {
            if($this->_classStart > 0 && $current['scope_closer'] == $this->_classEnd) {
                // Class ended, check all DCI rules.
                $this->checkRules($file);                
                // Reset class state
                $this->_classStart = 0;
                $this->_classEnd = 0;
                $this->_roles = [];
            }
            else if($this->_currentRole && $current['scope_closer'] == $this->_currentRoleMethod->end) {
                // RoleMethod ended.
                $this->_currentRole = null;
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
                    $this->_currentRole = $this->role($matches[1])->addMethod(
                        $matches[2], 
                        $funcNamePos,
                        $tokens[$funcPos]['scope_closer'],
                        $type
                    );
                    $this->_currentRoleMethod = $this->_currentRole->roleMethods[$matches[2]];
                }
            }
            // Check if it's a Role definition
            else if($rolePos = $file->findNext(T_VARIABLE, $stackPtr, null, false, null, true)) {
                $name = substr($tokens[$rolePos]['content'], 1);
                // Check if normal var or a Role
                // TODO: Allow different convention than underscore
                if($name[0] != '_' && $type != T_PRIVATE) {
                    $msg = 'Role "%s" must be private.';
                    $data = [$name];
                    $file->addError($msg, $rolePos, 'RoleAccess', $data);
                }
                else {
                    $this->role($name)->pos = $rolePos;
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
                        if(!$this->_currentRole || $this->_currentRole->name != $name) {
                            $msg = 'Role "%s" accessed outside its RoleMethods';
                            $data = [$name];
                            $file->addError($msg, $methodPos, 'RoleAccessedOutsideItsMethods', $data);        
                        }
                    }                    
                }
            }
        }

        //file_put_contents('e:\temp\tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));

        /*
        if ($tokens[$stackPtr]['content'][0] === '#') {
            $role = $tokens[$rolePos];
            $msg = 'Found role: %s';
            $data  = array(trim($tokens[$rolePos]['content']));
            $file->addWarning($msg, $rolePos, 'Found', $data);    
        }
        */
    }
}
