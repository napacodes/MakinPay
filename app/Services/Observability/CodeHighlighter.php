<?php

namespace App\Services\Observability;

/**
 * Turns a stored source excerpt into the coloured lines the detail screen
 * paints inside its editor pane.
 *
 * Server-side on purpose. The alternative — shipping a highlighter to the
 * browser and letting it walk the DOM after paint — costs a library, a second
 * parse of code we have already parsed, and a visible repaint on a screen
 * somebody opened because something is broken. Here the work happens once, on
 * a fragment that is a couple of dozen lines at most.
 *
 * Escaping is this class's whole safety story: every character of the excerpt
 * leaves through {@see e()} and the only unescaped bytes in the result are the
 * span tags this file writes itself. Nothing here may ever interpolate token
 * text into markup without that call.
 */
class CodeHighlighter
{
    /**
     * The excerpt is a window around one line, not a file. Anything past this
     * is a capture that went wrong, and painting it would trade a readable
     * screen for a scrollbar nobody asked for.
     */
    public const MAX_LINES = 80;

    /**
     * A single line longer than this is minified output or a serialised blob.
     * Laying it out costs the browser a reflow per frame while the reader
     * scrolls sideways past content that carries nothing.
     */
    private const MAX_LINE_LENGTH = 400;

    /**
     * Token ids painted as keywords.
     *
     * Listed rather than derived: `token_name()` prefixes are not a reliable
     * guide — T_STRING is an identifier and T_ARRAY is a keyword — and a wrong
     * guess here is a miscoloured word on every screen.
     *
     * @var list<int>
     */
    private const KEYWORDS = [
        T_ABSTRACT, T_ARRAY, T_AS, T_BREAK, T_CALLABLE, T_CASE, T_CATCH,
        T_CLASS, T_CLONE, T_CONST, T_CONTINUE, T_DECLARE, T_DEFAULT, T_DO,
        T_ECHO, T_ELSE, T_ELSEIF, T_EMPTY, T_ENDDECLARE, T_ENDFOR,
        T_ENDFOREACH, T_ENDIF, T_ENDSWITCH, T_ENDWHILE, T_ENUM, T_EXIT,
        T_EXTENDS, T_FINAL, T_FINALLY, T_FN, T_FOR, T_FOREACH, T_FUNCTION,
        T_GLOBAL, T_GOTO, T_IF, T_IMPLEMENTS, T_INCLUDE, T_INCLUDE_ONCE,
        T_INSTANCEOF, T_INSTEADOF, T_INTERFACE, T_ISSET, T_LIST,
        T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_MATCH, T_NAMESPACE,
        T_NEW, T_PRINT, T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY,
        T_REQUIRE, T_REQUIRE_ONCE, T_RETURN, T_STATIC, T_SWITCH, T_THROW,
        T_TRAIT, T_TRY, T_UNSET, T_USE, T_VAR, T_WHILE, T_YIELD,
    ];

    /**
     * The excerpt, line by line, ready to print.
     *
     * @param  array<int, array<string, mixed>>                                                                                      $lines
     * @return array{lines: array<int, array{number: int|string, offending: bool, html: string}>, truncated: int, highlighted: bool}
     */
    public function excerpt(array $lines, ?string $file = null): array
    {
        $lines = array_values($lines);
        $total = count($lines);
        $kept  = array_slice($lines, 0, self::MAX_LINES);

        $code = array_map(
            fn (array $line): string => $this->clipLine((string) ($line['code'] ?? '')),
            $kept
        );

        $painted = $this->supportsPhp($file) ? $this->paint($code) : null;

        $out = [];

        foreach ($kept as $index => $line) {
            $out[] = [
                'number'    => $line['number'] ?? ($index + 1),
                'offending' => (bool) ($line['offending'] ?? false),
                'html'      => $painted[$index] ?? e($code[$index]),
            ];
        }

        return [
            'lines'       => $out,
            'truncated'   => max(0, $total - count($kept)),
            'highlighted' => $painted !== null,
        ];
    }

    /**
     * Any other block of text the screen shows in the editor pane — the debug
     * report preview, a request body — as escaped lines under the same budget.
     *
     * @return array{lines: array<int, array{number: int, offending: bool, html: string}>, truncated: int, highlighted: bool}
     */
    public function plain(string $text, int $maxLines = self::MAX_LINES): array
    {
        $all   = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $kept  = array_slice($all, 0, $maxLines);
        $lines = [];

        foreach ($kept as $index => $line) {
            $lines[] = [
                'number'    => $index + 1,
                'offending' => false,
                'html'      => e($this->clipLine($line)),
            ];
        }

        return [
            'lines'       => $lines,
            'truncated'   => max(0, count($all) - count($kept)),
            'highlighted' => false,
        ];
    }

    /**
     * Colour a PHP fragment, or give up.
     *
     * Giving up matters more than colouring. The excerpt is a slice out of the
     * middle of a file, so its quotes and braces need not balance; the
     * tokeniser then reads the remainder as one long string and what comes
     * back stops matching what went in. Rather than paint that, the caller
     * falls back to the plain escaped source — the same code, without colour.
     *
     * @param  array<int, string>      $code
     * @return array<int, string>|null
     */
    private function paint(array $code): ?array
    {
        $source = implode("\n", $code);

        if (trim($source) === '') {
            return null;
        }

        /*
         * An unbalanced fragment makes the tokeniser warn. That is the
         * expected case here rather than a fault to report, and the
         * round-trip check below is what actually decides whether the result
         * can be used.
         */
        $tokens = @token_get_all("<?php\n".$source);

        if ($tokens === []) {
            return null;
        }

        $html  = '';
        $plain = '';

        foreach ($tokens as $index => $token) {
            if (is_string($token)) {
                $html  .= e($token);
                $plain .= $token;

                continue;
            }

            [$id, $text] = $token;

            if ($index === 0 && $id === T_OPEN_TAG) {
                continue;
            }

            $class = $this->classFor($id, $text, $tokens, $index);
            $plain .= $text;
            $html  .= $class === null ? e($text) : $this->wrap($class, $text);
        }

        if ($plain !== $source) {
            return null;
        }

        $painted = explode("\n", $html);

        return count($painted) === count($code) ? $painted : null;
    }

    /**
     * One span per line of the token, never one span across lines.
     *
     * A doc block or a heredoc is a single token spanning several lines, and
     * the pane splits the finished markup on newlines to lay the gutter out.
     * Wrapping such a token once puts the opening tag on one line and the
     * closing tag on another, which the browser then repairs by guessing —
     * usually by colouring the rest of the excerpt as a comment.
     */
    private function wrap(string $class, string $text): string
    {
        $open = '<span class="tok-'.$class.'">';

        return $open.implode('</span>'."\n".$open, array_map(
            static fn (string $segment): string => e($segment),
            explode("\n", $text)
        )).'</span>';
    }

    /**
     * @param array<int, array{0: int, 1: string}|string> $tokens
     */
    private function classFor(int $id, string $text, array $tokens, int $index): ?string
    {
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            return 'comment';
        }

        if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC], true)) {
            return 'string';
        }

        if ($id === T_VARIABLE) {
            return 'variable';
        }

        if ($id === T_LNUMBER || $id === T_DNUMBER) {
            return 'number';
        }

        if (in_array($id, self::KEYWORDS, true)) {
            return 'keyword';
        }

        if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_RELATIVE) {
            return 'type';
        }

        if ($id === T_STRING) {
            /*
             * true, false and null are T_STRING to the tokeniser but read as
             * literals to anybody looking at the screen.
             */
            if (in_array(mb_strtolower($text), ['true', 'false', 'null'], true)) {
                return 'atom';
            }

            return $this->isCall($tokens, $index) ? 'function' : 'type';
        }

        return null;
    }

    /**
     * Whether the identifier at this position is being called.
     *
     * @param array<int, array{0: int, 1: string}|string> $tokens
     */
    private function isCall(array $tokens, int $index): bool
    {
        for ($cursor = $index + 1, $last = count($tokens); $cursor < $last; $cursor++) {
            $next = $tokens[$cursor];

            if (is_array($next) && $next[0] === T_WHITESPACE) {
                continue;
            }

            return $next === '(';
        }

        return false;
    }

    /**
     * Whether the excerpt is worth handing to the PHP tokeniser at all.
     *
     * A missing filename is treated as PHP: the excerpt comes from a stack
     * frame, and this platform's frames are PHP unless the path says
     * otherwise.
     */
    private function supportsPhp(?string $file): bool
    {
        if ($file === null || $file === '') {
            return true;
        }

        $name = mb_strtolower($file);

        if (str_ends_with($name, '.blade.php')) {
            return true;
        }

        return in_array(pathinfo($name, PATHINFO_EXTENSION), ['', 'php', 'inc'], true);
    }

    private function clipLine(string $line): string
    {
        /*
         * A stored line carries no newline of its own. If one is there anyway
         * the pane's line count and the gutter would silently disagree, so it
         * is flattened here rather than discovered downstream.
         */
        $line = str_replace(["\r", "\n", "\t"], ['', ' ', '    '], $line);

        if (mb_strlen($line) <= self::MAX_LINE_LENGTH) {
            return $line;
        }

        return mb_substr($line, 0, self::MAX_LINE_LENGTH).' … ['.mb_strlen($line).' chars]';
    }
}
