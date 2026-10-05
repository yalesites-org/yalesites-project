// CommonMark reads a block-start line indented 4+ columns as an indented code
// block, so that is the threshold to stay under.
const MAX_BLOCK_START_INDENT = 3;

// A tab advances to the next 4-column stop, so indent width has to be measured
// in columns, not characters: "\t" is one character but four columns, and
// slicing it by character count would leave the line still parsing as code.
const TAB_STOP = 4;

const LEADING_WHITESPACE = /^[ \t]+/;

// Deliberately indent-agnostic. A fence indented 4+ is not a valid opener in
// the input, but capping could turn it into one, so it has to be recognised
// before any capping decision is made.
const FENCE = /^[ \t]*(`{3,}|~{3,})(.*)$/;

// A setext underline: the line that promotes the text above it to a heading.
const SETEXT_UNDERLINE = /^ {0,3}(?:-+|=+)[ \t]*$/;

const indentColumns = (indent: string): number => {
  let column = 0;
  for (const character of indent) {
    column += character === "\t" ? TAB_STOP - (column % TAB_STOP) : 1;
  }
  return column;
};

interface OpenFence {
  marker: string;
  length: number;
}

// Returns the fence this line opens, or null if it is not a fence opener.
const fenceOpenedBy = (line: string): OpenFence | null => {
  const match = FENCE.exec(line);
  return match ? { marker: match[1][0], length: match[1].length } : null;
};

// CommonMark closes a fence only on a run of the *same* marker at least as
// long as the opener, with nothing but whitespace after it. A shorter run, or
// a different marker, is content inside the fence.
const closesFence = (line: string, open: OpenFence): boolean => {
  const match = FENCE.exec(line);
  return (
    match !== null &&
    match[1][0] === open.marker &&
    match[1].length >= open.length &&
    match[2].trim() === ""
  );
};

/**
 * Repairs headings that stray indentation turned into code blocks.
 *
 * Citation source content is produced by converting a rendered page's HTML into
 * markdown, so it can carry leading whitespace that meant nothing in the
 * original page (template formatting, converted `&nbsp;` spacers). A browser
 * collapses that whitespace; CommonMark does not. A block-start line indented
 * 4+ columns parses as an indented code block, which renders as a monospace box
 * and stops the `-----` underline beneath it from promoting it to a setext
 * heading -- the underline degrades to an `<hr>` instead. That is the reported
 * bug: a section heading rendering as a bordered box trailed by a stray rule.
 *
 * Scope is deliberately narrow. A line is de-indented only when all of:
 *   - it starts a block (very start of the content, or after a blank line),
 *   - the line directly beneath it is a setext underline, and
 *   - it is not inside a fenced code block.
 *
 * Requiring the setext underline is what makes this safe. "Indented 4+ columns"
 * on its own is ambiguous -- it is equally the signature of a deliberate
 * indented code block, and of a loose list's continuation paragraph, which sits
 * after a blank line and so also looks like a block start. De-indenting either
 * of those corrupts valid markdown: the code block collapses into a paragraph,
 * and the list continuation is ejected from its item. An underline directly
 * beneath the line is unambiguous evidence the author meant a heading, so it
 * distinguishes the broken case from both.
 *
 * The tradeoff is that stray indentation which does *not* form a setext heading
 * is left alone, and still renders as a code box. Fixing that class needs the
 * HTML, where the whitespace is knowable as insignificant, rather than guessing
 * from the converted markdown -- see IndexableHtmlFilter on the PHP side.
 */
export const normalizeCitationMarkdown = (content: string): string => {
  const lines = content.split("\n");
  let openFence: OpenFence | null = null;
  let previousLineBlank = true;

  return lines
    .map((line, index) => {
      const isBlockStart = previousLineBlank && openFence === null;
      previousLineBlank = line.trim() === "";

      if (openFence !== null) {
        if (closesFence(line, openFence)) {
          openFence = null;
        }
        return line;
      }

      const opened = fenceOpenedBy(line);
      if (opened !== null) {
        openFence = opened;
        return line;
      }

      if (!isBlockStart || !SETEXT_UNDERLINE.test(lines[index + 1] ?? "")) {
        return line;
      }

      return line.replace(LEADING_WHITESPACE, (indent) =>
        indentColumns(indent) > MAX_BLOCK_START_INDENT
          ? " ".repeat(MAX_BLOCK_START_INDENT)
          : indent
      );
    })
    .join("\n");
};
