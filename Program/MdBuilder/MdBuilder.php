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
    public function __construct()
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
     * @param \UT_Php_Core\IO\Common\IPhpFile $file
     * @throws \Exception
    */
    private function parseFile(\UT_Php_Core\IO\Common\IPhpFile $file)
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
            $this -> data[$ns][] = $this -> parseInterface($file);
        } elseif ($isClass) {
            $this -> data[$ns][] = $this -> parseClass($file);
        } elseif ($isTrait) {
            $this -> data[$ns][] = $this -> parseTrait($file);
        }
    }

    /**
     * @param \UT_Php_Core\IO\Common\IPhpFile $file
     * @return string
     */
    private function parseTrait(\UT_Php_Core\IO\Common\IPhpFile $file): string
    {
        $stream = $file -> object() -> declaration() . self::EOL;
        $stream .= '{' . self::EOL;

        $members = '';
        foreach (
            (new \UT_Php_Core\Collections\Linq($file -> members()))
                -> orderBy(function (\UT_Php_Core\IO\Common\Php\TokenMember $x) {
                    return $x -> declaration();
                }) -> toArray() as $member
        ) {
            $members .= self::TAB . $member -> declaration() . ';' . self::EOL;
        }

        $methods = '';
        foreach (
            (new \UT_Php_Core\Collections\Linq($file -> methods()))
                -> orderBy(function (\UT_Php_Core\IO\Common\Php\TokenMethod $x) {
                    return $x -> declaration();
                }) -> toArray() as $method
        ) {
            $methods .= self::TAB . $method -> declaration() . ';' . self::EOL;
        }

        $stream .= $members;
        if ($members !== '' && $methods !== '') {
            $stream .= self::EOL;
        }
        $stream .= $methods;


        $stream .= '}' . self::EOL;

        return $stream;
    }

    /**
     * @param \UT_Php_Core\IO\Common\IPhpFile $file
     * @return string
     */
    private function parseClass(\UT_Php_Core\IO\Common\IPhpFile $file): string
    {
        $stream = $file -> object() -> declaration() . self::EOL;
        $stream .= '{' . self::EOL;

        $traits = '';
        foreach (
            (new \UT_Php_Core\Collections\Linq($file -> traits()))
                -> orderBy(function (\UT_Php_Core\IO\Common\Php\TokenTrait $x) {
                    return $x -> declaration();
                }) -> toArray() as $trait
        ) {
            $traits .= self::TAB . $trait -> declaration() . ';' . self::EOL;
        }

        $members = '';
        foreach (
            (new \UT_Php_Core\Collections\Linq($file -> members()))
                -> orderBy(function (\UT_Php_Core\IO\Common\Php\TokenMember $x) {
                    return $x -> declaration();
                }) -> toArray() as $member
        ) {
            if (!$member -> isPrivate()) {
                $members .= self::TAB . $member -> declaration() . ';' . self::EOL;
            }
        }

        $methods = '';
        foreach (
            (new \UT_Php_Core\Collections\Linq($file -> methods()))
                -> orderBy(function (\UT_Php_Core\IO\Common\Php\TokenMethod $x) {
                    return $x -> declaration();
                }) -> toArray() as $method
        ) {
            if (!$method -> isPrivate()) {
                $methods .= self::TAB . $method -> declaration() . ';' . self::EOL;
            }
        }

        $stream .= $traits;
        if ($traits !== '' && $members !== '') {
            $stream .= self::EOL;
        }
        $stream .= $members;
        if (($members !== '' && $methods !== '') || ($traits !== '' && $methods !== '')) {
            $stream .= self::EOL;
        }
        $stream .= $methods;
        $stream .= '}' . self::EOL;

        return $stream;
    }

    /**
     * @param \UT_Php_Core\IO\Common\IPhpFile $file
     * @return string
     */
    private function parseInterface(\UT_Php_Core\IO\Common\IPhpFile $file): string
    {
        $stream = $file -> object() -> declaration() . self::EOL;
        $stream .= '{' . self::EOL;

        foreach (
            (new \UT_Php_Core\Collections\Linq($file -> methods()))
                -> where(function (\UT_Php_Core\IO\Common\Php\TokenMethod $x) {
                    return !$x -> isPrivate();
                })
                -> orderBy(function (\UT_Php_Core\IO\Common\Php\TokenMethod $x) {
                    return $x -> declaration();
                }) -> toArray() as $method
        ) {
            $stream .= self::TAB . $method -> declaration() . ';' . self::EOL;
        }

        $stream .= '}' . self::EOL;

        return $stream;
    }

    /**
     * @param \UT_Php_Core\IO\Common\IPhpFile $file
     * @return string
     */
    private function parseEnum(\UT_Php_Core\IO\Common\IPhpFile $file): string
    {
        $stream = $file -> object() -> declaration() . self::EOL;
        $stream .= '{' . self::EOL;

        foreach (
            (new \UT_Php_Core\Collections\Linq($file -> cases()))
            -> orderBy(function (\UT_Php_Core\IO\Common\Php\TokenCase $x) {
                return $x -> declaration();
            }) -> toArray() as $case
        ) {
            $stream .= self::TAB . $case -> declaration() . ';' . self::EOL;
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
