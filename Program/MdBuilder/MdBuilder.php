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
        $this -> mdFile = \UT_Php_Core\IO\File::fromDirectory($dir -> parent(), $dir -> name().'.md');
        if($this -> mdFile -> exists())
        {
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
        if(!$fh)
        {
            return;
        }
        
        foreach($this -> data as $ns => $entries)
        {
            fwrite($fh, '## '.$ns.self::EOL);
            foreach($entries as $entry)
            {
                fwrite($fh, '```php'.self::EOL);
                fwrite($fh, $entry.self::EOL);
                fwrite($fh, '```'.self::EOL);
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
        foreach($directory -> list() as $entry)
        {
            if (preg_match('/^\./', $entry -> name())) 
            {
                continue;
            }
            
            if($entry instanceof \UT_Php_Core\IO\Directory)
            {
                $this -> itterate($entry);
            }
            else if($entry instanceof \UT_Php_Core\IO\File && $entry -> extension() === 'php')
            {
                $this -> parseFile($entry -> asPhp());
            }
            else
            {
                throw new \UT_Php_Core\Exceptions\NotImplementedException('Undefined file "'.$entry -> path().'"');
            }
        }
    }
    
    /**
     * @param \UT_Php_Core\Interfaces\IPhpFile $file
     * @throws \Exception
     */
    private function parseFile(\UT_Php_Core\Interfaces\IPhpFile $file)
    {
        $tokens = $file -> tokens();
        
        $ns = $file -> namespace() -> name();
        if(!isset($this -> data[$ns]))
        {
            $this -> data[$ns] = [];
        }
        
        $isClass = $this -> isClass($tokens);
        $isInterface = $this -> isInterface($tokens);
        $isEnum = $this -> isEnum($tokens);
        
        if(!$isClass && !$isInterface && !$isEnum)
        {
            throw new \Exception('Unknown content in "'.$file -> path().'"');
        }
        
        if($isEnum)
        {
            $this -> data[$ns][] = $this -> parseEnum($tokens);
        }
        else if($isInterface)
        {
            $this -> data[$ns][] = $this -> parseInterface($tokens);
        }
        else if($isClass)
        {
            $this -> data[$ns][] = $this -> parseClass($tokens);
        }
        else
        {
            var_dump($file -> name());
            var_dump($isClass);
            var_dump($isInterface);
            echo self::EOL;
        }
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
        
        foreach($tokens as $idx => $token)
        {
            if(is_array($token) && $token[0] === 369)
            {
                $ir = $idx - 1;
                while(isset($tokens[$ir]) && $tokens[$ir] !== ';')
                {
                    if(is_array($tokens[$ir]) && $tokens[$ir][0] === 397 && $tokens[$ir][1] !== ' ')
                    {
                        $declaration .= ' ';
                    }
                    else if(is_array($tokens[$ir]))
                    {
                        $declaration .= $tokens[$ir][1];
                    }
                    else
                    {
                        $declaration .= $tokens[$ir];
                    }
                    $ir--;
                }
                
                $i = $idx;
                while(isset($tokens[$i]) && $tokens[$i] !== '{')
                {
                    if(is_array($tokens[$i]))
                    {
                        $declaration .= $tokens[$i][1];
                    }
                    else
                    {
                        $declaration .= $tokens[$i];
                    }
                    $i++;
                }
                $inClass = true;
            }
            else if(is_array($token) && $inClass && in_array($token[0], [362, 361]))
            {
                $method = '';
                
                $ir = $idx - 1;
                while(isset($tokens[$ir]) && !in_array($tokens[$ir], ['{', '}', ';']))
                {
                    if(is_array($tokens[$ir]) && $tokens[$ir][0] === 393) { }
                    else if(is_array($tokens[$ir]) && $tokens[$ir][0] === 397 && $tokens[$ir][1] !== ' ')
                    {
                        $method .= ' ';
                    }
                    else if(is_array($tokens[$ir]))
                    {
                        $method .= $tokens[$ir][1];
                    }
                    else
                    {
                        $method .= $tokens[$ir];
                    }
                    $ir--;
                }
                
                $i = $idx;
                while(isset($tokens[$i]) && !in_array($tokens[$i], ['{', ';']))
                {
                    if(is_array($tokens[$i]) && $tokens[$i][0] === 397 && $tokens[$i][1] !== ' ')
                    {
                        $method .= '';
                    }
                    else if(is_array($tokens[$i]))
                    {
                        $method .= $tokens[$i][1];
                    }
                    else
                    {
                        $method .= $tokens[$i];
                    }
                    $i++;
                }
                $methods[] = str_replace([',', ',  '], [', ', ', '], $method);
            }
        }
        
        $stream = trim($declaration).self::EOL;
        $stream .= '{'.self::EOL;
        foreach($methods as $method)
        {
            $stream .= self::TAB.trim($method).self::EOL;
        }
        $stream .= '}'.self::EOL;
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
        
        foreach($tokens as $idx => $token)
        {
            if(is_array($token) && $token[0] === 371)
            {
                $i = $idx;
                while(isset($tokens[$i]) && $tokens[$i] !== '{')
                {
                    if(is_array($tokens[$i]))
                    {
                        $declaration .= $tokens[$i][1];
                    }
                    else
                    {
                        $declaration .= $tokens[$i];
                    }
                    $i++;
                }
                $inInterface = true;
            }
            else if(is_array($token) && $inInterface && in_array($token[0], [362, 361]))
            {
                $method = '';
                
                $i = $idx;
                while(isset($tokens[$i]) && $tokens[$i] !== ';')
                {
                    if(is_array($tokens[$i]) && $tokens[$i][0] === 397 && $tokens[$i][1] !== ' ')
                    {
                        $method .= '';
                    }
                    else if(is_array($tokens[$i]))
                    {
                        $method .= $tokens[$i][1];
                    }
                    else
                    {
                        $method .= $tokens[$i];
                    }
                    $i++;
                }
                $methods[] = str_replace([',', ',  '], [', ', ', '], $method);
            }
        }
        
        $stream = $declaration;
        $stream .= '{'.self::EOL;
        foreach($methods as $method)
        {
            $stream .= self::TAB.$method.self::EOL;
        }
        $stream .= '}'.self::EOL;
        
        return $stream;
    }
    
    /**
     * @param array $tokens
     * @return string
     */
    private function parseEnum(array $tokens): string
    {
        $list = [];
        $enumName = null;
        $inEnum = false;
        $inEnumBody = false;
        foreach($tokens as $idx => $token)
        {
            if(is_array($token) && $token[0] === 372)
            {
                $inEnum = true;
            }
            else if($inEnum && !$inEnumBody && is_array($token) && $token[0] === 313)
            {
                $enumName = $token[1];
            }
            else if($inEnum && !$inEnumBody && $token === '{')
            {
                $inEnumBody = true;
            }
            else if($inEnumBody && is_array($token) && $token[0] === 341)
            {
                $i = $idx;
                while($tokens[$i][0] !== 313)
                {
                    $i++;
                }
                $list[] = $tokens[$i][1];
            }
        }
        
        $stream = 'enum '.$enumName.self::EOL;
        $stream .= '{'.self::EOL;
        foreach($list as $entry)
        {
            $stream .= self::TAB.'case '.$entry.self::EOL;
        }
        $stream .= '}'.self::EOL;
        return $stream;
    }
    
    /**
     * @param array $tokens
     * @return bool
     */
    private function isEnum(array $tokens): bool
    {
        foreach($tokens as $token)
        {
            if(is_array($token) && $token[0] === 372)
            {
                return true;
            }
        }
        return false;
    }
    
    /**
     * @param array $tokens
     * @return bool
     */
    private function isInterface(array $tokens): bool
    {
        foreach($tokens as $token)
        {
            if(is_array($token) && $token[0] === 371)
            {
                return true;
            }
        }
        return false;
    }
    
    /**
     * @param array $tokens
     * @return bool
     */
    private function isClass(array $tokens): bool
    {
        foreach($tokens as $token)
        {
            if(is_array($token) && $token[0] === 369)
            {
                return true;
            }
        }
        return false;
    }

    /**
     * @return void
     */
    private function initializeMdFile(): void
    {
        $stream = '# '.$this -> resources -> name().self::EOL;
        $stream .= 'Version '.$this -> version.self::EOL;
        
        $this -> mdFile -> write($stream);
    }
    
    /**
     * @return \UT_Php_Core\Version
     */
    private function getVersion(): \UT_Php_Core\Version
    {
        $versionFile = \UT_Php_Core\IO\File::fromDirectory($this -> resources, '.version');
        if(!$versionFile -> exists())
        {
            return \UT_Php_Core\Version::parse('0.0.0.-1');
        }
        return \UT_Php_Core\Version::parse($versionFile -> read());
    }
}