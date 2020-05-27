<?php

declare(strict_types=1);

namespace Cloudstek\SCIM\FilterParser;

use Cloudstek\SCIM\FilterParser\Exception\InvalidValuePathException;
use Cloudstek\SCIM\FilterParser\Exception\TokenizerException;

/**
 * Abstract parser.
 */
abstract class AbstractParser
{
    protected const T_NUMBER = 10;
    protected const T_STRING = 11;
    protected const T_BOOL = 12;
    protected const T_NULL = 13;

    protected const T_PAREN_OPEN = 21;
    protected const T_PAREN_CLOSE = 22;
    protected const T_BRACKET_OPEN = 23;
    protected const T_BRACKET_CLOSE = 24;

    protected const T_NEGATION = 30;
    protected const T_LOG_OP = 31;
    protected const T_COMP_OP = 32;

    protected const T_NAME = 40;
    protected const T_SUBATTR = 41;

    protected Tokenizer\Tokenizer $tokenizer;

    protected ParserMode $mode;

    /**
     * Abstract parser.
     *
     * @param ParserMode $mode Parser mode.
     */
    public function __construct(ParserMode $mode)
    {
        $patterns = [
            self::T_NUMBER => '\d+(?:\.\d+)?',
            self::T_STRING => '\"[^\"]*\"',
            self::T_BOOL => 'true|false',
            self::T_NULL => 'null',
            self::T_PAREN_OPEN => '\(',
            self::T_PAREN_CLOSE => '\)',
            self::T_BRACKET_OPEN => '\[',
            self::T_BRACKET_CLOSE => '\]',
            self::T_NEGATION => 'not\s+',
            self::T_LOG_OP => '\s+(?:and|or)\s+',
            self::T_COMP_OP => '\s(?:(?:eq|ne|co|sw|ew|gt|lt|ge|le)\s+|pr)',
            self::T_NAME => '(?:(?:[^\"]+\:)+)?[\-\_a-z0-9]+(?:\.[\-\_a-z0-9]+)?',
        ];

        if ($mode === ParserMode::PATH()) {
            $patterns[self::T_SUBATTR] = '\.[\-\_a-z0-9]+$';
        }

        $this->tokenizer = new Tokenizer\Tokenizer(
            $patterns,
            'i'
        );

        $this->mode = $mode;
    }

    /**
     * Parse inner.
     *
     * @param Tokenizer\Stream $stream
     * @param bool             $inValuePath
     *
     * @throws TokenizerException|\Nette\Tokenizer\Exception
     *
     * @return AST\Node|null
     */
    protected function parseInner(Tokenizer\Stream $stream, bool $inValuePath = false): ?AST\Node
    {
        $node = null;

        if ($stream->isNext(self::T_PAREN_OPEN)) {
            // Parentheses
            $node = $this->parseParentheses($stream);

            if ($node === null) {
                return null;
            }
        } elseif ($stream->isNext(self::T_NEGATION)) {
            // Negation
            $node = $this->parseNegation($stream, $inValuePath);

            if ($node === null) {
                return null;
            }
        } elseif ($stream->isNext(self::T_NAME)) {
            // Comparison or value path
            $node = $this->parseAttributePath($stream, $inValuePath);
        }

        if ($node !== null) {
            // Logical connective
            if ($stream->isNext(self::T_LOG_OP)) {
                return $this->parseConnective($stream, $node, $inValuePath);
            }

            return $node;
        }

        if ($inValuePath === true) {
            throw new InvalidValuePathException();
        }

        throw new TokenizerException(
            sprintf(
                'Expected an attribute/value path, opening parenthesis or a negation, got "%s".',
                $stream->nextValue()
            ),
            $stream
        );
    }

    /**
     * Parse filter in parentheses.
     *
     * @param Tokenizer\Stream $stream
     *
     * @throws \Nette\Tokenizer\Exception
     *
     * @return AST\Node|null
     */
    protected function parseParentheses(Tokenizer\Stream $stream): ?AST\Node
    {
        $stream->matchNext(self::T_PAREN_OPEN);
        $filter = $stream->joinUntil(self::T_PAREN_CLOSE);
        $stream->matchNext(self::T_PAREN_CLOSE);

        if (empty($filter)) {
            return null;
        }

        return $this->parse($filter);
    }

    /**
     * Parse negation.
     *
     * @param Tokenizer\Stream $stream
     * @param bool             $inValuePath
     *
     * @throws TokenizerException|\Nette\Tokenizer\Exception
     *
     * @return AST\Node|null
     */
    protected function parseNegation(Tokenizer\Stream $stream, bool $inValuePath = false): ?AST\Node
    {
        $stream->matchNext(self::T_NEGATION);
        $stream->matchNext(self::T_PAREN_OPEN);
        $filter = $stream->joinUntil(self::T_PAREN_CLOSE);
        $stream->matchNext(self::T_PAREN_CLOSE);

        if (empty($filter)) {
            return null;
        }

        $node = $this->parseInner($this->tokenizer->tokenize($filter), $inValuePath);

        return new AST\Negation($node);
    }

    /**
     * Parse attribute path.
     *
     * @param Tokenizer\Stream $stream
     * @param bool             $inValuePath
     *
     * @throws TokenizerException|\Nette\Tokenizer\Exception
     *
     * @return AST\Node
     */
    protected function parseAttributePath(Tokenizer\Stream $stream, bool $inValuePath = false): AST\Node
    {
        $name = $stream->matchNext(self::T_NAME)->value;

        // Attribute scheme
        $scheme = null;

        if (strpos($name, ':') !== false) {
            $lastColonPos = strrpos($name, ':');
            $scheme = substr($name, 0, $lastColonPos);
            $name = substr($name, $lastColonPos + 1);
        }

        // Attribute path
        $attributePath = new AST\AttributePath($scheme, explode('.', $name));

        // Value path (only if not in value path already
        if ($stream->isNext(self::T_BRACKET_OPEN)) {
            if ($inValuePath === true || count($attributePath) !== 1) {
                throw new InvalidValuePathException($stream);
            }

            return $this->parseValuePath($stream, $attributePath);
        }

        // Comparison
        return $this->parseComparison($stream, $attributePath);
    }

    /**
     * Parse value path.
     *
     * @param Tokenizer\Stream  $stream
     * @param AST\AttributePath $attributePath
     *
     * @throws TokenizerException|\Nette\Tokenizer\Exception
     *
     * @return AST\ValuePath
     */
    protected function parseValuePath(Tokenizer\Stream $stream, AST\AttributePath $attributePath): AST\ValuePath
    {
        // Parse node between brackets.
        $stream->matchNext(self::T_BRACKET_OPEN);

        $node = $this->parseInner($stream, true);

        if (
            $node instanceof AST\Connective === false
            && $node instanceof AST\Comparison === false
            && $node instanceof AST\Negation === false
        ) {
            throw new InvalidValuePathException($stream);
        }

        $stream->matchNext(self::T_BRACKET_CLOSE);

        // Correct attribute path for node.
        $this->updateValuePathAttributePath($attributePath, $node);

        return new AST\ValuePath($attributePath, $node);
    }

    /**
     * Parse comparison.
     *
     * @param Tokenizer\Stream  $stream
     * @param AST\AttributePath $attributePath
     *
     * @throws \Nette\Tokenizer\Exception
     *
     * @return AST\Connective|AST\Comparison
     */
    protected function parseComparison(
        Tokenizer\Stream $stream,
        AST\AttributePath $attributePath
    ) {
        $operator = trim($stream->matchNext(self::T_COMP_OP)->value);
        $value = null;

        if (strcasecmp($operator, 'pr') <> 0) {
            $value = $stream->matchNext(self::T_STRING, self::T_NUMBER, self::T_BOOL, self::T_NULL);

            switch ($value->type) {
                case self::T_STRING:
                    $value = trim($value->value, '"');
                    break;
                case self::T_NUMBER:
                    if (strpos($value->value, '.') !== false) {
                        $value = floatval($value->value);
                        break;
                    }

                    $value = intval($value->value);
                    break;
                case self::T_BOOL:
                    $value = strcasecmp($value->value, 'true') === 0;
                    break;
                default:
                    $value = null;
                    break;
            }
        }

        return new AST\Comparison($attributePath, $operator, $value);
    }

    /**
     * Parse logical connective (and/or).
     *
     * @param Tokenizer\Stream $stream
     * @param AST\Node         $leftNode
     * @param bool             $inValuePath
     *
     * @throws TokenizerException|\Nette\Tokenizer\Exception
     *
     * @return AST\Connective
     */
    protected function parseConnective(
        Tokenizer\Stream $stream,
        AST\Node $leftNode,
        bool $inValuePath = false
    ): AST\Connective {
        $logOp = trim($stream->matchNext(self::T_LOG_OP)->value);

        $isConjunction = strcasecmp($logOp, 'and') === 0;

        // Parse right hand node
        $rightNode = $this->parseInner($stream, $inValuePath);

        // Connective nodes
        $nodes = [$leftNode, $rightNode];

        // Merge consecutive connectives of the same type into one connective.
        if (
            ($rightNode instanceof AST\Conjunction && $isConjunction === true)
            || ($rightNode instanceof AST\Disjunction && $isConjunction === false)
        ) {
            $rightNodes = $rightNode->getNodes();

            $nodes = array_merge([$leftNode], $rightNodes);
        }

        return $isConjunction === true
            ? new AST\Conjunction($nodes)
            : new AST\Disjunction($nodes);
    }

    /**
     * Prepend attribute path to node.
     *
     * @param AST\AttributePath $attributePath
     * @param AST\Node          $node
     *
     * @throws TokenizerException
     *
     * @return AST\Node
     */
    protected function updateValuePathAttributePath(AST\AttributePath $attributePath, AST\Node $node): AST\Node
    {
        if ($node instanceof AST\Comparison) {
            $names = array_merge($attributePath->getNames(), $node->getAttributePath()->getNames());

            $node->setAttributePath(new AST\AttributePath($attributePath->getSchema(), $names));

            return $node;
        }

        if ($node instanceof AST\Negation) {
            $node->setNode($this->updateValuePathAttributePath($attributePath, $node->getNode()));

            return $node;
        }

        if ($node instanceof AST\Connective) {
            foreach ($node->getNodes() as $subNode) {
                $this->updateValuePathAttributePath($attributePath, $subNode);
            }

            return $node;
        }

        throw new InvalidValuePathFilterException();
    }
}
