<?php

namespace App\Services\VmTranslator;

class TranslatorService
{
    protected int $stackPointer = 256;
    protected int $LCL = 0;
    protected int $ARG = 0;
    protected int $THIS = 0;
    protected int $THAT = 0;

    protected array $segmentBase = [
        'local' => 'LCL',
        'argument' => 'ARG',
        'this' => 'THIS',
        'that' => 'THAT',
        'temp' => 5,
    ];

    private int $labelCounter = 0;

    private function initializePointers()
    {
        $this->LCL = $this->stackPointer;
        $this->ARG = $this->stackPointer + 1;
        $this->THIS = $this->stackPointer + 2;
        $this->THAT = $this->stackPointer + 3;
    }

    public function translate(string $vmcode): string
    {
        $this->initializePointers();

        $lines = explode("\n", $vmcode);
        $cleaned = $this->cleanLines($lines);

        $translated = [];
        foreach ($cleaned as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'push')) {
                $translated[] = $this->handlePush($line);
            } elseif (str_starts_with($line, 'pop')) {
                $translated[] = $this->handlePop($line);
            } elseif (in_array($line, ['add', 'sub', 'neg', 'not'])) {
                $translated[] = $this->handleArithmeticOperation($line);
            } elseif (in_array($line, ['eq', 'gt', 'lt'])) {
                $translated[] = $this->handleComparison($line);
            } elseif (in_array($line, ['and', 'or'])) {
                $translated[] = $this->handleBitwise($line);
            } else {
                $translated[] = "Unknown command: $line";
            }
        }

        return implode("\n", $translated);
    }

    private function cleanLines(array $lines): array
    {
        return array_values(array_filter(array_map(function ($line) {
            $line = trim($line);
            return ($line === '' || str_starts_with($line, '//')) ? null : explode('//', $line)[0];
        }, $lines)));
    }

    private function handlePush(string $line): string
    {
        [$command, $segment, $value] = explode(' ', $line);
        $asmCode = match ($segment) {
            'constant' => $this->pushConstant($value, $line),
            'static' => $this->pushStatic($value, $line),
            'local', 'argument', 'this', 'that' => $this->pushLocal($segment, $value, $line),
            'temp' => $this->pushTemp($value, $line),
            default => "Unsupported push segment: $segment"
        };
        $this->stackPointer++;
        return $asmCode;
    }

    private function handlePop(string $line): string
    {
        [$command, $segment, $value] = explode(' ', $line);
        $asmCode = match ($segment) {
            'static' => $this->popStatic($value, $line),
            'local', 'argument', 'this', 'that' => $this->popLocal($segment, $value, $line),
            'temp' => $this->popTemp($value, $line),
            default => "Unsupported pop segment: $segment"
        };
        $this->stackPointer--;
        return $asmCode;
    }

    private function pushConstant(string $value, string $line): string
    {
        return <<<ASM
// $line
@{$value}
D=A
@SP
A=M
M=D
@SP
M=M+1
ASM;
    }

    private function pushStatic(string $value, string $line): string
    {
        return <<<ASM
// $line
@Foo.{$value}
D=M
@SP
A=M
M=D
@SP
M=M+1
ASM;
    }

    private function pushLocal(string $segment, string $value, string $line): string
    {
        $base = $this->segmentBase[$segment];
        return <<<ASM
// $line
@{$value}
D=A
@{$base}
A=M+D
D=M
@SP
A=M
M=D
@SP
M=M+1
ASM;
    }

    private function pushTemp(string $value, string $line): string
    {
        $address = 5 + (int)$value;
        return <<<ASM
// $line
@{$address}
D=M
@SP
A=M
M=D
@SP
M=M+1
ASM;
    }

    private function popStatic(string $value, string $line): string
    {
        return <<<ASM
// $line
@SP
AM=M-1
D=M
@Foo.{$value}
M=D
ASM;
    }

    private function popLocal(string $segment, string $value, string $line): string
    {
        $base = $this->segmentBase[$segment];
        return <<<ASM
// $line
@{$value}
D=A
@{$base}
D=M+D
@R13
M=D
@SP
AM=M-1
D=M
@R13
A=M
M=D
ASM;
    }

    private function popTemp(string $value, string $line): string
    {
        $address = 5 + (int)$value;
        return <<<ASM
// $line
@SP
AM=M-1
D=M
@{$address}
M=D
ASM;
    }

    private function handleArithmeticOperation(string $line): string
    {
         //match expression 
    return match ($line) {
        'add', 'sub' => $this->handleAddSub($line),
        'neg', 'not' => $this->handleNotNeg($line),
        default => "Unsupported arithmetic operation: $line"
    };
    }
    private function handleAddSub(string $operation): string
    {
       
        $operationCode = $operation === 'add' ? 'M+D' : 'M-D'; // select the operation based on 'add' or 'sub'
    
        return <<<ASM
    // $operation
    @SP
    AM=M-1
    D=M
    A=A-1
    M=$operationCode
    ASM;
    }
    
    private function handleNotNeg(string $operation): string
    {
        $asmCode = $operation === 'neg' ? 'M=-M' : 'M=!M'; // choose between negation and bitwise NOT
        
        return <<<ASM
    // $operation
    @SP
    A=M-1
    $asmCode
    ASM;
    }
    private function handleComparison(string $line): string
    {
        $labelId = $this->labelCounter++;
        $trueLabel = strtoupper($line) . "_TRUE_{$labelId}";
        $endLabel = strtoupper($line) . "_END_{$labelId}";

        $jump = match ($line) {
            'eq' => 'JEQ',
            'gt' => 'JGT',
            'lt' => 'JLT',
            default => null
        };

        if (!$jump) return "Unsupported comparison operation: $line";

        return <<<ASM
// $line
@SP
AM=M-1
D=M
A=A-1
D=M-D
@{$trueLabel}
D;{$jump}
@SP
A=M-1
M=0
@{$endLabel}
0;JMP
({$trueLabel})
@SP
A=M-1
M=-1
({$endLabel})
ASM;
    }

    private function handleBitwise(string $line): string
    {
        return match ($line) {
            'and', 'or' => $this->handleAndOr($line),
            default => "Unsupported bitwise operation: $line"
        };
    }
    
    private function handleAndOr(string $operation): string
    {
        $operationCode = $operation === 'and' ? 'M&D' : 'M|D'; // select between 'and' and 'or'
    
        return <<<ASM
    // $operation
    @SP
    AM=M-1
    D=M
    A=A-1
    M=$operationCode
    ASM;
    }
    
}
