<?php


namespace PHP_CodeSniffer\Standards\DCI\Sniffs;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

class ContextFinalSniff implements Sniff {
    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array(int)
     */
    public function register() {
        return [
            T_DOC_COMMENT_TAG, T_CLOSE_CURLY_BRACKET, // Context detection
            T_CLASS // "final" keyword check
        ];
    }

    private File $file;
    private array $tokens;

    private int $_classStart = 0;
    private int $_classEnd = 0;

    private function checkClassStart($token, $stackPtr) {
        // Check if class should be parsed
        if($token['code'] == T_DOC_COMMENT_TAG && $this->_classStart == 0) {
            $tag = strtolower($token['content']);
            $tagged = in_array($tag, ['@context', '@dci', '@dcicontext']);

            if(
                $tagged &&
                $classPos = $this->file->findNext(
                    T_CLASS, $stackPtr, null, false, null, true
                )
            ) {
                $class = $this->tokens[$classPos];

                $this->_classStart = $class['scope_opener'];
                $this->_classEnd = $class['scope_closer'];
            }
        }
        
        return $this->_classStart > 0;
    }

    private function checkClassEnd($token) {
        if(
            $this->_classStart > 0 &&
            $token['code'] == T_CLOSE_CURLY_BRACKET &&
            $token['scope_closer'] == $this->_classEnd
        ) {
            // Reset class state
            $this->_classStart = 0;
            $this->_classEnd = 0;

            return true;
        }

        return false;
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
        $this->tokens = $tokens = $file->getTokens();

        $current = $tokens[$stackPtr];
        $type = $current['code'];

        if(!$this->checkClassStart($current, $stackPtr))
            return;

        if($this->checkClassEnd($current))
            return;

        if($type == T_CLASS) {
            if(!$file->getClassProperties($stackPtr)['is_final']) {
                $msg = 'A DCI Context class must be final.';
                $file->addError($msg, $stackPtr, 'ContextNotFinal');
            }
        }        
    }
}
