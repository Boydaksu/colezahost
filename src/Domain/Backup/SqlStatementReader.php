<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup;

/** Bounded SQL lexical boundaries. Only trusted logical backup SQL is accepted by the restore workflow. */
final class SqlStatementReader
{
    public static function read(string $sql,bool $backslashEscapes=false): \Generator
    {
        $input=fopen('php://temp/maxmemory:1048576','w+b');fwrite($input,$sql);rewind($input);
        try{yield from self::stream($input,$backslashEscapes);}finally{fclose($input);}
    }
    public static function file(string $path,bool $backslashEscapes=false): \Generator
    {
        $input=fopen($path,'rb');if($input===false){throw new \RuntimeException('Cannot open backup SQL dump.');}
        try{yield from self::stream($input,$backslashEscapes);}finally{fclose($input);}
    }
    /** @param resource $input */
    private static function stream(mixed $input,bool $backslashEscapes): \Generator
    {
        $buffer='';$offset=0;$current='';$quote=null;$comment=null;
        while(true){
            if(strlen($buffer)-$offset<3&&!feof($input)){$chunk=fread($input,65536);if($chunk===false){throw new \RuntimeException('Cannot read backup SQL.');}$buffer=substr($buffer,$offset).$chunk;$offset=0;}
            if($offset>=strlen($buffer)){break;}$c=$buffer[$offset++];$next=$buffer[$offset]??'';$after=$buffer[$offset+1]??'';
            if($comment==='line'){if($c==="\n"){$comment=null;$current.="\n";}continue;}
            if($comment==='block'){if($c==='*'&&$next==='/'){$comment=null;$offset++;$current.=' ';}continue;}
            if($quote!==null){$current.=$c;if($backslashEscapes&&$c==='\\'&&$next!==''){$current.=$next;$offset++;}
                elseif($c===$quote){if($next===$quote){$current.=$next;$offset++;}else{$quote=null;}}
            }elseif($c==='-'&&$next==='-'&&($after===''||ctype_space($after))){$comment='line';$offset++;}
            elseif($c==='#'){$comment='line';}
            elseif($c==='/'&&$next==='*'){if($after==='!'||$after==='M'){throw new \RuntimeException('Executable SQL comments are unsupported.');}$comment='block';$offset++;}
            elseif($c==="'"||$c==='"'||$c==='`'){$quote=$c;$current.=$c;}
            elseif($c===';'){if(trim($current)!==''){yield trim($current);}$current='';}
            else{$current.=$c;}
            if(strlen($current)>64*1024*1024){throw new \RuntimeException('Backup SQL statement exceeds the 64 MiB safety limit.');}
        }
        if($quote!==null||$comment==='block'){throw new \RuntimeException('Unterminated backup SQL quote or comment.');}
        if(trim($current)!==''){throw new \RuntimeException('Backup SQL statement is missing its terminator.');}
    }
}
