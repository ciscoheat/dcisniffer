<?php

namespace PHP_CodeSniffer\Standards\DCI\Sniffs;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

require_once __DIR__ . '/../Context.php';
require_once __DIR__ . '/../CheckDCIRules.php';
require_once __DIR__ . '/../ListContextInformation.php';
require_once __DIR__ . '/../ContextVisualization.php';

use PHP_CodeSniffer\Standards\DCI\Context;
use PHP_CodeSniffer\Standards\DCI\Role;
use PHP_CodeSniffer\Standards\DCI\Method;
use PHP_CodeSniffer\Standards\DCI\Ref;

use PHP_CodeSniffer\Standards\DCI\CheckDCIRules;
use PHP_CodeSniffer\Standards\DCI\ListContextInformation;
use PHP_CodeSniffer\Standards\DCI\ContextVisualization;

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
    public bool $listRoleInterfaces = false;

    /**
     * @noDCIRole
     */
    public string $visDataDir = __DIR__ . '/../Visualization';

    ///// State ///////////////////////////////////////////

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
            $this->context_checkForRoleDefinition();
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
        return $this->tokens[$this->_stackPtr];
    }

    protected function tokens_get(int $ptr) {
        return $this->tokens[$ptr];
    }

    ///////////////////////////////////////////////////////

    private File $parser;

    protected function parser_findNext($type, int $start = null, ?string $value = null, bool $local = true) {
        if($start === null) $start = $this->_stackPtr;

        return $this->parser->findNext(
            $type, $start, null, false, $value, $local
        );
    }

    protected function parser_error($msg, $pos, $error, $data = null) : void {
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
            $name = $this->parser->getDeclarationName($classPos);
            return new Context($name, $class['scope_opener'], $class['scope_closer']);
        }

        return null;
    }

    protected function parser_checkNewMethod() : ?Method {
        if(!$this->context_exists()) return null;
        if($this->currentMethod_exists()) return null;

        $current = $this->tokens_current();

        if($current['code'] != T_PRIVATE &&
            $current['code'] != T_PROTECTED &&
            $current['code'] != T_PUBLIC) {
            return null;
        }

        // Check if it's a method
        if($funcPos = $this->parser_findNext(T_FUNCTION)) {                    
            $funcToken = $this->tokens_get($funcPos);

            $funcNamePos = $this->parser_findNext(T_STRING, $funcPos);
            $funcName = $this->tokens_get($funcNamePos)['content'];

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

        if(!($varPos = $this->parser_findNext(T_STRING))) return;

        $to = $this->tokens_get($varPos)['content'];

        $ignoredRole = in_array($to, $this->_ignoredRoles);
        $isRoleMethod = !!preg_match($this->roleMethodFormat, $to);
        $isRole = !$ignoredRole && !$isRoleMethod && !!preg_match($this->roleFormat, $to);

        $type = null;
        $contractCall = null;
        $excepted = false;
        
        $checkPos = $varPos;
        while($type === null) {
            $code = $this->tokens_get(++$checkPos)['code'];

            if(array_key_exists($code, Tokens::$emptyTokens)) {
                continue;
            }

            if($isRole && $code == T_OPEN_SQUARE_BRACKET) {
                $type = Ref::ROLE;
                $contractCall = 'ARRAY';
            }
            else if($isRole && $code == T_OBJECT_OPERATOR) {
                // Role contract access: $this->someRole->method
                $type = Ref::ROLE;
                $callPos = $this->parser_findNext(T_STRING, $checkPos);
                $contractCall = $this->tokens_get($callPos)['content'];
            } 
            else if($code == T_OPEN_PARENTHESIS) {
                // Method access: $this->method(...
                $type = $isRoleMethod ? Ref::ROLEMETHOD : Ref::METHOD;
            } 
            else if($isRole) {
                // Check assignment: $this->someRole = ...
                $isAssignment = array_key_exists($code, Tokens::$assignmentTokens);
                $type = $isAssignment ? Ref::ROLE_ASSIGNMENT : Ref::ROLE;
            } 
            else {
                $type = Ref::PROPERTY;
            }
        }

        $prevToken = $this->tokens_get($this->_stackPtr - 1);
        if($prevToken['code'] == T_ASPERAND) {
            $excepted = true;
        }

        
        $ref = new Ref($to, $varPos, $type, $excepted, $contractCall);
        /*
        if($type == Ref::ROLE && $contractCall) {
            $this->parser_error('Contract call for ' . $to, $varPos, 'DEBUG');
        }
        */

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

    protected function context_checkForRoleDefinition() : void {
        $current = $this->tokens_current();

        if(!in_array($current['code'], [T_PRIVATE, T_PROTECTED, T_PUBLIC]))
            return;

        // Check if it's a Role definition
        if($rolePos = $this->parser_findNext(T_VARIABLE)) {
            $name = substr($this->tokens_get($rolePos)['content'], 1);
            
            // Check if normal var or a Role
            if(preg_match($this->roleFormat, $name)) {
                $tags = [];
                $pos = $this->_stackPtr;
                do {
                    $token = $this->tokens_get(--$pos);
    
                    if($token['code'] == T_DOC_COMMENT_TAG) {
                        $tag = substr($token['content'], 1);

                        if(in_array(strtolower($tag), ['norole', 'nodcirole', 'ignorerole', 'ignoredcirole'])) {
                            $this->_ignoredRoles[] = $name;
                            return;
                        }

                        $tags[] = $tag;
                    }        
                } while($token['code'] == T_WHITESPACE || array_key_exists($token['code'], Tokens::$commentTokens));

                $this->context_addRole($name, $rolePos, $current['code'], $tags);
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
            @$this->parser, $this->context
        ))->check(); 

        (new ListContextInformation(
            @$this->parser, $this->context,
            $this->listRoleInterfaces
        ))->listInformation();

        if($this->visDataDir) {
            (new ContextVisualization(
                @$this->parser, $this->context,
                $this->visDataDir
            ))->saveVisData();
        }
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
                $this->parser_error($msg, $attach->method->start(), 'NonExistingRole', $data);
            }
        }
        $this->_addMethodToRole = [];
    }
}
