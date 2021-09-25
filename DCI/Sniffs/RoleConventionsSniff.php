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
    public string $roleFormat = '/^([a-zA-Z0-9]+)$/';

    /**
     * @noDCIRole
     */
    public string $roleMethodFormat = '/^([a-zA-Z0-9]+)_+([a-zA-Z0-9]+)$/';

    /**
     * @noDCIRole
     */
    public ?string $listCallsInRoleMethod = null;

    /**
     * @noDCIRole
     */
    public ?string $listCallsToRoleMethod = null;

    ///// State ///////////////////////////////////////////

    private bool $_ignoreNextRole = false;
    private array $_ignoredRoles = [];
    private array $_addMethodToRole = [];
    
    ///// Methods /////////////////////////////////////////

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
     
        if($this->_rebind($file, $stackPtr) || !$this->context_exists()) 
            return;

        $tokens = $file->getTokens();
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
                $this->parser_checkForRoleDefinition($stackPtr);
                break;

            case T_VARIABLE:
                $this->parser_checkForReferences($stackPtr);
                break; 
        }
    }

    /**
     * Returns true if Context or CurrentMethod was rebound.
     */
    private function _rebind(File $file, int $stackPtr) : bool {        
        $tokens = $file->getTokens();
        $current = $tokens[$stackPtr];

        $this->parser = $file;
        
        switch($current['code']) {
            case T_DOC_COMMENT_TAG:
                if($newContext = $this->parser_checkNewContext($stackPtr)) {
                    $this->context = $newContext;
                    return true;
                }
                break;
            
            case T_PRIVATE:
            case T_PROTECTED:
            case T_PUBLIC:
                if($newMethod = $this->parser_checkNewMethod($stackPtr)) {
                    $this->currentMethod = $newMethod;
                    return true;
                }
                break;

            case T_CLOSE_CURLY_BRACKET:
                if($this->parser_checkEndOfContext($stackPtr)) {
                    $this->context = null;
                    return true;
                } else  if($this->parser_checkEndOfMethod($stackPtr)) {
                    $this->currentMethod = null;
                    return true;
                }
                break;
        }

        return false;
    }

    ///// Roles ////////////////////////////////////////////////////

    private File $parser;

    protected function parser_addError($msg, $pos, $error, $data = null) {
        $this->parser->addError($msg, $pos, $error, $data);
    }
    
    private function parser_findNext($type, int $start, ?string $value = null, bool $local = true) {
        return $this->parser->findNext(
            $type, $start, null, false, $value, $local
        );
    }

    protected function parser_checkNewContext(int $stackPtr) : ?Context {
        if($this->context_exists()) return null;

        $tokens = $this->parser->getTokens();
        $current = $tokens[$stackPtr];

        $tag = strtolower($current['content']);
        $tagged = in_array($tag, ['@context', '@dci', '@dcicontext']);

        if($tagged && $classPos = $this->parser_findNext(T_CLASS, $stackPtr)) {
            // New class found
            $class = $tokens[$classPos];
            return new Context($class['scope_opener'], $class['scope_closer']);
        }

        return null;
    }

    protected function parser_checkNewMethod(int $stackPtr) : ?Method {
        if($this->currentMethod_exists()) return null;

        $tokens = $this->parser->getTokens();
        $current = $tokens[$stackPtr];

        // Check if it's a method
        if($funcPos = $this->parser_findNext(T_FUNCTION, $stackPtr)) {                    
            $funcToken = $tokens[$funcPos];

            $funcNamePos = $this->parser_findNext(T_STRING, $funcPos);
            $funcName = $tokens[$funcNamePos]['content'];

            return $this->context_addMethod(
                $funcName, $funcPos, $funcToken['scope_closer'], $current['code']
            );
        }

        return null;
    }

    protected function parser_checkEndOfContext(int $stackPtr) : bool {
        if(!$this->context_exists()) return false;

        $tokens = $this->parser->getTokens();
        $current = $tokens[$stackPtr];

        return $this->context_checkIfEnds($current);
    }

    protected function parser_checkEndOfMethod(int $stackPtr) : bool {
        if(!$this->currentMethod_exists()) return false;

        $tokens = $this->parser->getTokens();
        $current = $tokens[$stackPtr];

        if($current['scope_closer'] == $this->currentMethod_endPos()) {
            return true;
        }
        
        return false;
    }

    protected function parser_checkForRoleDefinition(int $stackPtr) {
        assert(!$this->currentMethod_exists(), 'currentMethod should not exist.');

        $tokens = $this->parser->getTokens();
        $current = $tokens[$stackPtr];

        // Check if it's a Role definition
        if($rolePos = $this->parser_findNext(T_VARIABLE, $stackPtr)) {
            $name = substr($tokens[$rolePos]['content'], 1);

            // Check if normal var or a Role
            if(preg_match($this->roleFormat, $name)) {
                if(!$this->_ignoreNextRole) {
                    $this->context_addRole($name, $rolePos, $current['code']);
                } else {
                    $this->_ignoreNextRole = false;
                    $this->_ignoredRoles[] = $name;
                }
            }
        }
    }

    protected function parser_checkForReferences(int $stackPtr) {
        if(!$this->currentMethod_exists()) return;

        $tokens = $this->parser->getTokens();
        $current = $tokens[$stackPtr];

        // Check if a Role or RoleMethod is referenced.
        if($current['content'] == '$this' && $varPos = $this->parser_findNext(T_STRING, $stackPtr)) {
            $isAssignment = null;
            $isObjectAccess = null;
            $checkPos = $varPos;

            // Check for assignment, or direct access
            while($isAssignment === null) {
                $code = $tokens[++$checkPos]['code'];

                if(array_key_exists($code, Tokens::$emptyTokens)) {
                    continue;
                }

                if($code == T_OBJECT_OPERATOR) {
                    $isAssignment = false;
                    $isObjectAccess = true;
                } else {
                    $isAssignment = array_key_exists($code, Tokens::$assignmentTokens);
                    $isObjectAccess = false;
                }
            }

            if(!$isAssignment && !$isObjectAccess) {
                // Probably a Role reference "$this->someRole".
                // Allow outside its RoleMethods if an @ is prepended.
                // Used for instantiating other Contexts.
                $prevToken = $tokens[$stackPtr - 1];
                if($prevToken['code'] == T_ASPERAND) 
                    return;
            }

            $name = $tokens[$varPos]['content'];
            $this->currentMethod_addRef($name, $varPos, $isAssignment);
        }
    }

    /////////////////////////////////////////////////////////////////

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

    private function context_endPos() : int {
        return $this->context->end();
    }

    protected function context_addRole(string $name, int $pos, int $access) {
        if($access != T_PRIVATE) {
            $msg = 'Role "%s" must be private.';
            $data = [$name];
            $this->parser_addError($msg, $pos, 'RoleNotPrivate', $data);
        }
        
        $role = new Role($name, $pos, $access);
        $this->context->addRole($role);

        return $role;
    }

    protected function context_addMethod(string $name, int $start, int $end, int $access) {
        $method = new Method($name, $start, $end, $access);        
        $isRoleMethod = preg_match($this->roleMethodFormat, $name, $matches);

        if($isRoleMethod) {
            // Save the method so it can be attached to its Role
            // when the Context is fully parsed.
            $this->_addMethodToRole[] = (object)['method' => $method, 'roleName' => $matches[1], 'methodName' => $matches[2]];
        }

        $this->context->addMethod($method);

        return $method;
    }

    protected function context_checkIfEnds($token) {
        if($token['scope_closer'] == $this->context_endPos()) {
            // Context ends, check rules
            $this->context_checkRules();
            return true;
        }

        return false;
    }

    private function context_checkRules() {
        $this->context_attachMethodsToRoles();
        (new CheckDCIRules(
            @$this->parser, @$this->context, 
            $this->listCallsInRoleMethod, $this->listCallsToRoleMethod
        ))->check();
    }

    private function context_attachMethodsToRoles() {
        $roles = $this->context->roles();

        foreach($this->_addMethodToRole as $attach) {
            $role = $roles[$attach->roleName] ?? null;
            if($role) {
                $role->addMethod($attach->methodName, $attach->method);
            } else {
                $msg = 'Role "%s" does not exist. Add it as "private $%s;" above this RoleMethod.';
                $data = [$attach->roleName, $attach->roleName];
                $this->parser_addError($msg, $attach->method->start(), 'NonExistingRole', $data);
            }
        }
        $this->_addMethodToRole = [];
    }
}
