<?php

namespace Program\Compiler;

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
            $version = \UT_Php_Core\Version::parse($versionFile -> read());
            $version -> increment();
        } else {
            $versionFile -> write(self::DEFAULT_VERSION);
            $version = \UT_Php_Core\Version::parse($versionFile -> read());
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
        $this -> cleanup($this -> work);

        echo 'Data written to "' . $output -> path() . '"' . "\r\n";
        $versionFile -> write($version);
    }

    /**
     * @param \UT_Php_Core\IO\Directory $directory
     * @return void
     */
    private function cleanup(\UT_Php_Core\IO\Directory $directory): void
    {
        foreach ($directory -> list() as $entry) {
            if ($entry instanceof \UT_Php_Core\IO\Directory) {
                $this -> cleanup($entry);
            } elseif ($entry instanceof \UT_Php_Core\IO\File) {
                $entry -> remove();
            }
        }
        $directory -> remove();
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
                $bin = $file -> read();

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

        return $this -> removeDuplicatesKeepFirst($nullRequirements);
    }

    /**
     * @param array $input
     * @return array
     */
    private function removeDuplicatesKeepFirst(array $input): array
    {
        $inverse = array_reverse($input);
        $counts = [];
        foreach ($inverse as $value) {
            if (!isset($counts[$value])) {
                $counts[$value] = 0;
            }
            $counts[$value]++;
        }
        $list = (new \UT_Php_Core\Collections\Linq($counts)) -> toArray(function (int $x) {
            return $x > 1;
        }, true);
        if (count($list) === 0) {
            return $input;
        }

        $output = $inverse;
        foreach ($list as $key => $count) {
            for ($i = 0; $i < $count - 1; $i++) {
                $pos = array_search($key, $output);
                unset($output[$pos]);
            }
        }

        return array_reverse(array_values($output));
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
                $this -> translateToNamespace($entry -> asPhp());
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
     * @param \UT_Php_Core\IO\Common\IPhpFile $file
     * @return void
     */
    private function translateToNamespace(\UT_Php_Core\IO\Common\IPhpFile $file): void
    {
        $namespace = $file -> namespace() -> name();
        if (!isset($this -> namespaces[$namespace])) {
            $this -> namespaces[] = [];
        }

        $this -> namespaces[$namespace][$file -> object() -> name()] = [
            'Requires' => $this -> getNamespaceRequirements($file),
            'Stream' => $this -> obfusicate($file)
        ];
    }

    /**
     * @param \UT_Php_Core\IO\Common\IPhpFile $file
     * @return array
     */
    private function getNamespaceRequirements(\UT_Php_Core\IO\Common\IPhpFile $file): array
    {
        $list = array_merge($file -> object() -> implements());
        foreach ($file -> traits() as $trait) {
            $list[] = $trait -> name();
        }
        $extends = $file -> object() -> extends();
        if ($extends !== null) {
            $list[] = $extends;
        }
        $buffer = [];
        foreach ($list as $cls) {
            if (stristr($cls, '\\')) { //Is external NS, skip
                continue;
            }
            $buffer[] = $cls;
        }
        return array_unique($buffer);
    }

    /**
     * @param \UT_Php_Core\IO\Common\IPhpFile $file
     * @return string
     */
    private function obfusicate(\UT_Php_Core\IO\Common\IPhpFile $file): string
    {
        foreach ($file -> members() as $member) {
            if ($member -> isPrivate()) {
                $new = $this -> getNewAddress();
                $old = $member -> name();

                $member -> replace($old, '$' . $new);
                foreach ($file -> methods() as $method) {
                    $key = $old;
                    $value = $new;
                    if ($member -> isStatic()) {
                        $value = '$' . $value;
                    } else {
                        $key = substr($key, 1);
                    }
                    $method -> replace($key, $value, \UT_Php_Core\IO\Common\Php\ReplaceTypes::Member);
                }
            }
        }

        foreach ($file -> constants() as $constant) {
            if ($constant -> isPrivate()) {
                $new = strtoupper($this -> getNewAddress());
                $old = $constant -> name();

                $constant -> replace($old, $new);
                foreach ($file -> methods() as $method) {
                    $method -> replace($old, $new, \UT_Php_Core\IO\Common\Php\ReplaceTypes::Constant);
                }
            }
        }

        foreach ($file -> methods() as $method) {
            foreach ($method -> variables($method -> isPrivate()) as $old) {
                $new = '$' . $this -> getNewAddress();
                $method -> replace($old, $new, \UT_Php_Core\IO\Common\Php\ReplaceTypes::Variable);
            }
            if ($method -> isPrivate()) {
                $old = $method -> name();
                if (preg_match('/^\_\_/i', $old)) {
                    continue;
                }

                $new = $this -> getNewAddress();

                $method -> replace($old, $new, \UT_Php_Core\IO\Common\Php\ReplaceTypes::Declaration);
                foreach ($file -> methods() as $method2) {
                    $method2 -> replace($old, $new, \UT_Php_Core\IO\Common\Php\ReplaceTypes::Method);
                }
            }
            $method -> strip();
        }

        $stream = $file -> compose(true, true);

        return $stream;
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
}
