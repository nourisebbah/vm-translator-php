# Project 8 - Nand2Tetris (VM Translator Part 2)
# This project is the 8th from the Nand2Tetris book and focuses on VM Translator Part 2.
In this project, I extended the functionality of the VM translator by introducing support for labels and goto commands, enabling more complex control flow in the generated Hack Assembly. I also implemented the handling of function calls and returns. This project helped deepen my understanding of low-level programming concepts.

---
```php

First, I updated this:
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
And added: private int $ReturnlabelCounter = 0;

private function handleLabel(string $line): string
    {
        $label = explode(' ', $line)[1];
        return "({$label})";
    }

This method defines a label [a jump marker] in assembly,
and the label name is always the second word in the command.

    private function handleGoto(string $line): string
    {
        $label = explode(' ', $line)[1];
        return <<<ASM
// goto $label
@{$label}
0;JMP
ASM;
    }
This is an unconditional jump,
extract the label and generate assembly that jumps to that label directly using JMP.

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

This Function performs a conditional jump.
It pops the top value from the stack and jumps to the label only if that value is not zero using JNE.
@SP + AM=M-1 decreases the stack pointer and accesses the top value.
D=M stores that value in D.
@label + D;JNE performs the jump if D is not zero.

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
    
        return $code;
    }

$functionName is the function label, and $nVars is the number of local variables.
For each variable,  push 0 onto the stack and increment SP,
effectively initializing local variables to 0.

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

I extracted the function being called and its argument count.
Then, I generated a unique return label to jump back to after the function finishes executing.
In assembly:
I stored the return label address in the D register.
incremented the stack pointer.
After that, I saved the current values of LCL, ARG, THIS, and THAT to the stack in the same way, each time incrementing the stack pointer.
Next:
I subtracted the number of arguments from the current value of SP, then subtracted 5 more.
[this is cuz when we call a function, the ARG pointer must point 5 positions below the current top of the stack, That’s due to the five values we just pushed (return address, LCL, ARG, THIS, THAT)].
I then set ARG to this new computed value and set LCL to the current value of SP.
Finally, I jumped to the function’s code using JMP.
The return label marks where the execution should resume after the function completes.


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
    
I used R13 again as a temporary register , saved the current LCL value in it.
Then, I retrieved the return address and stored it in R14, popped the top of the stack [the function result] and stored it in ARG.
After that, I needed to reset the stack pointer to just above it.
Finally, I restored the segment pointers (THAT, THIS, ARG, LCL) from the stack, and jumped to the return address stored in R14.


