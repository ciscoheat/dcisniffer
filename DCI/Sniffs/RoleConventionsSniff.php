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
            T_CLASS, T_CLOSE_CURLY_BRACKET
        ];
    }

    private $roles = [];

    private function role(string $name) {
        if(!array_key_exists($name, $this->roles)) {
            $this->roles[$name] = new DCIRole($name);
        }
        return $this->roles[$name];
    }

    private $start = 0;
    private $end = 0;

    private function checkRules($file) {
        // Sort all roles by pos to check method locations
        $roles = array_values($this->roles);
        usort($roles, function($a, $b) {
            return $a->pos < $b->pos ? -1 : 1;
        });
        
        //print_r($roles);
        foreach ($roles as $pos => $role) {
            $start = $role->pos;
            $end = $roles[$pos + 1]->pos ?? PHP_INT_MAX;

            foreach($role->roleMethods as $name => $method) {
                $methodPos = $method['pos'];

                if($methodPos < $start || $methodPos > $end) {
                    $msg = 'RoleMethod "%s->%s" is not positioned below its Role.';
                    $data = [$role->name, $name];
                    $file->addError($msg, $methodPos, 'RoleMethodPos', $data);    
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
        if($type != T_CLASS && $this->start == 0) {
            return;
        }

        if($type == T_CLASS) {
            // TODO: Check if class is a DCI Context, if not, ignore.
            $this->start = $current['scope_opener'];
            $this->end = $current['scope_closer'];

            // TODO: Check if class is sealed
        }
        else if($type == T_CLOSE_CURLY_BRACKET) {
            if($this->start > 0 && $current['scope_closer'] == $this->end) {
                // Class ended, check all DCI rules.
                $this->checkRules($file);                
                $this->start = 0;
                $this->end = 0;
            }
        }
        else if($type == T_PRIVATE || $type == T_PROTECTED) {
            //var_dump($current);

            // Check if it's a RoleMethod
            if($funcPos = $file->findNext(T_FUNCTION, $stackPtr, null, false, null, true)) {
                $funcNamePos = $file->findNext(T_STRING, $funcPos);
                $funcName = $tokens[$funcNamePos]['content'];

                if(preg_match('/^([a-zA-Z]+)(_{1,2})([a-zA-Z]+)$/', $funcName, $matches)) {
                    $this->role($matches[1])->addMethod(
                        $matches[3], 
                        $funcNamePos,
                        strlen($matches[2] == 1 ? T_PRIVATE : T_PROTECTED
                    ));
                }
            }
            // Check if it's a Role definition
            else if($type == T_PRIVATE && $rolePos = $file->findNext(T_VARIABLE, $stackPtr, null, false, null, true)) {
                $role = substr($tokens[$rolePos]['content'], 1);
                $this->role($role)->pos = $rolePos;
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
