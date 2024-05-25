<?php

namespace Program;

class Compiler
{
    private const DEFAULT_VERSION = '1.0.0.0';

    /**
     * @var \UT_Php_Core\IO\Directory
     */
    private \UT_Php_Core\IO\Directory $source;

    /**
     * @var \UT_Php_Core\IO\Directory
     */
    private \UT_Php_Core\IO\Directory $work;

    /**
     * @var string
     */
    private string $name;

    /**
     * @var array
     */
    private array $namespaces;

    /**
     * @var int
     */
    private int $address;

    /**
     * @global array $argv
     * @throws \UT_Php_Core\Exceptions\ArgumentException
     */
    public function __construct()
    {
        global $argv;

        $name = $argv[1];
        $dir = \UT_Php_Core\IO\Directory::fromString('../' . $name);

        if (!$dir -> exists()) {
            throw new \UT_Php_Core\Exceptions\ArgumentException('Name of Library "' . $name . '" is not found on disk');
        }
        $version = null;
        $versionFile = \UT_Php_Core\IO\File::fromDirectory($dir, '.version');
        if ($versionFile -> exists()) {
            $version = \UT_Php_Core\Version::parse($versionFile -> content());
            $version -> increment();
        } else {
            $versionFile -> write(self::DEFAULT_VERSION);
            $version = \UT_Php_Core\Version::parse($versionFile -> content());
        }

        $this -> name = $dir -> name();

        $this -> source = $dir;
        $this -> work = \UT_Php_Core\IO\Directory::fromString('Work');
        if (!$this -> work -> exists()) {
            $this -> work -> create();
        }

        $this -> namespaces = [];
        $this -> address = 0;
        $this -> itterate($this -> source);
        $output = $this -> namespaceComposer($version);

        echo 'Data written to "' . $output -> path() . '"' . "\r\n";
        $versionFile -> write($version);
    }

    /**
     * @param int $value
     * @return string
     */
    private function encodeInt(int $value, int $len = null): string
    {
        $bin = decbin($value);
        $padding = ceil(strlen($bin) / 8) * 8;
        if ($padding > strlen($bin)) {
            $bin = str_pad($bin, $padding, '0', 0);
        }

        $bytes = [];
        for ($i = 0; $i < strlen($bin); $i += 8) {
            $bytes[] = chr(bindec(substr($bin, $i, 8)));
        }

        if ($len !== null) {
            $padding = array_fill(0, $len - count($bytes), chr(0));
            $bytes = array_merge($padding, $bytes);
        }

        return implode('', $bytes);
    }

    /**
     * @param UT_Php_Core\Version $version
     * @return type
     */
    private function encodeVersion(\UT_Php_Core\Version $version)
    {
        $v1 = $this -> encodeInt($version -> major());
        $v2 = $this -> encodeInt($version -> minor());
        $v3 = $this -> encodeInt($version -> patch());
        $v4 = $this -> encodeInt($version -> build());

        $padLen = (int)max([strlen($v1), strlen($v2), strlen($v3), strlen($v4)]);
        if ($padLen > 1) {
            $v1 = str_pad($v1, $padLen, chr(0), 0);
            $v2 = str_pad($v1, $padLen, chr(0), 0);
            $v3 = str_pad($v1, $padLen, chr(0), 0);
            $v4 = str_pad($v1, $padLen, chr(0), 0);
        }

        return $this -> encodeInt($padLen) . $v1 . $v2 . $v3 . $v4;
    }

    /**
     * @param string|null $namespace
     * @return void
     */
    private function namespaceComposer(?\UT_Php_Core\Version $version, ?string $namespace = null): \UT_Php_Core\IO\File
    {
        if ($namespace === null) {
            $map = [
                'Namespaces' => [],
                'Stream' => ''
            ];

            foreach (array_keys($this -> namespaces) as $ns) {
                $file = $this -> namespaceComposer(null, $ns);
                $bin = $file -> content();

                $map['Namespaces'][$ns] = strlen($bin);
                $map['Stream'] .= $bin;
            }

            $namespaces = gzencode(json_encode($map['Namespaces']));

            $output = \UT_Php_Core\IO\File::fromDirectory($this -> source -> parent(), $this -> name . '.pll');
            $output -> write(
                $this -> encodeVersion($version) .
                $this -> encodeInt(strlen($namespaces), 4) .
                $namespaces .
                $map['Stream']
            );

            return $output;
        }

        $ordering = $this -> namespaceOrdering($namespace);

        $stream = '<?php ';
        $stream .= 'namespace ' . $namespace . ';';

        $ns = $this -> namespaces[$namespace];
        foreach ($ordering as $cls) {
            $stream .= $ns[$cls]['Stream'];
        }

        $file = \UT_Php_Core\IO\File::fromDirectory($this -> work, str_replace('\\', '+', $namespace) . '.gz');
        $file -> write(gzencode($stream));

        return $file;
    }

    /**
     * @param string $namespace
     * @return array
     */
    private function namespaceOrdering(string $namespace): array
    {
        $ns = $this -> namespaces[$namespace];

        $requirements = [];
        $nullRequirements = [];
        foreach ($ns as $class => $data) {
            if (count($data['Requires']) > 0) {
                $requirements[$class] = $data['Requires'];
            } else {
                $nullRequirements[] = $class;
            }
        }
        if (count($requirements) === 0) {
            return $nullRequirements;
        }

        $allInNullRequirements = true;
        foreach ($requirements as $req) {
            foreach ($req as $cls) {
                if (!in_array($cls, $nullRequirements)) {
                    $allInNullRequirements = false;
                }
            }
        }

        if ($allInNullRequirements) {
            foreach (array_keys($requirements) as $class) {
                $nullRequirements[] = $class;
            }

            return $nullRequirements;
        }

        $dependants = [];
        foreach ($requirements as $class => $req) {
            foreach ($req as $cls) {
                if (!isset($dependants[$cls])) {
                    $dependants[$cls] = [];
                }
                $dependants[$cls][] = $class;
            }
        }

        $positions = [];
        $sum = -1;
        $offset = 0;

        while ($sum < array_sum($positions)) {
            $sum = array_sum($positions);
            foreach (array_keys($dependants) as $c1) {
                if (isset($positions[$c1]) && $positions[$c1] < $offset) {
                    continue;
                }

                if (!isset($positions[$c1])) {
                    $positions[$c1] = 0;
                }

                foreach ($dependants as $c2 => $d2) {
                    if ($c1 === $c2) {
                        continue;
                    }

                    if (!isset($positions[$c2])) {
                        $positions[$c2] = 0;
                    }

                    if (in_array($c1, $d2)) {
                        $positions[$c1] = $positions[$c2] + 1;
                    }
                }
            }
            $offset++;
        }

        $iPositions = [];
        foreach ($positions as $cls => $value) {
            if (!isset($iPositions[$value])) {
                $iPositions[$value] = [];
            }

            $children = $dependants[$cls];
            $iPositions[$value] = array_merge($iPositions[$value], $children);
        }
        ksort($iPositions);

        foreach ($iPositions as $list) {
            $nullRequirements = array_merge($nullRequirements, $list);
        }

        return $nullRequirements;
    }

    /**
     * @param \UT_Php_Core\IO\Directory $current
     * @return void
     */
    private function itterate(\UT_Php_Core\IO\Directory $current): void
    {
        foreach ($current -> list() as $entry) {
            if (preg_match('/^\./', $entry -> name())) {
                continue;
            }

            if ($entry instanceof \UT_Php_Core\IO\File && $entry -> extension() === 'php') {
                $this -> translateToNamespace($entry);
            } elseif ($entry instanceof \UT_Php_Core\IO\Directory) {
                $this -> itterate($entry);
            } else {
                throw new \UT_Php_Core\Exceptions\UndefinedException(
                    'Found undefined file "' . $entry -> path() . '" in library directory'
                );
            }
        }
    }

    /**
     * @param \UT_Php_Core\IO\File $file
     * @return void
     */
    private function translateToNamespace(\UT_Php_Core\IO\File $file): void
    {
        $stream = $file -> content();
        $tokens = token_get_all($stream);

        $namespace = $this -> getNamespaceFromTokens($tokens);

        if (!isset($this -> namespaces[$namespace])) {
            $this -> namespaces[$namespace] = [];
        }

        $this -> namespaces[$namespace][$file -> basename()] = [
            'Requires' => $this -> getNamespaceRequirements($tokens),
            'Stream' => $this -> obfusicate($tokens)
        ];
    }

    /**
     * @param array $tokens
     * @return array
     */
    private function getNamespaceRequirements(array $tokens): array
    {
        $buffer = [];
        $hasMatch = false;

        foreach ($tokens as $idx => $token) {
            if (($token[0] === 369 || $token[0] === 371) && !$hasMatch) {
                $hasMatch = true;

                $extends = null;
                $interfaces = [];

                $s = $idx + 3;
                while ($tokens[$idx] !== '{') {
                    $idx++;
                }
                $e = $idx - 1;

                $segment = array_slice($tokens, $s, $e - $s);
                if (count($segment) !== 0) {
                    foreach ($segment as $sIdx => $part) {
                        if (is_array($part) && $part[0] === 373) {
                            while (isset($segment[$sIdx]) && $segment[$sIdx][0] !== 313) {
                                $sIdx++;
                            }

                            if (isset($segment[$sIdx]) && $this -> isSameNamespace($segment[$sIdx][1])) {
                                $extends = $segment[$sIdx][1];
                            }
                        }

                        if (is_array($part) && $part[0] === 374) {
                            while (isset($segment[$sIdx])) {
                                if (
                                    ($segment[$sIdx][0] === 314 || $segment[$sIdx][0] === 313) &&
                                    $this -> isSameNamespace($segment[$sIdx][1])
                                ) {
                                    $interfaces[] = $segment[$sIdx][1];
                                }
                                $sIdx++;
                            }
                        }
                    }

                    if ($extends !== null || count($interfaces) !== 0) {
                        $buffer = $interfaces;
                        if ($extends !== null) {
                            $buffer[] = $extends;
                        }
                    }
                }
            }
        }

        return $buffer;
    }

    /**
     * @param string $object
     * @return bool
     */
    private function isSameNamespace(string $object): bool
    {
        if ($object[0] === '\\') {
            return false;
        }

        return true;
    }

    /**
     * @param array $tokens
     * @return string
     */
    private function obfusicate(array $tokens): string
    {
        $members = $this -> translateMembers($tokens);
        $this -> updateMembers($tokens, $members);

        $constants = $this -> translateConstants($tokens);
        $this -> updateConstants($tokens, $constants);

        $methods = $this -> translateMethods($tokens);
        $this -> updatePrivateMethods($tokens, $methods);

        $this -> updateMethods($tokens, $members);
        $this -> stripInformationAfter($tokens);
        $this -> stripDocComments($tokens);
        $this -> stripDocOpenAndNamespace($tokens);
        $this -> reSpace($tokens);

        return $this -> buildStream($tokens);
    }

    /**
     * @param array $tokens
     * @return array
     */
    private function translateMethods(array $tokens): array
    {
        $buffer = [];

        $inClass = false;

        foreach ($tokens as $idx => $token) {
            if (!is_array($token)) {
            } elseif (!$inClass && $token[0] === 369) {
                $inClass = true;
            } elseif (
                $inClass &&
                $token[0] === 360 &&
                $tokens[$idx + 2][0] === 347 &&
                substr($tokens[$idx + 4][1], 0, 2) !== '__'
            ) {
                $method = $tokens[$idx + 4][1];
                $buffer[$method] = $this -> getNewAddress();
            }
        }

        return $buffer;
    }

    /**
     * @param array $tokens
     * @return array
     */
    private function translateConstants(array &$tokens): array
    {
        $buffer = [];

        $inClass = false;
        $inMethod = false;
        $methodDepth = 0;

        $memberStart = -1;
        foreach ($tokens as $idx => $token) {
            if (!is_array($token)) {
                if ($inMethod && $token === '{') {
                    $methodDepth++;
                } elseif ($inMethod && $token === '}') {
                    $methodDepth--;
                    if ($methodDepth === 0) {
                        $inMethod = false;
                    }
                }
            } elseif (!$inClass && $token[0] === 369) {
                $inClass = true;
            } elseif ($inClass && !$inMethod && $token[0] === 347) {
                $inMethod = true;
            } elseif ($inClass && !$inMethod && $token[0] === 360) {
                $isConstant = false;
                $memberStart = $idx;
                while ($tokens[$idx][0] !== 313) {
                    if ($tokens[$idx][0] === 349) {
                        $isConstant = true;
                    }
                    $idx++;
                }

                if ($isConstant) {
                    $var = $tokens[$idx][1];
                    $address = strtoupper($this -> getNewAddress());
                    $tokens[$idx][1] = $address;

                    $buffer[$var] = $address;
                }
            }
        }

        return $buffer;
    }

    /**
     * @param array $tokens
     * @param array $constants
     * @return void
     */
    private function updateConstants(array &$tokens, array $constants): void
    {
        $remove = [];

        foreach ($tokens as $idx => $token) {
            if (!is_array($token)) {
                continue;
            } elseif ($token[0] == 317) {
                while (is_array($tokens[$idx]) && $tokens[$idx][0] !== 313) {
                    if ($tokens[$idx][0] === 397) {
                        $remove[] = $idx;
                    }
                    $idx++;
                }
                if (is_array($tokens[$idx]) && isset($constants[$tokens[$idx][1]])) {
                    $tokens[$idx][1] = $constants[$tokens[$idx][1]];
                }
            }
        }

        foreach ($remove as $idx) {
            unset($tokens[$idx]);
        }

        $tokens = array_values($tokens);
    }

    /**
     * @param array $tokens
     * @param array $methods
     * @return void
     */
    private function updatePrivateMethods(array &$tokens, array $methods): void
    {
        $remove = [];

        foreach ($tokens as $idx => $token) {
            if (!is_array($token)) {
                continue;
            } elseif ($token[0] == 317) {
                while (is_array($tokens[$idx]) && $tokens[$idx][0] !== 313) {
                    if ($tokens[$idx][0] === 397) {
                        $remove[] = $idx;
                    }
                    $idx++;
                }
                if (is_array($tokens[$idx]) && isset($methods[$tokens[$idx][1]])) {
                    $tokens[$idx][1] = $methods[$tokens[$idx][1]];
                }
            } elseif ($token[0] == 347) {
                while (is_array($tokens[$idx]) && $tokens[$idx][0] !== 313) {
                    if ($tokens[$idx][0] === 397) {
                        $remove[] = $idx;
                    }
                    $idx++;
                }
                if (is_array($tokens[$idx]) && isset($methods[$tokens[$idx][1]])) {
                    $tokens[$idx][1] = $methods[$tokens[$idx][1]];
                }
            }
        }

        foreach ($remove as $idx) {
            unset($tokens[$idx]);
        }

        $tokens = array_values($tokens);
    }

    /**
     * @param array $tokens
     * @return void
     */
    private function stripDocComments(array &$tokens): void
    {
        $list = [360, 361, 362, 358, 359];

        $remove = [];
        foreach ($tokens as $idx => $token) {
            if (
                is_array($token) &&
                in_array($token[0], $list) &&
                $tokens[$idx - 1][0] === 397 &&
                $tokens[$idx - 2][0] === 393
            ) {
                $remove[] = $idx - 1;
                $remove[] = $idx - 2;
            }
        }

        foreach ($remove as $idx) {
            unset($tokens[$idx]);
        }

        $tokens = array_values($tokens);
    }

    /**
     * @param array $tokens
     * @return void
     */
    private function stripDocOpenAndNamespace(array &$tokens): void
    {
        $classStart = (new \UT_Php_Core\Collections\Linq($tokens))
            ->firstOrDefault(function ($x) {
                return is_array($x) && ($x[0] === 369 || $x[0] === 372 || $x[0] === 371 || $x[0] === 358);
            });
        $classStartIndex = array_search($classStart, $tokens);

        $tokens = array_slice($tokens, $classStartIndex);
    }

    /**
     * @param array $tokens
     * @return void
     */
    private function reSpace(array &$tokens): void
    {
        $list = [ 'as', 'function', 'instanceof' ];

        foreach ($tokens as $idx => $token) {
            if (is_array($token) && in_array($token[1], $list)) {
                $tokens[$idx][1] = ' ' . $token[1] . ' ';
            }
        }
    }

    /**
     * @param array $tokens
     * @return void
     */
    private function stripInformationAfter(array &$tokens): void
    {
        $list = [
            '{', '}', '(', ')', ';', '=', '.', ':', ',', '?', '+=', '==', '!==', '===',
            '/', '[', ']', '-', '*', '&&', '+', '->', 'as', '=>', 'function', 'instanceof'
        ];
        $remove = [];

        foreach ($tokens as $idx => $token) {
            if (in_array($token, $list) && is_array($tokens[$idx + 1]) && $tokens[$idx + 1][0] === 397) {
                $remove[] = $idx + 1;
            }

            if (in_array($token, $list) && is_array($tokens[$idx - 1]) && $tokens[$idx - 1][0] === 397) {
                $remove[] = $idx - 1;
            }

            if (
                is_array($token) &&
                in_array($token[1], $list) &&
                is_array($tokens[$idx + 1]) &&
                $tokens[$idx + 1][0] === 397
            ) {
                $remove[] = $idx + 1;
            }

            if (
                is_array($token) &&
                in_array($token[1], $list) &&
                is_array($tokens[$idx - 1]) &&
                $tokens[$idx - 1][0] === 397
            ) {
                $remove[] = $idx - 1;
            }
        }

        foreach ($remove as $idx) {
            unset($tokens[$idx]);
        }

        $tokens = array_values($tokens);
    }

    /**
     * @param array $tokens
     */
    private function updateMethods(array &$tokens, array $members)
    {
        $inMethod = false;
        $inClass = false;
        $methodDepth = 0;
        $inParameters = false;
        $parameters = [];
        $replaced = [];

        foreach ($tokens as $idx => $token) {
            if (!is_array($token)) {
                if ($inMethod && $token === '{') {
                    $methodDepth++;
                } elseif ($inMethod && $token === '}') {
                    $methodDepth--;
                    if ($methodDepth === 0) {
                        $inMethod = false;
                    }
                } elseif ($inMethod && $methodDepth === 0 && $token === '(') {
                    $inParameters = true;
                    $parameters = [];
                    $replaced = [];
                } elseif ($inMethod && $inParameters && $token === ')') {
                    $inParameters = false;
                }
            } elseif (!$inClass && $token[0] === 369) {
                $inClass = true;
            } elseif ($inClass && !$inMethod && $token[0] === 347) {
                $inMethod = true;
            } elseif ($inParameters && $token[0] === 317) {
                $parameters[] = $token[1];
            } elseif (
                $inMethod &&
                $methodDepth >= 1 &&
                $token[0] === 317 &&
                !in_array($token[1], $parameters) &&
                $token[1] !== '$this' &&
                substr($token[1], 0, 2) !== '$_' &&
                !in_array($token[1], $members)
            ) {
                $var = $token[1];
                if (!isset($replaced[$var])) {
                    $replaced[$var] = '$' . $this -> getNewAddress();
                }

                $tokens[$idx][1] = $replaced[$var];
            }
        }
    }

    /**
     * @param array $tokens
     * @param array $members
     * @return void
     */
    private function updateMembers(array &$tokens, array $members): void
    {
        $remove = [];

        foreach ($tokens as $idx => $token) {
            if (!is_array($token)) {
                continue;
            } elseif ($token[0] == 317) {
                $i = $idx;
                while (is_array($tokens[$i]) && $tokens[$i][0] !== 313) {
                    if ($tokens[$idx][0] === 397) {
                        $remove[] = $i;
                    }
                    $i++;
                }
                if (is_array($tokens[$i]) && isset($members[$tokens[$i][1]]) && $tokens[$i + 1] !== '(') {
                    $tokens[$i][1] = $members[$tokens[$i][1]];
                } elseif (is_array($token) && isset($members[$token[1]])) {
                    $tokens[$idx][1] = $members[$token[1]];
                }
            }
        }

        foreach ($remove as $idx) {
            unset($tokens[$idx]);
        }

        $tokens = array_values($tokens);
    }

    /**
     * @param array $tokens
     * @return array
     */
    private function translateMembers(array &$tokens): array
    {
        $buffer = [];

        $inClass = false;
        $inMethod = false;
        $methodDepth = 0;

        $memberStart = -1;
        foreach ($tokens as $idx => $token) {
            if (!is_array($token)) {
                if ($inMethod && $token === '{') {
                    $methodDepth++;
                } elseif ($inMethod && $token === '}') {
                    $methodDepth--;
                    if ($methodDepth === 0) {
                        $inMethod = false;
                    }
                }
            } elseif (!$inClass && $token[0] === 369) {
                $inClass = true;
            } elseif ($inClass && !$inMethod && $token[0] === 347) {
                $inMethod = true;
            } elseif ($inClass && !$inMethod && $token[0] === 360) {
                $isStatic = false;
                $memberStart = $idx;

                while ($tokens[$idx][0] !== 317) {
                    if (is_array($tokens[$idx]) && $tokens[$idx][1] === 'static') {
                        $isStatic = true;
                    } elseif (is_array($tokens[$idx]) && $tokens[$idx][0] === 347) {
                        $inMethod = true;
                        break;
                    }

                    $idx++;
                }

                if ($inMethod) {
                    continue;
                }

                $var = $tokens[$idx][1];
                if (preg_match('/^\$a[0-9a-f]{4}$/i', $var)) {
                    continue;
                }

                $address = $this -> getNewAddress();
                $tokens[$idx][1] = '$' . $address;

                if ($isStatic) {
                    $buffer[$var] = '$' . $address;
                    continue;
                }
                $buffer[substr($var, 1)] = $address;
            }
        }

        return $buffer;
    }

    /**
     * @return string
     */
    private function getNewAddress(): string
    {
        $new = 'a' . str_pad(dechex($this -> address), 4, '0', STR_PAD_LEFT);
        $this -> address++;
        return $new;
    }

    /**
     * @param array $tokens
     * @return string
     */
    private function buildStream(array $tokens): string
    {
        $buffer = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $buffer .= $token[1];
                continue;
            }
            $buffer .= $token;
        }

        return $buffer;
    }

    /**
     * @param array $tokens
     * @return string
     */
    private function getNamespaceFromTokens(array $tokens): string
    {
        $namespaceStart = (new \UT_Php_Core\Collections\Linq($tokens))
            -> firstOrDefault(function ($x) {
                return is_array($x) && $x[1] === 'namespace';
            });
        $namespaceStartIndex = array_search($namespaceStart, $tokens);

        $namespaceEnd = (new \UT_Php_Core\Collections\Linq($tokens))
            -> skip($namespaceStartIndex)
            -> firstOrDefault(function ($x) {
                return $x === ';';
            });
        $namespaceEndIndex = array_search($namespaceEnd, $tokens);

        $namespaceTokens = array_slice($tokens, $namespaceStartIndex, $namespaceEndIndex - $namespaceStartIndex);
        $namespaceBuffer = (new \UT_Php_Core\Collections\Linq($namespaceTokens))
            -> where(function ($x) {
                return is_array($x) && ($x[0] === 313 || $x[0] === 316);
            })
            -> select(function (array $x) {
                return $x[1];
            })
            -> toArray();

        return implode('\\', $namespaceBuffer);
    }
}