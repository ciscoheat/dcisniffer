<?php

namespace PHP_CodeSniffer\Standards\DCI\Sniffs;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

require_once __DIR__ . '/../Context.php';
require_once __DIR__ . '/../CheckDCIRules.php';

use PHP_CodeSniffer\Standards\DCI\Context;
use PHP_CodeSniffer\Standards\DCI\Role;
use PHP_CodeSniffer\Standards\DCI\Method;
use PHP_CodeSniffer\Standards\DCI\Ref;

use PHP_CodeSniffer\Standards\DCI\CheckDCIRules;

/**
 * @context
 */
final class RoleConventionsSniff implements Sniff {
    /**
     * @noDCIRole
     */
    public $roleFormat = '/^([a-zA-Z0-9]+)$/';

    /**
     * @noDCIRole
     */
    public $roleMethodFormat = '/^([a-zA-Z0-9]+)_+([a-zA-Z0-9]+)$/';

    ///// State ///////////////////////////////////////////

    private bool $_ignoreNextRole = false;
    private array $_ignoredRoles = [];
    private array $_addMethodToRole = [];

    /**
     * Must always be set when process is called.
     */
    private File $_parser;

    ///// Methods /////////////////////////////////////////

    private function _findNext($type, int $start, ?string $value = null, bool $local = true) {
        return $this->_parser->findNext(
            $type, $start, null, false, $value, $local
        );
    }

    private function _rebind(int $stackPtr) {
        $tokens = $this->_parser->getTokens();
        $current = $tokens[$stackPtr];

        switch($current['code']) {
            case T_DOC_COMMENT_TAG:
                if($this->context_exists()) break;

                $tag = strtolower($current['content']);
                $tagged = in_array($tag, ['@context', '@dci', '@dcicontext']);

                if($tagged && $classPos = $this->_findNext(T_CLASS, $stackPtr)) {
                    // New class found
                    $class = $tokens[$classPos];
                    $this->context = new Context($class['scope_opener'], $class['scope_closer']);
                    return true;
                }
                break;
            
            case T_PRIVATE:
            case T_PROTECTED:
            case T_PUBLIC:
                if(!$this->context_exists()) break;

                // Check if it's a method
                if($funcPos = $this->_findNext(T_FUNCTION, $stackPtr)) {                    
                    $funcToken = $tokens[$funcPos];

                    $funcNamePos = $this->_findNext(T_STRING, $funcPos);
                    $funcName = $tokens[$funcNamePos]['content'];

                    $this->currentMethod = $this->context_addMethod(
                        $funcName, $funcPos, $funcToken['scope_closer'], $current['code']
                    );

                    return true;
                }
                break;

            case T_CLOSE_CURLY_BRACKET:
                // Check end of Context or Method
                if($this->context_exists() && $current['scope_closer'] == $this->context_endPos()) {
                    // Context ends, check rules
                    $this->context_attachMethodsToRoles();                    
                    (new CheckDCIRules($this->_parser, $this->context))->check();
                    $this->context = null;
                    return true;
                } else if($this->currentMethod_exists() && $current['scope_closer'] == $this->currentMethod_endPos()) {
                    // Method ends
                    $this->currentMethod = null;
                    return true;
                }
                break;
    
        }

        return false;
    }

    ///// Roles /////////////////////////////////////////////////////

    private ?Method $currentMethod = null;
    
    protected function currentMethod_exists() : bool {
        return !!$this->currentMethod;
    }

    protected function currentMethod_endPos() : int {
        return $this->currentMethod->end();
    }

    protected function currentMethod_addRef(string $to, int $pos, bool $isAssignment) {
        if(in_array($to, $this->_ignoredRoles)) return;

        $isRoleMethod = !!preg_match($this->roleMethodFormat, $to);
        $isRole = !$isRoleMethod && !!preg_match($this->roleFormat, $to);

        if(!$isRole && !$isRoleMethod) return;

        $type = $isRoleMethod ? Ref::ROLEMETHOD : Ref::ROLE;
        $ref = new Ref($to, $pos, $type, $isAssignment);

        $this->currentMethod->addRef($ref);
    }
    
    ///////////////////////////////////////////////////////

    private ?Context $context = null;

    protected function context_exists() : bool {
        return !!$this->context;
    }

    protected function context_endPos() : int {
        return $this->context->end();
    }

    protected function context_addRole(string $name, int $pos, int $access) {
        if($access != T_PRIVATE) {
            $msg = 'Role "%s" must be private.';
            $data = [$name];
            $this->_parser->addError($msg, $pos, 'RoleNotPrivate', $data);
        }
        
        $role = new Role($name, $pos, $access);

        return $this->context->addRole($role);
    }

    protected function context_addMethod(string $name, int $start, int $end, int $access) {
        $method = new Method($name, $start, $end, $access);
        
        $isRoleMethod = preg_match($this->roleMethodFormat, $name, $matches);

        if($isRoleMethod) {
            $this->_addMethodToRole[] = (object)['method' => $method, 'roleName' => $matches[1], 'methodName' => $matches[2]];
        }

        $this->context->addMethod($method);

        return $method;
    }

    protected function context_attachMethodsToRoles() {
        $roles = $this->context->roles();

        foreach($this->_addMethodToRole as $attach) {
            $role = $roles[$attach->roleName] ?? null;
            if($role) {
                $role->addMethod($attach->methodName, $attach->method);
            } else {
                $msg = 'Role "%s" does not exist. Add it as "private $%s;" above this RoleMethod.';
                $data = [$attach->roleName, $attach->roleName];
                $this->_parser->addError($msg, $attach->method->start(), 'NonExistingRole', $data);
            }
        }
    }

    ///////////////////////////////////////////////////////

    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array(int)
     */
    public function register() {
        return [
            T_DOC_COMMENT_TAG, T_CLOSE_CURLY_BRACKET, // Context detection
            T_PUBLIC, T_PRIVATE, T_PROTECTED, // Role/RoleMethod access
            T_VARIABLE, // Role assignments
            T_RETURN // Role leaking
        ];
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
        $this->_parser = $file;
     
        if($this->_rebind($stackPtr) || !$this->context_exists()) 
            return;

        $tokens = $this->_parser->getTokens();
        $current = $tokens[$stackPtr];
        $type = $current['code'];

        //if(!file_exists('e:\temp\tokens.json')) file_put_contents('e:\temp\tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));
                
        switch ($type) {
            case T_DOC_COMMENT_TAG:
                $tag = strtolower($current['content']);

                if(in_array($tag, ['@norole', '@nodcirole', '@ignorerole', '@ignoredcirole'])) {
                    $this->_ignoreNextRole = true;
                }
                break;

            case T_PRIVATE:
            case T_PROTECTED:
            case T_PUBLIC:
                assert(!$this->currentMethod_exists(), 'currentMethod should not exist.');
                
                // Check if it's a Role definition
                if($rolePos = $this->_findNext(T_VARIABLE, $stackPtr)) {
                    $name = substr($tokens[$rolePos]['content'], 1);

                    // Check if normal var or a Role
                    if(preg_match($this->roleFormat, $name)) {
                        if(!$this->_ignoreNextRole) {
                            $this->context_addRole($name, $rolePos, $type);
                        } else {
                            $this->_ignoreNextRole = false;
                            $this->_ignoredRoles[] = $name;
                        }
                    }
                }
                break;

            case T_VARIABLE:
                if(!$this->currentMethod_exists()) break;

                // Check if a Role or RoleMethod is referenced.
                if($current['content'] == '$this' && $varPos = $this->_findNext(T_STRING, $stackPtr)) {
                    $isAssignment = null;
                    $assignPos = $varPos;

                    while($isAssignment === null) {
                        $assignPos++;
                        switch($tokens[$assignPos]['code']) {
                            case T_WHITESPACE:
                            case T_COMMENT:
                                break;
                            default:
                                $token = $tokens[$assignPos]['code'];
                                $isAssignment = !!(Tokens::$assignmentTokens[$token] ?? false);
                                break;
                        }

                    }

                    $name = $tokens[$varPos]['content'];
                    $this->currentMethod_addRef($name, $varPos, $isAssignment);
                }
                break; 
        }
    }
}
