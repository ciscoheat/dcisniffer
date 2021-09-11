<?php


namespace PHP_CodeSniffer\Standards\DCI\Sniffs;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

class RoleBindingsSniff implements Sniff {
    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array(int)
     */
    public function register() {
        return [
            T_PRIVATE,
            T_FUNCTION,
            T_EQUAL,
            T_DOC_COMMENT_TAG,
            T_CLOSE_CURLY_BRACKET
        ];
    }

    private $_roles = [];

    private $_currentMethodStartsAt = 0;
    private $_currentMethodEndsAt = 0;
    private $_methodBindings = [];

    private function addCurrentBinding($assigns) {
        if(!array_key_exists($this->_currentMethodStartsAt, $this->_methodBindings)) {
            $this->_methodBindings[$this->_currentMethodStartsAt] = [$assigns];
        } else {
            $this->_methodBindings[$this->_currentMethodStartsAt][] = $assigns;
        }
    }

    private int $_classStart = 0;
    private int $_classEnd = 0;

    private function checkRules($file) {
        $assigned = [];
        foreach ($this->_methodBindings as $pos => $assignments) {
            foreach ($assignments as $assignment) {
                if(array_key_exists($assignment, $this->_roles)) {
                    $assigned[$assignment] = $this->_roles[$assignment];
                }
            }
            if(count($assigned) > 0 && count($assigned) < count($this->_roles)) {
                $missing = array_diff(array_keys($this->_roles), array_keys($assigned));
                $msg = 'Not all Roles are bound inside a single method. Missing: %s';
                $data = [join(",", $missing)];
                $file->addError($msg, $pos, 'UnboundRoles', $data);
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
                $this->_methodBindings = [];
            }
            else if($this->_currentMethodEndsAt && $current['scope_closer'] == $this->_currentMethodEndsAt) {
                // RoleMethod ended.
                $this->_currentMethodStartsAt = 0;
                $this->_currentMethodEndsAt = 0;
            }
        }
        // Check if it's a Role definition
        else if($type == T_PRIVATE) {
            // Check that we're not going into a function
            if(!$file->findNext(T_FUNCTION, $stackPtr, null, false, null, true)) {
                $rolePos = $file->findNext(T_VARIABLE, $stackPtr, null, false, null, true);
                $name = substr($tokens[$rolePos]['content'], 1);
                // Check if normal var or a Role
                // TODO: Allow different convention than underscore
                if(strpos($name, '_') === false) {
                    // If assigned inline, exclude from checks
                    if(!$file->findNext(T_EQUAL, $rolePos, null, false, null, true))
                        $this->_roles[$name] = $rolePos;
                }
            }
        }        
        else if($type == T_FUNCTION) {
            //var_dump($current);
            $this->_currentMethodStartsAt = $current['scope_condition'];
            $this->_currentMethodEndsAt = $current['scope_closer'];
        }
        else if($type == T_EQUAL) {
            if($thisPos = $file->findPrevious(T_VARIABLE, $stackPtr, null, false, '$this', true)) {
                $varPos = $file->findNext(T_STRING, $thisPos, null, false, null, true);
                $name = $tokens[$varPos]['content'];
                
                // TODO: Allow different convention than underscore
                if(strpos($name, '_') === false) {
                    $this->addCurrentBinding($name);
                }
            }
        }

        //file_put_contents('e:\temp\tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));
    }
}
