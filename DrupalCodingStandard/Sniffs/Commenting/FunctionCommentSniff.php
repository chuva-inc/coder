<?php
/**
 * Parses and verifies the doc comments for functions.
 *
 * PHP version 5
 *
 * @category  PHP
 * @package   PHP_CodeSniffer
 * @author    Klaus Purer
 * @link      http://pear.php.net/package/PHP_CodeSniffer
 */

/**
 * Parses and verifies the doc comments for functions. Largely copied from
 * PEAR_Sniffs_Commenting_FunctionCommentSniff.
 *
 * @category  PHP
 * @package   PHP_CodeSniffer
 * @author    Klaus Purer
 * @link      http://pear.php.net/package/PHP_CodeSniffer
 */
class DrupalCodingStandard_Sniffs_Commenting_FunctionCommentSniff implements PHP_CodeSniffer_Sniff
{

    /**
     * The name of the method that we are currently processing.
     *
     * @var string
     */
    private $_methodName = '';

    /**
     * The position in the stack where the fucntion token was found.
     *
     * @var int
     */
    private $_functionToken = null;

    /**
     * The position in the stack where the class token was found.
     *
     * @var int
     */
    private $_classToken = null;

    /**
     * The function comment parser for the current method.
     *
     * @var PHP_CodeSniffer_Comment_Parser_FunctionCommentParser
     */
    protected $commentParser = null;

    /**
     * The current PHP_CodeSniffer_File object we are processing.
     *
     * @var PHP_CodeSniffer_File
     */
    protected $currentFile = null;


    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
        return array(T_FUNCTION);

    }//end register()


    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param PHP_CodeSniffer_File $phpcsFile The file being scanned.
     * @param int                  $stackPtr  The position of the current token
     *                                        in the stack passed in $tokens.
     *
     * @return void
     */
    public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr)
    {
        $find = array(
                 T_COMMENT,
                 T_DOC_COMMENT,
                 T_CLASS,
                 T_FUNCTION,
                 T_OPEN_TAG,
                );

        $commentEnd = $phpcsFile->findPrevious($find, ($stackPtr - 1));

        if ($commentEnd === false) {
            return;
        }

        $this->currentFile = $phpcsFile;
        $tokens            = $phpcsFile->getTokens();

        // If the token that we found was a class or a function, then this
        // function has no doc comment.
        $code = $tokens[$commentEnd]['code'];

        if ($code === T_COMMENT) {
            $error = 'You must use "/**" style comments for a function comment';
            $phpcsFile->addError($error, $stackPtr, 'WrongStyle');
            return;
        } else if ($code !== T_DOC_COMMENT) {
            $phpcsFile->addError('Missing function doc comment', $stackPtr, 'Missing');
            return;
        }

        // If there is any code between the function keyword and the doc block
        // then the doc block is not for us.
        $ignore    = PHP_CodeSniffer_Tokens::$scopeModifiers;
        $ignore[]  = T_STATIC;
        $ignore[]  = T_WHITESPACE;
        $ignore[]  = T_ABSTRACT;
        $ignore[]  = T_FINAL;
        $prevToken = $phpcsFile->findPrevious($ignore, ($stackPtr - 1), null, true);
        if ($prevToken !== $commentEnd) {
            $phpcsFile->addError('Missing function doc comment', $stackPtr, 'Missing');
            return;
        }

        $this->_functionToken = $stackPtr;

        $this->_classToken = null;
        foreach ($tokens[$stackPtr]['conditions'] as $condPtr => $condition) {
            if ($condition === T_CLASS || $condition === T_INTERFACE) {
                $this->_classToken = $condPtr;
                break;
            }
        }

        // If the first T_OPEN_TAG is right before the comment, it is probably
        // a file comment.
        $commentStart = ($phpcsFile->findPrevious(T_DOC_COMMENT, ($commentEnd - 1), null, true) + 1);
        $prevToken    = $phpcsFile->findPrevious(T_WHITESPACE, ($commentStart - 1), null, true);
        if ($tokens[$prevToken]['code'] === T_OPEN_TAG) {
            // Is this the first open tag?
            if ($stackPtr === 0 || $phpcsFile->findPrevious(T_OPEN_TAG, ($prevToken - 1)) === false) {
                $phpcsFile->addError('Missing function doc comment', $stackPtr, 'Missing');
                return;
            }
        }

        $comment           = $phpcsFile->getTokensAsString($commentStart, ($commentEnd - $commentStart + 1));
        $this->_methodName = $phpcsFile->getDeclarationName($stackPtr);

        try {
            $this->commentParser = new DrupalCodingStandard_CommentParser_FunctionCommentParser($comment, $phpcsFile);
            $this->commentParser->parse();
        } catch (PHP_CodeSniffer_CommentParser_ParserException $e) {
            $line = ($e->getLineWithinComment() + $commentStart);
            $phpcsFile->addError($e->getMessage(), $line, 'FailedParse');
            return;
        }

        $comment = $this->commentParser->getComment();
        if (is_null($comment) === true) {
            $error = 'Function doc comment is empty';
            $phpcsFile->addError($error, $commentStart, 'Empty');
            return;
        }

        $this->processParams($commentStart);
        $this->processReturn($commentStart, $commentEnd);
        $this->processThrows($commentStart);
        $this->processSees();

        // Check if hook implementation doc is formated correctly.
        if (preg_match('/((I|i)mplement[^\n]+?hook_[^\n]+)/', $comment->getShortComment(), $matches)) {
            $formattingIssue = 0;
            if(!strstr($matches[0], 'Implements ')){
                $formattingIssue++;
            }
            if(!preg_match('/ hook_[\S\(\)]+\.$/', $matches[0])){
                $formattingIssue++;
            }
            if($formattingIssue){
                $phpcsFile->addWarning('Format should be * Implements hook_foo().', $commentStart + 1);
            }
        }

        // No extra newline before short description.
        $short        = $comment->getShortComment();
        $newlineCount = 0;
        $newlineSpan  = strspn($short, $phpcsFile->eolChar);
        if ($short !== '' && $newlineSpan > 0) {
            $line  = ($newlineSpan > 1) ? 'newlines' : 'newline';
            $error = "Extra $line found before function comment short description";
            $phpcsFile->addError($error, ($commentStart + 1));
        }

        $newlineCount = (substr_count($short, $phpcsFile->eolChar) + 1);

        // Exactly one blank line between short and long description.
        $long = $comment->getLongComment();
        if (empty($long) === false) {
            $between        = $comment->getWhiteSpaceBetween();
            $newlineBetween = substr_count($between, $phpcsFile->eolChar);
            if ($newlineBetween !== 2) {
                $error = 'There must be exactly one blank line between descriptions in function comment';
                $phpcsFile->addError($error, ($commentStart + $newlineCount + 1), 'SpacingAfterShort');
            }

            $newlineCount += $newlineBetween;
        }

    }//end process()


    /**
     * Process any throw tags that this function comment has.
     *
     * @param int $commentStart The position in the stack where the
     *                          comment started.
     *
     * @return void
     */
    protected function processThrows($commentStart)
    {
        if (count($this->commentParser->getThrows()) === 0) {
            return;
        }

        foreach ($this->commentParser->getThrows() as $throw) {

            $exception = $throw->getValue();
            $errorPos  = ($commentStart + $throw->getLine());

            if ($exception === '') {
                $error = '@throws tag must contain the exception class name';
                $this->currentFile->addError($error, $errorPos, 'EmptyThrows');
            }
        }

    }//end processThrows()


    /**
     * Process the return comment of this function comment.
     *
     * @param int $commentStart The position in the stack where the comment started.
     * @param int $commentEnd   The position in the stack where the comment ended.
     *
     * @return void
     */
    protected function processReturn($commentStart, $commentEnd)
    {
        // Skip constructor and destructor.
        $className = '';
        if ($this->_classToken !== null) {
            $className = $this->currentFile->getDeclarationName($this->_classToken);
            $className = strtolower(ltrim($className, '_'));
        }

        $methodName      = strtolower(ltrim($this->_methodName, '_'));
        $isSpecialMethod = ($this->_methodName === '__construct' || $this->_methodName === '__destruct');

        if ($isSpecialMethod === false && $methodName !== $className) {
            $return = $this->commentParser->getReturn();
            if ($return !== null) {
                $errorPos = ($commentStart + $this->commentParser->getReturn()->getLine());
                if (trim($return->getRawContent()) === '') {
                    $error = '@return tag is empty in function comment';
                    $this->currentFile->addError($error, $errorPos, 'EmptyReturn');
                    return;
                }

                $comment = $return->getComment();
                $commentWhitespace = $return->getWhitespaceBeforeComment();
                if (substr_count($return->getWhitespaceBeforeValue(), $this->currentFile->eolChar) > 0) {
                    $error = 'Data type of return value is missing';
                    $this->currentFile->addError($error, $errorPos, 'MissingReturnType');
                    // Treat the value as part of the comment.
                    $comment = $return->getValue().' '.$comment;
                    $commentWhitespace = $return->getWhitespaceBeforeValue();
                } else if ($return->getWhitespaceBeforeValue() !== ' ') {
                    $error = 'Expected 1 space before return type';
                    $this->currentFile->addError($error, $errorPos, 'SpacingBeforeReturnType');
                }

                if (trim($comment) === '') {
                    $error = 'Missing comment for @return statement';
                    $this->currentFile->addError($error, $errorPos, 'MissingReturnComment');
                } else if (substr_count($commentWhitespace, $this->currentFile->eolChar) !== 1) {
                    $error = 'Return comment must be on the next line';
                    $this->currentFile->addError($error, $errorPos, 'ReturnCommentNewLine');
                } else if (substr_count($commentWhitespace, ' ') !== 3) {
                    $error = 'Return comment indentation must be 2 additional spaces';
                    $this->currentFile->addError($error, $errorPos + 1, 'ParamCommentIndentation');
                }
            }
        }

    }//end processReturn()


    /**
     * Process the function parameter comments.
     *
     * @param int $commentStart The position in the stack where
     *                          the comment started.
     *
     * @return void
     */
    protected function processParams($commentStart)
    {
        $realParams = $this->currentFile->getMethodParameters($this->_functionToken);

        $params      = $this->commentParser->getParams();
        $foundParams = array();

        if (empty($params) === false) {
            // There must be an empty line before the parameter block.
            if (substr_count($params[0]->getWhitespaceBefore(), $this->currentFile->eolChar) < 2) {
                $error    = 'There must be an empty line before the parameter block';
                $errorPos = ($params[0]->getLine() + $commentStart);
                $this->currentFile->addError($error, $errorPos, 'SpacingBeforeParams');
            }

            $lastParm = (count($params) - 1);
            if (substr_count($params[$lastParm]->getWhitespaceAfter(), $this->currentFile->eolChar) !== 2) {
                $error    = 'Last parameter comment requires a blank newline after it';
                $errorPos = ($params[$lastParm]->getLine() + $commentStart);
                $this->currentFile->addError($error, $errorPos, 'SpacingAfterParams');
            }

            $previousParam      = null;
            $spaceBeforeVar     = 10000;
            $spaceBeforeComment = 10000;
            $longestType        = 0;
            $longestVar         = 0;

            foreach ($params as $param) {
                $paramComment = trim($param->getComment());
                $errorPos     = ($param->getLine() + $commentStart);

                // Make sure that there is only one space before the var type.
                if ($param->getWhitespaceBeforeType() !== ' ') {
                    $error = 'Expected 1 space before variable type';
                    $this->currentFile->addError($error, $errorPos, 'SpacingBeforeParamType');
                }

                $spaceCount = substr_count($param->getWhitespaceBeforeVarName(), ' ');
                if ($spaceCount < $spaceBeforeVar) {
                    $spaceBeforeVar = $spaceCount;
                    $longestType    = $errorPos;
                }

                $spaceCount = substr_count($param->getWhitespaceBeforeComment(), ' ');

                if ($spaceCount < $spaceBeforeComment && $paramComment !== '') {
                    $spaceBeforeComment = $spaceCount;
                    $longestVar         = $errorPos;
                }

                // Make sure they are in the correct order,
                // and have the correct name.
                $pos = $param->getPosition();

                $paramName = '[ UNKNOWN ]';
                if ($param->getVarName() !== '') {
                    $paramName = $param->getVarName();
                }

                // Make sure the names of the parameter comment matches the
                // actual parameter.
                if (isset($realParams[($pos - 1)]) === true) {
                    $realName          = $realParams[($pos - 1)]['name'];
                    $expectedParamName = $realName;
                    $foundParams[]     = $realName;
                    $isReference       = $realParams[($pos - 1)]['pass_by_reference'];
                    $code              = 'ParamNameNoMatch';

                    // Append ampersand to name if passing by reference.
                    if ($isReference === true) {
                        $realName = '&'.$realName;
                    }

                    $code = 'ParamNameNoMatch';
                    if ($isReference === true && substr($paramName, 0, 1) === '&') {
                        // This warning is disabled until a decision has been reached
                        // whether this is an error or not.
                        // @see http://drupal.org/node/1366842
                        /*$warning = 'Doc comment for var %s at position %s should not contain the & for referenced variables.';
                        $this->currentFile->addWarning(
                            $warning,
                            $errorPos,
                            $code,
                            array(
                             $paramName,
                             $pos,
                            )
                        );*/
                    } else {
                        if ($expectedParamName !== $paramName) {
                            $data  = array(
                                      $paramName,
                                      $realName,
                                      $pos,
                                     );
                            $error = 'Doc comment for var %s does not match ';
                            if (strtolower($paramName) === strtolower($expectedParamName)) {
                                $error .= 'case of ';
                                $code   = 'ParamNameNoCaseMatch';
                            }

                            $error .= 'actual variable name %s at position %s';

                            $this->currentFile->addError($error, $errorPos, $code, $data);
                        }//end if
                    }//end if
                } else if ($paramName !== '...') {
                    // We must have an extra parameter comment.
                    $error = 'Superfluous doc comment at position '.$pos;
                    $this->currentFile->addError($error, $errorPos, 'ExtraParamComment');
                }//end if

                if ($param->getVarName() === '') {
                    $error = 'Missing parameter name at position '.$pos;
                     $this->currentFile->addError($error, $errorPos, 'MissingParamName');
                }

                if ($param->getType() === '') {
                    $error = 'Missing parameter type at position '.$pos;
                    $this->currentFile->addError($error, $errorPos, 'MissingParamType');
                }

                if ($paramComment === '') {
                    $error = 'Missing comment for param "%s" at position %s';
                    $data  = array(
                              $paramName,
                              $pos,
                             );
                    $this->currentFile->addError($error, $errorPos, 'MissingParamComment', $data);
                } else if (substr_count($param->getWhitespaceBeforeComment(), $this->currentFile->eolChar) !== 1) {
                    $error = 'Parameter comment must be on the next line at position '.$pos;
                    $this->currentFile->addError($error, $errorPos, 'ParamCommentNewLine');
                } else if (substr_count($param->getWhitespaceBeforeComment(), ' ') !== 3) {
                    $error = 'Parameter comment indentation must be 2 additional spaces at position '.$pos;
                    $this->currentFile->addError($error, ($errorPos + 1), 'ParamCommentIndentation');
                }

                $previousParam = $param;
            }//end foreach
        }//end if

        $realNames = array();
        foreach ($realParams as $realParam) {
            $realNames[] = $realParam['name'];
        }

    }//end processParams()


    /**
     * Process the function "see" comments.
     *
     * @return void
     */
    protected function processSees()
    {
        $sees = $this->commentParser->getSees();
        foreach ($sees as $see) {
            $errorPos = $see->getLine();
            if ($see->getWhitespaceBeforeContent() !== ' ') {
                $error = 'Expected 1 space before see reference';
                $this->currentFile->addError($error, $errorPos, 'SpacingBeforeSee');
            }

            $comment = trim($see->getContent());
            if (preg_match('/[\.!\?]$/', $comment) === 1) {
                $error = 'Trailing punctuation for @see references is not allowed.';
                $this->currentFile->addError($error, $errorPos, 'SeePunctuation');
            }
        }

    }//end processSees()


}//end class

?>
