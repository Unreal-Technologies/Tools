<?php
require_once('../PllLoader.php');
PllLoader::initialize('UT.Php.Core:1.0.0.0');

class Compiler
{
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
        $dir = \UT_Php_Core\IO\Directory::fromString('../'.$name.'/Sources');
        
        if(!$dir -> exists())
        {
            throw new \UT_Php_Core\Exceptions\ArgumentException('Name of Library "'.$name.'" is not found on disk');
        }
        
        $this -> name = $dir -> parent() -> name();

        $this -> source = $dir;
        $this -> work = \UT_Php_Core\IO\Directory::fromString('Work');
        if(!$this -> work -> exists())
        {
            $this -> work -> create();
        }
        
        $this -> namespaces = [];
        $this -> address = 0;
        $this -> itterate($this -> source);
        
        file_put_contents('namespaces.txt', print_r($this -> namespaces, true));
    }
    
    /**
     * @param \UT_Php_Core\IO\Directory $current
     * @return void
     */
    private function itterate(\UT_Php_Core\IO\Directory $current): void
    {
        foreach($current -> list() as $entry)
        {
            if($entry instanceof \UT_Php_Core\IO\File && $entry -> extension() === 'php')
            {
                $this -> translateToNamespace($entry);
            }
            else if($entry instanceof \UT_Php_Core\IO\Directory)
            {
                $this -> itterate($entry);
            }
            else
            {
                var_dumP($entry -> path());
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

        if(!isset($this -> namespaces[$namespace]))
        {
            $this -> namespaces[$namespace] = [];
        }
        
        if($file -> basename() === 'IXmlFile')
        {
            file_put_contents($file -> basename().'.txt', print_r($tokens, true));
        }
        $this -> namespaces[$namespace][$file -> basename()] = [
            'Requires' => $this -> getNamespaceRequirements($tokens),
            'Stream' => $this -> obfusicate($tokens)
        ];
    }
    
    private function getNamespaceRequirements(array $tokens): array
    {
        $buffer = [];
        $hasMatch = false;
        
        foreach($tokens as $idx => $token)
        {
            if(($token[0] === 369 || $token[0] === 371) && !$hasMatch)
            {
                $hasMatch = true;
                
                $extends = null;
                $interfaces = [];
                
                $s = $idx + 3;
                while($tokens[$idx] !== '{')
                {
                    $idx++;
                }
                $e = $idx - 1;
                
                $segment = array_slice($tokens, $s, $e - $s);
                if(count($segment) !== 0)
                {
                    foreach($segment as $sIdx => $part)
                    {
                        if(is_array($part) && $part[0] === 373)
                        {
                            while(isset($segment[$sIdx]) && $segment[$sIdx][0] !== 313)
                            {
                                $sIdx++;
                            }
                            
                            if(isset($segment[$sIdx]) && $this -> isSameNamespace($segment[$sIdx][1]))
                            {
                                $extends = $segment[$sIdx][1];
                            }
                        }
                        
                        if(is_array($part) && $part[0] === 374)
                        {
                            while(isset($segment[$sIdx]))
                            {
                                if(($segment[$sIdx][0] === 314 || $segment[$sIdx][0] === 313) && $this -> isSameNamespace($segment[$sIdx][1]))
                                {
                                    $interfaces[] = $segment[$sIdx][1];
                                }
                                $sIdx++;
                            }
                        }
                    }
                
                    if($extends !== null || count($interfaces) !== 0)
                    {
                        $buffer = $interfaces;
                        if($extends !== null)
                        {
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
        if($object[0] === '\\')
        {
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
        
        $this -> updateMethods($tokens);
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
    private function translateConstants(array &$tokens): array
    {
        $buffer = [];
        
        $inClass = false;
        $inMethod = false;
        $methodDepth = 0;
        
        $memberStart = -1;
        foreach($tokens as $idx => $token)
        {
            if(!is_array($token))
            {
                if($inMethod && $token === '{')
                {
                    $methodDepth++;
                }
                else if($inMethod && $token === '}')
                {
                    $methodDepth--;
                    if($methodDepth === 0)
                    {
                        $inMethod = false;
                    }
                }
            }
            else if(!$inClass && $token[0] === 369)
            {
                $inClass = true;
            }
            else if($inClass && !$inMethod && $token[0] === 347)
            {
                $inMethod = true;
            }
            else if($inClass && !$inMethod && $token[0] === 360)
            {
                $isConstant = false;
                $memberStart = $idx;
                while($tokens[$idx][0] !== 313)
                {
                    if($tokens[$idx][0] === 349)
                    {
                        $isConstant = true;
                    }
                    $idx++;
                }
                
                if($isConstant)
                {
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
        
        foreach($tokens as $idx => $token)
        {
            if(!is_array($token))
            {
                continue;
            }
            else if($token[0] == 317)
            {
                while(is_array($tokens[$idx]) && $tokens[$idx][0] !== 313)
                {
                    if($tokens[$idx][0] === 397)
                    {
                        $remove[] = $idx;
                    }
                    $idx++;
                }
                if(is_array($tokens[$idx]) && isset($constants[$tokens[$idx][1]]))
                {
                    $tokens[$idx][1] = $constants[$tokens[$idx][1]]; 
                }
            }
        }
        
        foreach($remove as $idx)
        {
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
        foreach($tokens as $idx => $token)
        {
            if(is_array($token) && in_array($token[0], $list) && $tokens[$idx - 1][0] === 397 && $tokens[$idx - 2][0] === 393)
            {
                $remove[] = $idx - 1;
                $remove[] = $idx - 2;
            }
        }
        
        foreach($remove as $idx)
        {
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
        $classStart = (new UT_Php_Core\Collections\Linq($tokens))
            ->firstOrDefault(function($x) { return is_array($x) && ($x[0] === 369 || $x[0] === 372 || $x[0] === 371); });
        $classStartIndex = array_search($classStart, $tokens);    
  
        $tokens = array_slice($tokens, $classStartIndex);
    }
    
    /**
     * @param array $tokens
     * @return void
     */
    private function reSpace(array &$tokens): void
    {
        $list = [ 'as' ];
        
        foreach($tokens as $idx => $token)
        {
            if(is_array($token) && in_array($token[1], $list))
            {
                $tokens[$idx][1] = ' '.$token[1].' ';
            }
        }
    }
    
    /**
     * @param array $tokens
     * @return void
     */
    private function stripInformationAfter(array &$tokens): void
    {
        $list = ['{', '}', '(', ')', ';', '=', '.', ':', ',', '?', '+=', '==', '!==', '===', '/', '[', ']', '-', '*', '&&', '+', '->', 'as', '=>'];
        $remove = [];
        
        foreach($tokens as $idx => $token)
        {
            if(in_array($token, $list) && is_array($tokens[$idx + 1]) && $tokens[$idx + 1][0] === 397)
            {
                $remove[] = $idx + 1;
            }
            
            if(in_array($token, $list) && is_array($tokens[$idx - 1]) && $tokens[$idx - 1][0] === 397)
            {
                $remove[] = $idx - 1;
            }
            
            if(is_array($token) && in_array($token[1], $list) && is_array($tokens[$idx + 1]) && $tokens[$idx + 1][0] === 397)
            {
                $remove[] = $idx + 1;
            }
            
            if(is_array($token) && in_array($token[1], $list) && is_array($tokens[$idx - 1]) && $tokens[$idx - 1][0] === 397)
            {
                $remove[] = $idx - 1;
            }
        }
        
        foreach($remove as $idx)
        {
            unset($tokens[$idx]);
        }
        
        $tokens = array_values($tokens);
    }
    
    /**
     * @param array $tokens
     */
    private function updateMethods(array &$tokens)
    {
        $inMethod = false;
        $inClass = false;
        $methodDepth = 0;
        $inParameters = false;
        $parameters = [];
        $replaced = [];
        
        foreach($tokens as $idx => $token)
        {
            if(!is_array($token))
            {
                if($inMethod && $token === '{')
                {
                    $methodDepth++;
                }
                else if($inMethod && $token === '}')
                {
                    $methodDepth--;
                    if($methodDepth === 0)
                    {
                        $inMethod = false;
                    }
                }
                else if($inMethod && $methodDepth === 0 && $token === '(')
                {
                    $inParameters = true;
                    $parameters = [];
                    $replaced = [];
                }
                else if($inMethod && $inParameters && $token === ')')
                {
                    $inParameters = false;
                }
            }
            else if(!$inClass && $token[0] === 369)
            {
                $inClass = true;
            }
            else if($inClass && !$inMethod && $token[0] === 347)
            {
                $inMethod = true;
            }
            else if($inParameters && $token[0] === 317)
            {
                $parameters[] = $token[1];
            }
            else if($inMethod && $methodDepth >= 1 && $token[0] === 317 && !in_array($token[1], $parameters) && $token[1] !== '$this')
            {
                $var = $token[1];
                if(!isset($replaced[$var]))
                {
                    $replaced[$var] = '$'.$this -> getNewAddress();
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
        
        foreach($tokens as $idx => $token)
        {
            if(!is_array($token))
            {
                continue;
            }
            else if($token[0] == 317)
            {
                while(is_array($tokens[$idx]) && $tokens[$idx][0] !== 313)
                {
                    if($tokens[$idx][0] === 397)
                    {
                        $remove[] = $idx;
                    }
                    $idx++;
                }
                if(is_array($tokens[$idx]) && isset($members[$tokens[$idx][1]]))
                {
                    $tokens[$idx][1] = $members[$tokens[$idx][1]]; 
                }
            }
        }
        
        foreach($remove as $idx)
        {
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
        foreach($tokens as $idx => $token)
        {
            if(!is_array($token))
            {
                if($inMethod && $token === '{')
                {
                    $methodDepth++;
                }
                else if($inMethod && $token === '}')
                {
                    $methodDepth--;
                    if($methodDepth === 0)
                    {
                        $inMethod = false;
                    }
                }
            }
            else if(!$inClass && $token[0] === 369)
            {
                $inClass = true;
            }
            else if($inClass && !$inMethod && $token[0] === 347)
            {
                $inMethod = true;
            }
            else if($inClass && !$inMethod && $token[0] === 360)
            {
                $memberStart = $idx;
                while($tokens[$idx][0] !== 317)
                {
                    $idx++;
                }
                
                $var = $tokens[$idx][1];
                $address = $this -> getNewAddress();
                $tokens[$idx][1] = '$'.$address;
                
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
        $new = 'a'.str_pad(dechex($this -> address), 4, '0', STR_PAD_LEFT);
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
        foreach($tokens as $token)
        {
            if(is_array($token))
            {
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
        $namespaceStart = (new UT_Php_Core\Collections\Linq($tokens)) 
            -> firstOrDefault(function($x) { return is_array($x) && $x[1] === 'namespace'; });
        $namespaceStartIndex = array_search($namespaceStart, $tokens);

        $namespaceEnd = (new UT_Php_Core\Collections\Linq($tokens)) 
            -> skip($namespaceStartIndex)
            -> firstOrDefault(function($x) { return $x === ';'; });
        $namespaceEndIndex = array_search($namespaceEnd, $tokens);
        
        $namespaceTokens = array_slice($tokens, $namespaceStartIndex, $namespaceEndIndex - $namespaceStartIndex);
        $namespaceBuffer = (new UT_Php_Core\Collections\Linq($namespaceTokens)) 
            -> where(function($x) { return is_array($x) && ($x[0] === 313 || $x[0] === 316); })
            -> select(function(array $x) { return $x[1]; })
            -> toArray();

        return implode('\\', $namespaceBuffer);
    }
}

new Compiler();