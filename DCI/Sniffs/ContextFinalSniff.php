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
            T_CLOSE_CURLY_BRACKET,
            T_DOC_COMMENT_TAG
        ];
    }

    private int $_classStart = 0;
    private int $_classEnd = 0;

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
        
        if($type == T_CLOSE_CURLY_BRACKET) {
            if($this->_classStart > 0 && $current['scope_closer'] == $this->_classEnd) {
                // Class ended
                $this->_classStart = 0;
                $this->_classEnd = 0;
            }
        }
    }
}
