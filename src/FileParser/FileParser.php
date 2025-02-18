<?php

namespace TiMMiT\PhpDocBlockChecker\FileParser;

use PhpParser\Parser;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Param;
use PhpParser\Comment\Doc;
use PhpParser\NodeAbstract;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\Class_;
use TiMMiT\PhpDocBlockChecker\FileInfo;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use TiMMiT\PhpDocBlockChecker\DocblockParser\ReturnTag;
use TiMMiT\PhpDocBlockChecker\DocblockParser\DocblockParser;
use TiMMiT\PhpDocBlockChecker\DocblockParser\DescriptionTag;

/**
 * Uses Nikic/PhpParser to parse PHP files and find relevant information for the checker.
 *
 * @package PhpDocBlockChecker
 */
class FileParser
{
    /**
     * @var DocblockParser
     */
    private $docblockParser;
    /**
     * @var Parser
     */
    private $parser;

    /**
     * Load and parse a PHP file.
     *
     * @param Parser         $parser
     * @param DocblockParser $docblockParser
     */
    public function __construct(Parser $parser, DocblockParser $docblockParser)
    {
        $this->parser         = $parser;
        $this->docblockParser = $docblockParser;
    }

    /**
     * @param string $file
     *
     * @return FileInfo
     */
    public function parseFile($file)
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read file "%s"', $file));
        }
        $stmts = $this->parser->parse($contents);

        if ($stmts === null) {
            return new FileInfo($file, [], [], filemtime($file));
        }

        $result = $this->processStatements($file, $stmts);

        return new FileInfo(
            $file,
            $result['classes'],
            $result['methods'],
            filemtime($file)
        );
    }

    /**
     * Looks for class definitions, and then within them method definitions, docblocks, etc.
     *
     * @param string $file
     * @param array  $statements
     * @param string $prefix
     *
     * @return mixed
     */
    protected function processStatements($file, array $statements, $prefix = '')
    {
        $uses    = [];
        $methods = [];
        $classes = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                return $this->processStatements($file, $statement->stmts, (string)$statement->name);
            }

            if ($statement instanceof Use_) {
                foreach ($statement->uses as $use) {
                    // polyfill
                    $alias = $use->alias;
                    if (null === $alias && method_exists($use, 'getAlias')) {
                        $alias = $use->getAlias();
                    }

                    $uses[(string)$alias] = (string)$use->name;
                }
            }

            if ($statement instanceof Class_) {
                $class         = $statement;
                $fullClassName = $prefix . '\\' . $class->name;

                $docblock = $this->getDocblock($class, $uses);

                $classes[$fullClassName] = [
                    'file'     => $file,
                    'line'     => $class->getAttribute('startLine'),
                    'name'     => $fullClassName,
                    'docblock' => $docblock,
                ];

                foreach ($statement->stmts as $method) {
                    if (!$method instanceof ClassMethod) {
                        continue;
                    }
                    $fullMethodName = $fullClassName . '::' . $method->name;

                    $type = $method->returnType;

                    if ($type instanceof NullableType) {
                        $type = $type->type->toString();
                    } elseif ($type instanceof UnionType) {
                        $type = $type->types;
                    } elseif ($type instanceof NodeAbstract) {
                        $type = $type->toString();
                    }

                    if (is_array($type)) {
                        foreach ($type as &$t) {
                            $t = (string)$t;
                            if (isset($uses[$t])) {
                                $t = $uses[$t];
                            }

                            if ($t !== null) {
                                $t = strpos($t, '\\') === 0 ? substr($t, 1) : $t;
                            }
                        }
                    } else {
                        if (isset($uses[$type])) {
                            $type = $uses[$type];
                        }

                        if ($type !== null) {
                            $type = strpos($type, '\\') === 0 ? substr($type, 1) : $type;
                        }
                    }

                    if ($method->returnType instanceof NullableType) {
                        if (!is_array($type)) {
                            $type = [$type];
                        }
                        $type[] = 'null';
                    }

                    if (is_array($type)) {
                        sort($type);
                    }

                    $docblock = $this->getDocblock($method, $uses);

                    $thisMethod = [
                        'file'       => $file,
                        'class'      => $fullClassName,
                        'name'       => $fullMethodName,
                        'line'       => $method->getAttribute('startLine'),
                        'return'     => $type,
                        'params'     => [],
                        'docblock'   => $docblock,
                        'has_return' => isset($method->stmts) ? $this->statementsContainReturn(
                            $method->stmts
                        ) : false,
                    ];

                    /** @var Param $param */
                    foreach ($method->params as $param) {
                        $type = $param->type;

                        if ($type instanceof NullableType) {
                            $type = $type->type->toString();
                        } elseif ($type instanceof UnionType) {
                            $type = $type->types;
                        } elseif ($type instanceof NodeAbstract) {
                            if (is_callable([$type, 'toString'])) {
                                $type = $type->toString();
                            }
                        }

                        if (is_array($type)) {
                            foreach ($type as &$t) {
                                $t = (string)$t;
                                if (isset($uses[$t])) {
                                    $t = $uses[$t];
                                }

                                if ($t !== null) {
                                    $t = strpos($t, '\\') === 0 ? substr($t, 1) : $t;
                                }
                            }
                            $type = implode('|', $type);
                        } else {
                            if (isset($uses[$type])) {
                                $type = $uses[$type];
                            }

                            if ($type !== null) {
                                $type = strpos($type, '\\') === 0 ? substr($type, 1) : $type;
                            }
                        }

                        if (
                            property_exists($param, 'default') &&
                            $param->default instanceof Expr &&
                            property_exists($param->default, 'name') &&
                            property_exists($param->default->name, 'parts') &&
                            $type !== null &&
                            'null' === $param->default->name->parts[0]
                        ) {
                            $type .= '|null';
                        }

                        if ($param->type instanceof NullableType) {
                            $type .= '|null';
                        }

                        $name = null;
                        // parser v3
                        if (property_exists($param, 'name')) {
                            $name = $param->name;
                        }
                        // parser v4
                        if (null === $name && property_exists($param, 'var') && property_exists($param->var, 'name')) {
                            $name = $param->var->name;
                        }

                        $thisMethod['params']['$' . $name] = $type;
                    }

                    $methods[$fullMethodName] = $thisMethod;
                }
            }
        }

        return ['methods' => $methods, 'classes' => $classes];
    }

    /**
     * Recursively search an array of statements for a return statement.
     *
     * @param array $statements
     *
     * @return bool
     */
    protected function statementsContainReturn(array $statements)
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Return_) {
                return true;
            }

            if (empty($statement->stmts)) {
                continue;
            }

            if ($this->statementsContainReturn($statement->stmts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find and parse a docblock for a given class or method.
     *
     * @param Stmt  $stmt
     * @param array $uses
     *
     * @return array|null
     */
    protected function getDocblock(Stmt $stmt, array $uses = [])
    {
        $comments = $stmt->getAttribute('comments');

        if (is_array($comments)) {
            foreach ($comments as $comment) {
                if ($comment instanceof Doc) {
                    return $this->processDocblock($comment->getText(), $uses);
                }
            }
        }

        return null;
    }

    /**
     * @param string $text
     * @param array  $uses
     *
     * @return array
     */
    protected function processDocblock($text, array $uses = [])
    {
        $tagCollection = $this->docblockParser->parseComment($text);

        if ($tagCollection->hasTag('inheritdoc')) {
            return ['inherit' => true];
        }

        $rtn = ['params' => [], 'return' => null, 'comment' => null];

        if ($tagCollection->hasTag('param')) {
            foreach ($tagCollection->getParamTags() as $paramTag) {
                $types = [];
                foreach (explode('|', $paramTag->getType()) as $tmpType) {
                    if (isset($uses[$tmpType])) {
                        $tmpType = $uses[$tmpType];
                    }

                    $types[] = strpos($tmpType, '\\') === 0 ? substr($tmpType, 1) : $tmpType;
                }

                $rtn['params'][$paramTag->getVar()] = implode('|', $types);
            }
        }

        if ($tagCollection->hasTag('return')) {
            $return = $tagCollection->getReturnTags();
            $return = array_shift($return);

            if ($return instanceof ReturnTag) {
                $types = [];
                /** @var string $tmpType */
                foreach (explode('|', $return->getType()) as $tmpType) {
                    if (isset($uses[$tmpType])) {
                        $tmpType = $uses[$tmpType];
                    }

                    $types[] = strpos($tmpType, '\\') === 0 ? substr($tmpType, 1) : $tmpType;
                }
                $rtn['return'] = implode('|', $types);
            }
        }

        if ($tagCollection->hasTag(DocblockParser::DESCRIPTION_TAG_LINE)) {
            $return = $tagCollection->getDescriptionTags();

            $comment = [];

            foreach ($return as $tag) {
                if ($tag instanceof DescriptionTag) {
                    $comment[] = $tag->getValue();
                }
            }

            $rtn['comment'] = \implode("\n", $comment);
        }

        return $rtn;
    }
}
