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
    private int $ReturnlabelCounter = 0;
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

        $lines = explode("\n", $vmcode); // i dont need a co
        $cleaned = $this->cleanLines($lines);

        $translated = [];
        foreach ($cleaned as $line) {
            $line = trim($line);
        
            $handlers = [
                'push'     => 'handlePush',
                'pop'      => 'handlePop',
                'add'      => 'handleArithmeticOperation',
                'sub'      => 'handleArithmeticOperation',
                'neg'      => 'handleArithmeticOperation',
                'not'      => 'handleArithmeticOperation',
                'eq'       => 'handleComparison',
                'gt'       => 'handleComparison',
                'lt'       => 'handleComparison',
                'and'      => 'handleBitwise',
                'or'       => 'handleBitwise',
                'label'    => 'handleLabel',
                'goto'     => 'handleGoto',
                'if-goto'  => 'handleIfGoto',
                'function' => 'handleFunction',
                'call'     => 'handleCall',
                'return'   => 'handleReturn',
            ];
        
            $command = strtok($line, ' ');
        
            if (isset($handlers[$command])) {
                $method = $handlers[$command];
                $translated[] = $this->$method($line);
            } else {
                $translated[] = "// Unknown command: $line";
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
            default => "// Unsupported push segment: $segment"
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
            default => "// Unsupported pop segment: $segment"
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
            default => "// Unsupported bitwise operation: $line"
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
    private function handleLabel(string $line): string
    {
        $label = explode(' ', $line)[1];
        return "({$label})";
    }

    private function handleGoto(string $line): string
    {
        $label = explode(' ', $line)[1];
        return <<<ASM
// goto $label
@{$label}
0;JMP
ASM;
    }

    private function handleIfGoto(string $line): string
    {
        $label = explode(' ', $line)[1];
        return <<<ASM
// if-goto $label
@SP
AM=M-1
D=M
@{$label}
D;JNE
ASM;
    }

    private function handleFunction(string $line): string
    {
        $parts = explode(' ', $line);
        $functionName = $parts[1];
        $nVars = (int)$parts[2];
        $code = "($functionName)\n";
    
        for ($i = 0; $i < $nVars; $i++) {
            $code .= "// initialize local var $i\n";
            $code .= "@0\n";
            $code .= "D=A\n";
            $code .= "@SP\n";
            $code .= "A=M\n";
            $code .= "M=D\n";
            $code .= "@SP\n";
            $code .= "M=M+1\n";
        }
        $this->stackPointer++;
        return $code;
    }
    
    private function handleCall(string $line): string
    {
        $parts = explode(' ', $line);
        $functionName = $parts[1];
        $nArgs = (int)$parts[2];
    
        $returnLabel = "RETURN_" . $this->ReturnlabelCounter++;
    
        return <<<ASM
    // call $functionName $nArgs
    @$returnLabel
    D=A
    @SP
    A=M
    M=D
    @SP
    M=M+1
    @LCL
    D=M
    @SP
    A=M
    M=D
    @SP
    M=M+1
    @ARG
    D=M
    @SP
    A=M
    M=D
    @SP
    M=M+1
    @THIS
    D=M
    @SP
    A=M
    M=D
    @SP
    M=M+1
    @THAT
    D=M
    @SP
    A=M
    M=D
    @SP
    M=M+1
    @SP
    D=M
    @$nArgs
    D=D-A
    @5
    D=D-A
    @ARG
    M=D
    @SP
    D=M
    @LCL
    M=D
    @$functionName
    0;JMP
    ($returnLabel)
    ASM;
    }
    
    private function handleReturn(string $line): string
    {
        return <<<ASM
    // return
    @LCL
    D=M
    @R13
    M=D // FRAME = LCL
    @5
    A=D-A
    D=M
    @R14
    M=D // RET = *(FRAME-5)
    @SP
    AM=M-1
    D=M
    @ARG
    A=M
    M=D // *ARG = pop()
    @ARG
    D=M+1
    @SP
    M=D // SP = ARG + 1
    @R13
    AM=M-1
    D=M
    @THAT
    M=D // THAT = *(FRAME-1)
    @R13
    AM=M-1
    D=M
    @THIS
    M=D // THIS = *(FRAME-2)
    @R13
    AM=M-1
    D=M
    @ARG
    M=D // ARG = *(FRAME-3)
    @R13
    AM=M-1
    D=M
    @LCL
    M=D // LCL = *(FRAME-4)
    @R14
    A=M
    0;JMP // goto RET
    ASM;
    }
    
}
