import { describe, it, expect } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import rehypeRaw from "rehype-raw";
import DOMPurify from "dompurify";
import {
  allowLinkOutsideHeading,
  demotedHeadingComponents,
} from "../constants/markdownComponents";
import { XSSAllowTags, XSSAllowAttr } from "../constants/xssAllowTags";
import { normalizeCitationMarkdown } from "./normalizeCitationMarkdown";

// normalizeCitationMarkdown runs on the *output* of DOMPurify.sanitize and its
// result is handed to a markdown parser with rehype-raw, which re-parses raw
// HTML. Removing leading whitespace therefore moves text from an inert
// indented-code-block position into a live one, so the property worth pinning
// is: de-indenting must never activate something the sanitizer rejected.
//
// Indentation is not a security control -- an unindented copy of any payload
// below was always live. The sanitizer is the control, and these cases assert
// it holds either side of the transform.
const render = (content: string) =>
  renderToStaticMarkup(
    <ReactMarkdown
      linkTarget="_blank"
      children={content}
      remarkPlugins={[remarkGfm]}
      rehypePlugins={[rehypeRaw]}
      components={demotedHeadingComponents}
      allowElement={allowLinkOutsideHeading}
      unwrapDisallowed
    />
  );

const sanitize = (content: string) =>
  DOMPurify.sanitize(content, {
    ALLOWED_TAGS: XSSAllowTags,
    ALLOWED_ATTR: XSSAllowAttr,
  });

// Every payload is indented 4+ spaces, so without normalization it parses as
// an inert indented code block -- the exact condition normalization removes.
const payloads: Record<string, string> = {
  script: "    <script>alert(1)</script>\n",
  imgOnerror: "    <img src=x onerror=alert(1)>\n",
  iframe: "    <iframe src=https://evil.example></iframe>\n",
  svgOnload: "    <svg onload=alert(1)></svg>\n",
  jsHref: '    <a href="javascript:alert(1)">click</a>\n',
  jsMarkdownLink: "    [click](javascript:alert(1))\n",
  objectTag: "    <object data=evil.swf></object>\n",
  formAction: "    <form action=https://evil.example><input name=a></form>\n",
  entityEscaped: "    &lt;script&gt;alert(1)&lt;/script&gt;\n",
  styleJsUrl: '    <div style="background:url(javascript:alert(1))">x</div>\n',
  styleExpression: '    <div style="width:expression(alert(1))">x</div>\n',
  imgStyle: '    <img src=x style="background:url(javascript:alert(1))">\n',
  headingLinkJs: '    <h2><a href="javascript:alert(1)">Title</a></h2>\n',
  base: "    <base href=https://evil.example>\n",
  metaRefresh: "    <meta http-equiv=refresh content=0>\n",
};

// Inspect the parsed DOM rather than substring-matching the HTML: a payload
// rendered as inert text inside a code block contains all the same substrings
// as a live one, and react-markdown rewrites an unsafe URL to the inert
// placeholder "javascript:void(0)", which a substring match would misreport.
const dangersIn = (html: string): string[] => {
  const host = document.createElement("div");
  host.innerHTML = html;
  const found: string[] = [];

  for (const tag of ["script", "iframe", "object", "embed", "form", "base", "meta", "svg"]) {
    if (host.querySelector(tag)) {
      found.push(`<${tag}>`);
    }
  }

  for (const element of Array.from(host.querySelectorAll("*"))) {
    for (const attr of Array.from(element.attributes)) {
      // No event handlers, and no inline CSS at all -- inline styles carried
      // over from the indexed page are stripped outright, so any `style`
      // reaching the DOM is a regression regardless of its value.
      if (/^on/i.test(attr.name) || attr.name.toLowerCase() === "style") {
        found.push(`${element.tagName}[${attr.name}]`);
      }
      if (
        /^(href|src|xlink:href|action|data)$/i.test(attr.name) &&
        /^\s*(javascript|data|vbscript):/i.test(attr.value) &&
        attr.value.trim().toLowerCase() !== "javascript:void(0)"
      ) {
        found.push(`${element.tagName}[${attr.name}=${attr.value}]`);
      }
    }
  }

  return found;
};

describe("normalizeCitationMarkdown does not weaken sanitization", () => {
  it.each(Object.entries(payloads))(
    "keeps %s inert after de-indenting it",
    (_name, payload) => {
      const sanitized = sanitize(payload);

      expect(dangersIn(render(normalizeCitationMarkdown(sanitized)))).toEqual(
        []
      );
    }
  );

  // Guards against the allowlist being either vacuous or over-broad: the
  // style must be gone, and the href/src/alt that make raw-HTML links and
  // images useful must survive.
  it("strips inline style while keeping href, src and alt", () => {
    const sanitized = sanitize(
      '<a href="https://example.com" style="color:red">link</a>' +
        '<img src="https://example.com/a.png" alt="alt text" style="width:9px">'
    );

    expect(sanitized).not.toContain("style");
    expect(sanitized).toContain('href="https://example.com"');
    expect(sanitized).toContain('src="https://example.com/a.png"');
    expect(sanitized).toContain('alt="alt text"');
  });

  it("terminates promptly on adversarial indentation", () => {
    const hostile =
      " ".repeat(200000) +
      "x\n" +
      "\t".repeat(200000) +
      "y\n" +
      "`".repeat(50000) +
      "\n";

    const start = Date.now();
    normalizeCitationMarkdown(hostile);

    expect(Date.now() - start).toBeLessThan(2000);
  });
});
