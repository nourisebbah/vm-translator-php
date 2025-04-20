# This project is the 7th project from the Nand2Tetris book. 

While I plan to dive deeper into C++ after completing Nand2Tetris, I’m not yet fully comfortable with it, so for this project, I used PHP and Laravel to build an API. You can send VM code as a request to the API, and it will return the corresponding Hack Assembly in the response. I focused on performance by using a lot of native PHP throughout the project.

---
```php
protected int $stackPointer = 256;
protected int $LCL = 0;
protected int $ARG = 0;
protected int $THIS = 0;
protected int $THAT = 0;

These variables represent pointers for different memory segments and the stack pointer.

    protected array $segmentBase = [
        'local' => 'LCL',
        'argument' => 'ARG',
        'this' => 'THIS',
        'that' => 'THAT',
        'temp' => 5,
    ];
This array maps segment names like local, argument, this, that, and temp to their corresponding registers or base addresses.
The temp segment is a special case because it’s a range of addresses starting from 5. In Nand2Tetris, the temp segment spans addresses 5–12.

private function initializePointers()
{
    $this->LCL = $this->stackPointer; 
    $this->ARG = $this->stackPointer + 1; 
    $this->THIS = $this->stackPointer + 2; 
    $this->THAT = $this->stackPointer + 3;
}
This function initializes the segment pointers (LCL, ARG, THIS, THAT) relative to the stack pointer.

  public function translate(string $vmcode): string
    {
         // initialize segment pointers and stack pointer
        $this->initializePointers();
        
        $lines = explode("\n", $vmcode);
        $cleaned = $this->cleanLines($lines);
        
        $translated = [];
        foreach ($cleaned as $index => $line) {
            if (str_starts_with($line, 'push')) {
                $translated[] = $this->handlePush($line);
            } elseif (str_starts_with($line, 'pop')) {
                $translated[] = $this->handlePop($line);
            } else {
                $translated[] = " Unknown command: $line";
            }
        }
        return implode("\n", $translated);
    }
   private function cleanLines(array $lines): array { 
        return array_values(array_filter(array_map(function ($line) {
            $line = trim($line);
            return ($line === '' || str_starts_with($line, '//')) ? null : explode('//', $line)[0];
        }, $lines)));
    }

Cleans the lines by removing empty lines and comments.

 private function handlePush(string $line): string
    {
        [$command, $segment, $value] = explode(' ', $line);

        $asmCode = match ($segment) {
            'constant' => $this->pushConstant($value, $line),
            'static'   => $this->pushStatic($value, $line),
            'local', 'argument', 'this', 'that' => $this->pushLocal($segment, $value, $line),
            'temp'     => $this->pushTemp($value, $line),
            default    => "Unsupported push segment: $segment" 
        }
        $this->stackPointer++;
        return $asmCode;
    }
Processes a push command by extracting the segment and value, then calling the correct method.
 private function handlePop(string $line): string
    {
        [$command, $segment, $value] = explode(' ', $line);

        // pop logic based on segment type
        $asmCode = match ($segment) {
            'static'   => $this->popStatic($value, $line),
            'local', 'argument', 'this', 'that' => $this->popLocal($segment, $value, $line),
            'temp'     => $this->popTemp($value, $line),
            default    => "// Unsupported pop segment: $segment"
        };

        // decrement stack pointer after pop
        $this->stackPointer--;

        return $asmCode;
    }
Handles the pop command similarly to the push

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
This can handle something like : push constant 2
Load the constant into D ,access the stack pointer SP, and move to the memory location it points to,
then Store D at that address and Increment SP.

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
This part looks almost the same, but there's a tiny difference with a huge impact:
D=A vs D=M
To understand the difference, you need to know:
    A is the address register.
    M is the value stored at the address currently held in A.
So:
    D=A means: Put the address itself into D.
    D=M means: Put the value at that address into D.

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
Load it into D , add it to the segment base pointer then Get the value at that final address
,push it to the stack and increment SP.
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

What I did here is:
First, I calculated the real address by adding 5 to the index, since the temp segment RAM[5] , RAM[12].
Then, I loaded the value into D, pushed it onto the stack, and finally incremented SP.

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
decrement SP, put the top of the stack into D and store D into the static variable.

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
Since i can’t directly do something like @LCL + 3 or A = M + 3 in Hack assembly, I used R13 as a temporary storage.
Calculate the target address and Store it in R13, Pop from the stack into D.
    Store D at the address saved in R13


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
Calculate the real address.
Pop the stack into D.
Store D at the address.

What I have left at this point are the arithmetic commands, so I added :

} elseif (in_array($line, ['add', 'sub', 'neg', 'not'])) {
                $translated[] = $this->handleArithmeticOperation($line);
            } elseif (in_array($line, ['eq', 'gt', 'lt'])) {
                $translated[] = $this->handleComparison($line);
            } elseif (in_array($line, ['and', 'or'])) {
                $translated[] = $this->handleBitwise($line);
            } 

and then : 

 private function handleArithmeticOperation(string $line): string
    {
    return match ($line) {
        'add', 'sub' => $this->handleAddSub($line),
        'neg', 'not' => $this->handleNotNeg($line),
        default => "Unsupported arithmetic operation: $line"
    };
    }
  private function handleAddSub(string $operation): string
    {
        $operationCode = $operation === 'add' ? 'M+D' : 'M-D'; 
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
        $asmCode = $operation === 'neg' ? 'M=-M' : 'M=!M'; 
        return <<<ASM
    // $operation
    @SP
    A=M-1
    $asmCode
    ASM;
    }

These are straightforward:
    The only difference between add and sub is the operation (M+D vs M-D).
    Same thing for neg vs not (-M vs !M).


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

From trial and error, I figured out that I need a way to control the flow of the program using unique labels for this step.
 So, I used a label counter and generated two unique labels: trueLabel and endLabel. The function strtoupper($line)
 here only converts the comparison operator to uppercase for the label name.

Next, since I know that JEQ is for when out == 0, JGT is for when out > 0, and JLT is for when out < 0, I
 matched that with the appropriate comparison. Then, I handled the unsupported comparisons as a safety net.

For the assembly part, it's a multi-step process:
    AM=M-1 decrements the stack pointer.
    D=M stores the value at the top of the stack in D.
    A=A-1 moves the A register to the previous memory address.
    D=M-D subtracts the second value (now in M) from the first value in D, and the result is stored in D.
Now, using the generated label @{$trueLabel}, the program jumps to the true label if the comparison is true
, and it accesses the stack again: A=M-1 moves the A register to the correct memory location.
 Then M=-1 sets the value at the top of the stack to -1, indicating true.

If the comparison is false, the program continues and the stack pointer is accessed again: A=M-1 decrements the stack pointer,
 and M=0 sets the value at the top of the stack to 0. Finally, @{$endLabel} performs an unconditional jump to the end of the comparison code.

private function handleBitwise(string $line): string
    {
        return match ($line) {
            'and', 'or' => $this->handleAndOr($line),
            default => "// Unsupported bitwise operation: $line"
        };
    }
    
    private function handleAndOr(string $operation): string
    {
        $operationCode = $operation === 'and' ? 'M&D' : 'M|D'; 
    
        return <<<ASM
    // $operation
    @SP
    AM=M-1
    D=M
    A=A-1
    M=$operationCode
    ASM;
    }
And and Or aren't so different in terms of their assembly,
so I just used a ternary operator to check and change that line accordingly.


