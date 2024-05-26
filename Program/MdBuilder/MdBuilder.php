<?php

namespace Program\MdBuilder;

class MdBuilder
{
    private const EOL = "\r\n";
    private const TAB = "\t";

    /**
     * @var \UT_Php_Core\IO\Directory
     */
    private \UT_Php_Core\IO\Directory $resources;

    /**
     * @var \UT_Php_Core\Version
     */
    private \UT_Php_Core\Version $version;

    /**
     * @var \UT_Php_Core\IO\File
     */
    private \UT_Php_Core\IO\File $mdFile;

    /**
     * @var array
     */
    private array $data = [];

    /**
     * @global array $argv
     * @throws \UT_Php_Core\Exceptions\ArgumentException
     */
    function __construct()
    {
        global $argv;

        $name = $argv[1];
        $dir = \UT_Php_Core\IO\Directory::fromString('../' . $name);

        if (!$dir -> exists()) {
            throw new \UT_Php_Core\Exceptions\ArgumentException('Name of Library "' . $name . '" is not found on disk');
        }

        $this -> resources = $dir;
        $this -> version = $this -> getVersion();
        $this -> mdFile = \UT_Php_Core\IO\File::fromDirectory($dir -> parent(), $dir -> name() . '.md');
        if ($this -> mdFile -> exists()) {
            $this -> mdFile -> remove();
        }

        $this -> initializeMdFile();
        $this -> itterate($this -> resources);
        $this -> writeMdContent();
    }

    /**
     * @return void
     */
    private function writeMdContent(): void
    {
        ksort($this -> data);

        $fh = fopen($this -> mdFile -> path(), 'a+');
        if (!$fh) {
            return;
        }

        foreach ($this -> data as $ns => $entries) {
            fwrite($fh, '## ' . $ns . self::EOL);
            foreach ($entries as $entry) {
                fwrite($fh, '```php' . self::EOL);
                fwrite($fh, $entry . self::EOL);
                fwrite($fh, '```' . self::EOL);
            }
        }

        fclose($fh);
    }

    /**
     * @param \UT_Php_Core\IO\Directory $directory
     * @return void
     */
    private function itterate(\UT_Php_Core\IO\Directory $directory): void
    {
        foreach ($directory -> list() as $entry) {
            if (preg_match('/^\./', $entry -> name())) {
                continue;
            }

            if ($entry instanceof \UT_Php_Core\IO\Directory) {
                $this -> itterate($entry);
            } elseif ($entry instanceof \UT_Php_Core\IO\File && $entry -> extension() === 'php') {
                $this -> parseFile($entry -> asPhp());
            } else {
                throw new \UT_Php_Core\Exceptions\NotImplementedException('Undefined file "' . $entry -> path() . '"');
            }
        }
    }

    /**
     * @param \UT_Php_Core\Interfaces\IPhpFile $file
     * @throws \Exception
    */
    private function parseFile(\UT_Php_Core\Interfaces\IPhpFile $file)
    {
//        $tokens = $file -> tokens();

        $ns = $file -> namespace() -> name();
        if (!isset($this -> data[$ns])) {
            $this -> data[$ns] = [];
        }

        $object = $file -> object();
        $isClass = $object -> isClass();
        $isInterface = $object -> isInterface();
        $isEnum = $object -> isEnum();
        $isTrait = $object -> isTrait();

        if (!$isClass && !$isInterface && !$isEnum && !$isTrait) {
            throw new \Exception('Unknown content in "' . $file -> path() . '"');
        }

        if ($isEnum) {
            $this -> data[$ns][] = $this -> parseEnum($file);
        } elseif ($isInterface) {
//            print_r($file);
//            $this -> data[$ns][] = $this -> parseInterface($tokens);
        } elseif ($isClass) {
//            print_r($file);
//            $this -> data[$ns][] = $this -> parseClass($tokens);
        } elseif ($isTrait) {
//            print_r($file);
//            $this -> data[$ns][] = $this -> parseTrait($tokens);
        }
    }

    /**
     * @param array $tokens
     * @return string
     */
    private function parseTrait(array $tokens): string
    {
        $declaration = '';
        $inTrait = false;
        $methods = [];

        foreach ($tokens as $idx => $token) {
            if (is_array($token) && $token[0] === 370) {
                $ir = $idx - 1;
                while (isset($tokens[$ir]) && $tokens[$ir] !== ';') {
                    if (is_array($tokens[$ir]) && $tokens[$ir][0] === 397 && $tokens[$ir][1] !== ' ') {
                        $declaration .= ' ';
                    } elseif (is_array($tokens[$ir])) {
                        $declaration .= $tokens[$ir][1];
                    } else {
                        $declaration .= $tokens[$ir];
                    }
                    $ir--;
                }

                $i = $idx;
                while (isset($tokens[$i]) && $tokens[$i] !== '{') {
                    if (is_array($tokens[$i])) {
                        $declaration .= $tokens[$i][1];
                    } else {
                        $declaration .= $tokens[$i];
                    }
                    $i++;
                }
                $inTrait = true;
            } elseif (is_array($token) && $inTrait && in_array($token[0], [362, 361])) {
                $method = '';

                $ir = $idx - 1;
                while (isset($tokens[$ir]) && !in_array($tokens[$ir], ['{', '}', ';'])) {
                    if (is_array($tokens[$ir]) && $tokens[$ir][0] === 393) {
                    } elseif (is_array($tokens[$ir]) && $tokens[$ir][0] === 397 && $tokens[$ir][1] !== ' ') {
                        $method .= ' ';
                    } elseif (is_array($tokens[$ir])) {
                        $method .= $tokens[$ir][1];
                    } else {
                        $method .= $tokens[$ir];
                    }
                    $ir--;
                }

                $i = $idx;
                while (isset($tokens[$i]) && !in_array($tokens[$i], ['{', ';'])) {
                    if (is_array($tokens[$i]) && $tokens[$i][0] === 397 && $tokens[$i][1] !== ' ') {
                        $method .= '';
                    } elseif (is_array($tokens[$i])) {
                        $method .= $tokens[$i][1];
                    } else {
                        $method .= $tokens[$i];
                    }
                    $i++;
                }
                $methods[] = str_replace([',', ',  '], [', ', ', '], $method);
            }
        }

        $stream = trim($declaration) . self::EOL;
        $stream .= '{' . self::EOL;
        foreach ($methods as $method) {
            $stream .= self::TAB . trim($method) . self::EOL;
        }
        $stream .= '}' . self::EOL;
        return str_replace('  ', ' ', $stream);
    }

    /**
     * @param array $tokens
     * @return string
     */
    private function parseClass(array $tokens): string
    {
        $declaration = '';
        $inClass = false;
        $methods = [];

        foreach ($tokens as $idx => $token) {
            if (is_array($token) && $token[0] === 369) {
                $ir = $idx - 1;
                while (isset($tokens[$ir]) && $tokens[$ir] !== ';') {
                    if (is_array($tokens[$ir]) && $tokens[$ir][0] === 397 && $tokens[$ir][1] !== ' ') {
                        $declaration .= ' ';
                    } elseif (is_array($tokens[$ir])) {
                        $declaration .= $tokens[$ir][1];
                    } else {
                        $declaration .= $tokens[$ir];
                    }
                    $ir--;
                }

                $i = $idx;
                while (isset($tokens[$i]) && $tokens[$i] !== '{') {
                    if (is_array($tokens[$i])) {
                        $declaration .= $tokens[$i][1];
                    } else {
                        $declaration .= $tokens[$i];
                    }
                    $i++;
                }
                $inClass = true;
            } elseif (is_array($token) && $inClass && $token[0] === 354) {
                $i = $idx;
                $trait = '';
                while (isset($tokens[$i]) && $tokens[$i] !== ';') {
                    if (is_array($tokens[$i]) && $tokens[$i][0] === 393) {
                    } elseif (is_array($tokens[$i]) && $tokens[$i][0] === 397 && $tokens[$i][1] !== ' ') {
                        $trait .= ' ';
                    } elseif (is_array($tokens[$i])) {
                        $trait .= $tokens[$i][1];
                    } else {
                        $trait .= $tokens[$i];
                    }
                    $i++;
                }
                $methods[] = $trait;
            } elseif (is_array($token) && $inClass && in_array($token[0], [362, 361])) {
                $method = '';

                $ir = $idx - 1;
                while (isset($tokens[$ir]) && !in_array($tokens[$ir], ['{', '}', ';'])) {
                    if (is_array($tokens[$ir]) && $tokens[$ir][0] === 393) {
                    } elseif (is_array($tokens[$ir]) && $tokens[$ir][0] === 397 && $tokens[$ir][1] !== ' ') {
                        $method .= ' ';
                    } elseif (is_array($tokens[$ir])) {
                        $method .= $tokens[$ir][1];
                    } else {
                        $method .= $tokens[$ir];
                    }
                    $ir--;
                }

                $i = $idx;
                while (isset($tokens[$i]) && !in_array($tokens[$i], ['{', ';'])) {
                    if (is_array($tokens[$i]) && $tokens[$i][0] === 397 && $tokens[$i][1] !== ' ') {
                        $method .= '';
                    } elseif (is_array($tokens[$i])) {
                        $method .= $tokens[$i][1];
                    } else {
                        $method .= $tokens[$i];
                    }
                    $i++;
                }
                $methods[] = str_replace([',', ',  '], [', ', ', '], $method);
            }
        }

        $stream = trim($declaration) . self::EOL;
        $stream .= '{' . self::EOL;
        foreach ($methods as $method) {
            $stream .= self::TAB . trim($method) . self::EOL;
        }
        $stream .= '}' . self::EOL;
        return str_replace('  ', ' ', $stream);
    }

    /**
     * @param array $tokens
     * @return string
     */
    private function parseInterface(array $tokens): string
    {
        $declaration = '';
        $inInterface = false;
        $methods = [];

        foreach ($tokens as $idx => $token) {
            if (is_array($token) && $token[0] === 371) {
                $i = $idx;
                while (isset($tokens[$i]) && $tokens[$i] !== '{') {
                    if (is_array($tokens[$i])) {
                        $declaration .= $tokens[$i][1];
                    } else {
                        $declaration .= $tokens[$i];
                    }
                    $i++;
                }
                $inInterface = true;
            } elseif (is_array($token) && $inInterface && in_array($token[0], [362, 361])) {
                $method = '';

                $i = $idx;
                while (isset($tokens[$i]) && $tokens[$i] !== ';') {
                    if (is_array($tokens[$i]) && $tokens[$i][0] === 397 && $tokens[$i][1] !== ' ') {
                        $method .= '';
                    } elseif (is_array($tokens[$i])) {
                        $method .= $tokens[$i][1];
                    } else {
                        $method .= $tokens[$i];
                    }
                    $i++;
                }
                $methods[] = str_replace([',', ',  '], [', ', ', '], $method);
            }
        }

        $stream = $declaration;
        $stream .= '{' . self::EOL;
        foreach ($methods as $method) {
            $stream .= self::TAB . $method . self::EOL;
        }
        $stream .= '}' . self::EOL;

        return $stream;
    }

    /**
     * @param \UT_Php_Core\Interfaces\IPhpFile $file
     * @return string
     */
    private function parseEnum(\UT_Php_Core\Interfaces\IPhpFile $file): string
    {
        $stream = $file -> object() -> declaration() . self::EOL;
        $stream .= '{' . self::EOL;
        foreach ($file -> cases() as $case) {
            $stream .= self::TAB . $case -> declaration() . self::EOL;
        }
        $stream .= '}' . self::EOL;

        return $stream;
    }

    /**
     * @return void
     */
    private function initializeMdFile(): void
    {
        $stream = '# ' . $this -> resources -> name() . self::EOL;
        $stream .= 'Version ' . $this -> version . self::EOL;

        $this -> mdFile -> write($stream);
    }

    /**
     * @return \UT_Php_Core\Version
     */
    private function getVersion(): \UT_Php_Core\Version
    {
        $versionFile = \UT_Php_Core\IO\File::fromDirectory($this -> resources, '.version');
        if (!$versionFile -> exists()) {
            return \UT_Php_Core\Version::parse('0.0.0.-1');
        }
        return \UT_Php_Core\Version::parse($versionFile -> read());
    }
}
