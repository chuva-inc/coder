<?php
/**
 * DrupalCodingStandard_Sniffs_Formatting_SpaceUnaryOperatorSniff.
 *
 * PHP version 5
 *
 * @category  PHP
 * @package   PHP_CodeSniffer
 * @author    Peter Philipp <peter.philipp@cando-image.com>
 * @link      http://pear.php.net/package/PHP_CodeSniffer
 */

/**
 * DrupalCodingStandard_Sniffs_Formatting_SpaceUnaryOperatorSniff.
 *
 * Ensures there are no spaces on increment / decrement statements.
 *
 * @category  PHP
 * @package   PHP_CodeSniffer
 * @author    Peter Philipp <peter.philipp@cando-image.com>
 * @version   Release: 1.2.2
 * @link      http://pear.php.net/package/PHP_CodeSniffer
 */
class DrupalCodingStandard_Sniffs_Formatting_SpaceUnaryOperatorSniff implements PHP_CodeSniffer_Sniff
{


    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
         return array(T_DEC, T_INC, T_MINUS, T_PLUS);

    }//end register()


    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param PHP_CodeSniffer_File $phpcsFile The file being scanned.
     * @param int                  $stackPtr  The position of the current token in
     *                                        the stack passed in $tokens.
     *
     * @return void
     */
    public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // Check decrement / increment.
        if ($tokens[$stackPtr]['code'] == T_DEC || $tokens[$stackPtr]['code'] == T_INC) {
          $modifyLeft = substr($tokens[($stackPtr - 1)]['content'], 0, 1) == '$' ||
                        $tokens[($stackPtr + 1)]['content'] == ';';

          if ($modifyLeft && $tokens[($stackPtr - 1)]['code'] === T_WHITESPACE) {
            $error = 'There must not be a single space befora a unary opeator statement';
            $phpcsFile->addError($error, $stackPtr);
          }

          if (!$modifyLeft && substr($tokens[($stackPtr + 1)]['content'], 0, 1) != '$') {
            $error = 'An unary opeator statement must not followed by a single space';
            $phpcsFile->addError($error, $stackPtr);
          }
        }

        $has_equality_token = in_array($tokens[$stackPtr - 2]['code'], PHP_CodeSniffer_Tokens::$equalityTokens);
        // Check plus / minus value assignments or comparisons.
        if ($tokens[$stackPtr]['code'] == T_MINUS || $tokens[$stackPtr]['code'] == T_PLUS) {
          if ($tokens[($stackPtr - 1)]['code'] === T_WHITESPACE
            && ($tokens[($stackPtr - 2)]['code'] === T_EQUAL
                || $tokens[($stackPtr - 2)]['code'] === T_DOUBLE_ARROW
                || $has_equality_token)
            && $tokens[($stackPtr + 1)]['code'] === T_WHITESPACE
          ) {
            $error = 'An unary opeator statement must not followed by a single space';
            $phpcsFile->addError($error, $stackPtr);
          }
        }

    }//end process()


}//end class

?>
