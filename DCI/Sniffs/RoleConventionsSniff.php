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

    /**
     * @noDCIRole
     */
    public bool $listRoleInterfaces = false;

    ///// State ///////////////////////////////////////////

    private bool $_ignoreNextRole = false;
    private array $_ignoredRoles = [];
    private array $_addMethodToRole = [];
    private int $_stackPtr;
    
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

        //if(!file_exists('e:\temp\tokens.json')) file_put_contents('e:\temp\tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));

        if(!$this->currentMethod_exists())
            $this->context_checkForIgnoredRole();
        else
            $this->currentMethod_checkForReferences();
    }

    /**
     * Returns true if Context or CurrentMethod was rebound.
     */
    private function _rebind(File $file, int $stackPtr) : bool {        
        $this->parser = $file;
        $this->tokens = $file->getTokens();
        $this->_stackPtr = $stackPtr;

        if($newContext = $this->parser_checkNewContext()) {
            $this->context = $newContext;
            return true;
        }
        else if($newMethod = $this->parser_checkNewMethod()) {
            $this->currentMethod = $newMethod;
            return true;
        }
        else if($this->context_checkIfEnds()) {
            $this->context = null;
            return true;
        }
        else if($this->currentMethod_checkIfEnds()) {
            $this->currentMethod = null;
            return true;
        }

        return false;
    }

    ///// Roles ////////////////////////////////////////////////////

    private array $tokens;

    protected function tokens_current() {
        return $this->tokens_get($this->_stackPtr);
    }

    protected function tokens_get(int $ptr) {
        return $this->tokens[$ptr];
    }

    ///////////////////////////////////////////////////////

    private File $parser;

    private function parser_tokens() : array {
        return $this->parser->getTokens();
    }
    
    protected function parser_findNext($type, int $start = null, ?string $value = null, bool $local = true) {
        if($start === null) $start = $this->_stackPtr;

        return $this->parser->findNext(
            $type, $start, null, false, $value, $local
        );
    }

    protected function parser_findPrevious($type, int $start = null, ?string $value = null, bool $local = true) {
        if($start === null) $start = $this->_stackPtr;

        return $this->parser->findPrevious(
            $type, $start, null, false, $value, $local
        );
    }

    protected function parser_addError($msg, $pos, $error, $data = null) : void {
        $this->parser->addError($msg, $pos, $error, $data);
    }

    protected function parser_checkNewContext() : ?Context {
        if($this->context_exists()) return null;

        $current = $this->tokens_current();

        if($current['code'] != T_DOC_COMMENT_TAG) return null;

        $tag = strtolower($current['content']);
        $tagged = in_array($tag, ['@context', '@dci', '@dcicontext']);

        if($tagged && $classPos = $this->parser_findNext(T_CLASS)) {
            // New class found
            $class = $this->tokens_get($classPos);
            return new Context($class['scope_opener'], $class['scope_closer']);
        }

        return null;
    }

    protected function parser_checkNewMethod() : ?Method {
        if(!$this->context_exists()) return null;
        if($this->currentMethod_exists()) return null;

        $tokens = $this->parser_tokens();
        $current = $this->tokens_current();

        if($current['code'] != T_PRIVATE &&
            $current['code'] != T_PROTECTED &&
            $current['code'] != T_PUBLIC) {
            return null;
        }

        // Check if it's a method
        if($funcPos = $this->parser_findNext(T_FUNCTION)) {                    
            $funcToken = $tokens[$funcPos];

            $funcNamePos = $this->parser_findNext(T_STRING, $funcPos);
            $funcName = $tokens[$funcNamePos]['content'];

            $tags = [];
            $pos = $this->_stackPtr;
            do {
                $token = $this->tokens_get(--$pos);

                if($token['code'] == T_DOC_COMMENT_TAG)
                    $tags[] = substr($token['content'], 1);
            } while($token['code'] == T_WHITESPACE || array_key_exists($token['code'], Tokens::$commentTokens));

            return $this->context_addMethod(
                $funcName, $funcPos, $funcToken['scope_closer'], $current['code'], $tags
            );
        }

        return null;
    }

    /////////////////////////////////////////////////////////////////

    private ?Method $currentMethod = null;
    
    protected function currentMethod_exists() : bool {
        return !!$this->currentMethod;
    }

    protected function currentMethod_checkForReferences() : void {
        $current = $this->tokens_current();

        if($current['content'] != '$this') return;

        // Check if a Role or RoleMethod is referenced.
        if($varPos = $this->parser_findNext(T_STRING)) {
            $isAssignment = null;
            $objectAccess = null;
            $checkPos = $varPos;

            // Check for assignment, or direct access
            while($isAssignment === null) {
                $code = $this->tokens_get(++$checkPos)['code'];

                if(array_key_exists($code, Tokens::$emptyTokens)) {
                    continue;
                }

                if($code == T_OBJECT_OPERATOR) {
                    // Role access: $this->someRole->objMethod
                    $isAssignment = false;
                    $objectAccess = $this->tokens_get($checkPos + 1);
                } else {
                    // Check assignment: $this->someRole = ...
                    $isAssignment = array_key_exists($code, Tokens::$assignmentTokens);
                    $objectAccess = false;
                }
            }

            if(!$isAssignment && !$objectAccess) {
                // Probably a Role reference "$this->someRole".
                // Allow outside its RoleMethods if an @ is prepended.
                // Used for instantiating other Contexts.
                $prevToken = $this->tokens_get($this->_stackPtr - 1);
                if($prevToken['code'] == T_ASPERAND) 
                    return;
            }

            $name = $this->tokens_get($varPos)['content'];
            $this->currentMethod_addRef($name, $varPos, $isAssignment, $objectAccess);
        }
    }

    private function currentMethod_addRef(string $to, int $pos, bool $isAssignment, $objectAccess) : void {
        if(in_array($to, $this->_ignoredRoles)) return;

        $isRoleMethod = !!preg_match($this->roleMethodFormat, $to);
        $isRole = !$isRoleMethod && !!preg_match($this->roleFormat, $to);

        // Only check references for Roles and RoleMethods
        if(!$isRole && !$isRoleMethod) return;

        $type = $isRoleMethod ? Ref::ROLEMETHOD : Ref::ROLE;

        $calls = 
            $type == Ref::ROLE && $objectAccess && $objectAccess['code'] == T_STRING
            ? $objectAccess['content']
            : null;

        $ref = new Ref($to, $pos, $type, $isAssignment, $calls);

        $this->currentMethod->addRef($ref);
    }

    protected function currentMethod_checkIfEnds() : bool {
        if(!$this->currentMethod_exists()) return false;

        $current = $this->tokens_current();

        if($current['code'] != T_CLOSE_CURLY_BRACKET) return false;

        return $current['scope_closer'] == $this->currentMethod->end();
    }
    
    ///////////////////////////////////////////////////////

    private ?Context $context = null;

    protected function context_exists() : bool {
        return !!$this->context;
    }

    private function context_endPos() : int {
        return $this->context->end();
    }

    private function context_addRole(string $name, int $pos, int $access, array $tags) : Role {
        if($access != T_PRIVATE) {
            $msg = 'Role "%s" must be private.';
            $data = [$name];
            $this->parser_addError($msg, $pos, 'RoleNotPrivate', $data);
        }
        
        $role = new Role($name, $pos, $access, $tags);
        $this->context->addRole($role);

        return $role;
    }

    protected function context_addMethod(string $name, int $start, int $end, int $access, array $tags) : Method {
        $method = new Method($name, $start, $end, $access, $tags);
        $isRoleMethod = preg_match($this->roleMethodFormat, $name, $matches);

        if($isRoleMethod) {
            // Save the method so it can be attached to its Role
            // when the Context is fully parsed.
            $this->_addMethodToRole[] = (object)['method' => $method, 'roleName' => $matches[1], 'methodName' => $matches[2]];
        }

        $this->context->addMethod($method);

        return $method;
    }

    protected function context_checkForIgnoredRole() : void {
        $current = $this->tokens_current();

        if($current['code'] == T_DOC_COMMENT_TAG) {
            $tag = strtolower($current['content']);
            
            if(in_array($tag, ['@norole', '@nodcirole', '@ignorerole', '@ignoredcirole'])) {
                $this->_ignoreNextRole = true;
            }
        } else {
            $this->context_checkForRoleDefinition();
        }
    }

    private function context_checkForRoleDefinition() : void {
        $current = $this->tokens_current();

        if(!in_array($current['code'], [T_PRIVATE, T_PROTECTED, T_PUBLIC]))
            return;

        // Check if it's a Role definition
        if($rolePos = $this->parser_findNext(T_VARIABLE)) {
            $name = substr($this->tokens_get($rolePos)['content'], 1);
            
            // Check if normal var or a Role
            if(preg_match($this->roleFormat, $name)) {
                if(!$this->_ignoreNextRole) {
                    $tags = [];
                    $pos = $this->_stackPtr;
                    do {
                        $token = $this->tokens_get(--$pos);
        
                        if($token['code'] == T_DOC_COMMENT_TAG) {
                            $tags[] = substr($token['content'], 1);
                        }        
                    } while($token['code'] == T_WHITESPACE || array_key_exists($token['code'], Tokens::$commentTokens));
                
                    $this->context_addRole($name, $rolePos, $current['code'], $tags);
                    return;
                } else {
                    $this->_ignoreNextRole = false;
                    $this->_ignoredRoles[] = $name;
                }
            }
        }
    }

    protected function context_checkIfEnds() : bool {
        if(!$this->context_exists()) return false;

        $current = $this->tokens_current();

        if($current['code'] != T_CLOSE_CURLY_BRACKET) return false;

        if($current['scope_closer'] == $this->context_endPos()) {
            // Context ends, check rules
            $this->context_checkRules();
            return true;
        }

        return false;
    }

    private function context_checkRules() : void {
        $this->context_attachMethodsToRoles();
        (new CheckDCIRules(
            @$this->parser, @$this->context, 
            $this->listCallsInRoleMethod, $this->listCallsToRoleMethod,
            $this->listRoleInterfaces
        ))->check();
    }

    private function context_attachMethodsToRoles() : void {
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
